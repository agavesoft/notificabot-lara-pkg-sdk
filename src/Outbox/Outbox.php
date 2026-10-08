<?php

namespace Agavesoft\Smartmailto\Outbox;

use Agavesoft\Smartmailto\Exceptions\OutboxRowInFlight;
use Agavesoft\Smartmailto\Identity;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * F-010 (J1, J3): tabla local de llamadas pendientes de acuse.
 *
 * La fila se escribe en la conexion de tu app (`smartmailto.outbox.connection`), dentro de la transaccion
 * activa: si la accion de negocio se revierte, no queda fila ni evento. Un worker la entrega y la cierra
 * solo con el acuse de Smartmailto. El payload va cifrado con APP_KEY.
 *
 * Estados: pending → sending → acked; un rechazo definitivo → failed; un `send` que llega a su
 * `send_before` sin acuse → expired; el envio de emergencia de tu app pasa la fila a superseded
 * (con la fila tomada, nunca desde `sending`).
 */
class Outbox
{
    public const KINDS = [
        'track' => 'track',
        'send' => 'send',
        'identify' => 'identify',
        'link' => 'contacts/link',
        'external_report' => 'send/external',
    ];

    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const ACKED = 'acked';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const SUPERSEDED = 'superseded';

    public function __construct(private readonly Container $app) {}

    public function enabled(): bool
    {
        return (bool) $this->config('enabled', false);
    }

    public function kindFor(string $endpoint): ?string
    {
        $kind = array_search($endpoint, self::KINDS, true);

        return $kind === false ? null : $kind;
    }

    /**
     * Guarda la llamada en la transaccion del llamador. Idempotente por (kind, key): la misma llave
     * pendiente no se duplica (insertOrIgnore: un choque de llave no aborta la transaccion de tu app).
     *
     * @param  array<string, mixed>  $body
     */
    public function write(string $kind, ?string $key, array $body, ?string $template = null, ?DateTimeInterface $sendBefore = null): void
    {
        // identify no tiene llave en el servidor (es un upsert idempotente): una llave por llamada conserva
        // el orden A → B → A; updated_at deja que Smartmailto se quede con el mas reciente (R-11).
        $key = $kind === 'identify' ? 'identify:'.Str::ulid() : (string) $key;
        if (strlen($key) > 191) {
            throw new InvalidArgumentException("Smartmailto outbox key is longer than 191 characters: {$kind}.");
        }

        // J4: con el outbox el evento puede llegar horas despues; sin hora real, cuenta desde que paso aqui.
        if ($kind === 'track') {
            $body['occurred_at'] ??= now()->format(DATE_ATOM);
        }
        if ($kind === 'identify') {
            $body['updated_at'] ??= now()->format(DATE_ATOM);
        }

        $now = now();
        $this->table()->insertOrIgnore([
            'kind' => $kind,
            'key' => $key,
            'payload' => $this->app->make('encrypter')->encryptString(json_encode($body, JSON_THROW_ON_ERROR)),
            'template' => $template,
            'send_before' => $sendBefore !== null ? Carbon::instance($sendBefore)->setTimezone($now->getTimezone()) : null,
            'status' => self::PENDING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * F-010 (regla 17.4): tu app mando por emergencia el correo de `$idempotencyKey`. Cierra la fila `send`
     * como superseded (nunca en vuelo) y deja en la misma transaccion la fila `external_report` que el
     * worker entrega a POST /api/send/external con la misma garantia.
     *
     * @throws OutboxRowInFlight si la fila esta `sending`
     */
    public function reportExternalSend(string $idempotencyKey, ?Identity $identity, ?string $template, DateTimeInterface $sentAt, string $channel, ?string $reason): void
    {
        $this->connection()->transaction(function () use ($idempotencyKey, $identity, $template, $sentAt, $channel, $reason) {
            $row = $this->table()->where('kind', 'send')->where('key', $idempotencyKey)->lockForUpdate()->first();

            $body = [];
            if ($row !== null) {
                if ($row->status === self::SENDING) {
                    throw new OutboxRowInFlight("Smartmailto outbox row {$idempotencyKey} is being delivered; retry the report in a few seconds.");
                }

                $body = $this->decode($row);
                $template ??= $row->template;
                $reason ??= $row->reason;

                // Con la fila tomada y exigiendo el estado leido: si el worker la tomo en medio, falla.
                if (in_array($row->status, [self::PENDING, self::FAILED, self::EXPIRED], true)) {
                    $changed = $this->table()->where('id', $row->id)->where('status', $row->status)
                        ->update(['status' => self::SUPERSEDED, 'updated_at' => now()]);
                    if ($changed === 0) {
                        throw new OutboxRowInFlight("Smartmailto outbox row {$idempotencyKey} changed while reporting; retry.");
                    }
                }
            }

            if ($identity !== null) {
                $body = [...array_diff_key($body, ['user_id' => 1, 'email' => 1, 'recipient_kind' => 1]), ...self::identityBody($identity)];
            }
            if (! isset($body['user_id']) && ! isset($body['email'])) {
                throw new InvalidArgumentException("Smartmailto::reportExternalSend() needs the identity: there is no outbox send for {$idempotencyKey}.");
            }
            if ($template === null || $template === '') {
                throw new InvalidArgumentException("Smartmailto::reportExternalSend() needs the template: there is no outbox send for {$idempotencyKey}.");
            }

            $this->write('external_report', $idempotencyKey, self::reportBody($idempotencyKey, $body, $template, $sentAt, $channel, $reason), $template);
        });
    }

    /**
     * Cuerpo de POST /api/send/external.
     *
     * @param  array<string, mixed>  $identity  user_id / email / recipient_kind del envio
     * @return array<string, mixed>
     */
    public static function reportBody(string $key, array $identity, string $template, DateTimeInterface $sentAt, string $channel, ?string $reason): array
    {
        return array_filter([
            'idempotency_key' => $key,
            'template' => $template,
            'user_id' => $identity['user_id'] ?? null,
            'email' => $identity['email'] ?? null,
            'recipient_kind' => ($identity['recipient_kind'] ?? null) === 'external' ? 'external' : null,
            'sent_at' => $sentAt->format(DATE_ATOM),
            'channel' => $channel,
            'reason' => $reason,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, string> */
    public static function identityBody(Identity $identity): array
    {
        return $identity->external ? [...$identity->toArray(), 'recipient_kind' => 'external'] : $identity->toArray();
    }

    /** @return array<string, mixed> */
    public function decode(object $row): array
    {
        return json_decode($this->app->make('encrypter')->decryptString($row->payload), true, flags: JSON_THROW_ON_ERROR);
    }

    public function table(): Builder
    {
        return $this->connection()->table((string) $this->config('table', 'smartmailto_outbox'));
    }

    public function alertTable(): Builder
    {
        return $this->connection()->table((string) $this->config('alert_table', 'smartmailto_outbox_alert_state'));
    }

    public function connection(): ConnectionInterface
    {
        return $this->app->make('db')->connection($this->config('connection') ?: null);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get("smartmailto.outbox.{$key}", $default);
    }
}
