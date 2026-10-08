<?php

namespace Agavesoft\Smartmailto\Outbox;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * F-010 (regla 2, decision de Jose 2026-10-08 11:50): alertas del outbox agrupadas por proyecto, nunca
 * una por fila. Las emite tu app (correo a `alerts.mail_to` y Teams por `alerts.teams_webhook_url`).
 *
 * - `unacked`: filas sin acuse mas de `alert_after` (15 min). Primera alerta ("N pendientes, la mas vieja
 *   de hace X, ultimo error"), recordatorios a 1 h, 4 h, 12 h, 24 h y 48 h desde que empezo el atraso con
 *   conteos actualizados, un solo resumen a las 72 h de las filas que se rindieron y "recuperado" cuando
 *   ya no queda nada atrasado. Si el worker se brinco escalones sale un solo aviso.
 * - `rejected:{plantilla}`: rechazos definitivos (422). El primero alerta de inmediato (al terminar la
 *   pasada, con todos los de esa pasada); los siguientes se juntan en ventanas de 15 min por plantilla.
 *
 * Un aviso solo avanza su escalon si salio por algun canal configurado; sin canales solo queda en el log.
 * Sin datos personales: conteos, edades, llaves y codigos de error.
 */
class OutboxAlerts
{
    public function __construct(
        private readonly Container $app,
        private readonly Outbox $outbox,
    ) {}

    /** Registra un rechazo definitivo para el aviso agrupado de su plantilla (o tipo). */
    public function rejected(string $group, string $kind, string $key, string $error): void
    {
        $type = mb_substr('rejected:'.$group, 0, 191);
        $state = $this->state($type);

        $this->save($type, [
            'count' => $state->count + 1,
            'opened_at' => $state->opened_at ?? now(),
            'context' => json_encode(['kind' => $kind, 'key' => $key, 'error' => $error]),
        ]);
    }

    public function evaluate(): void
    {
        $this->flushRejected();
        $this->unacked();
    }

    private function flushRejected(): void
    {
        $window = (int) $this->outbox->config('reject_group_window', 15);

        foreach ($this->outbox->alertTable()->where('type', 'like', 'rejected:%')->where('count', '>', 0)->get() as $state) {
            if ($state->window_ends_at !== null && Carbon::parse($state->window_ends_at)->isFuture()) {
                continue;
            }

            $group = substr($state->type, strlen('rejected:'));
            $context = json_decode((string) $state->context, true) ?: [];
            $sent = $this->send("{$state->count} rechazo(s) definitivo(s) de {$group}", [
                'Rechazos' => $state->count,
                'Plantilla o tipo' => $group,
                'Ultima llave' => $context['key'] ?? '-',
                'Ultimo error' => $context['error'] ?? '-',
                'Que hacer' => 'Corrige el dato o la plantilla; un send rechazado lo manda tu app por emergencia (SmartmailtoOutboxFailed).',
            ]);

            if ($sent) {
                $this->save($state->type, ['count' => 0, 'opened_at' => null, 'last_alert_at' => now(), 'window_ends_at' => now()->addMinutes($window)]);
            }
        }
    }

    private function unacked(): void
    {
        $alertAfter = (int) $this->outbox->config('alert_after', 15);
        $stuck = $this->outbox->table()->whereIn('status', [Outbox::PENDING, Outbox::SENDING])
            ->where('created_at', '<=', now()->subMinutes($alertAfter));
        $count = (clone $stuck)->count();
        $state = $this->state('unacked');

        if ($count === 0) {
            if ($state->opened_at !== null) {
                // Si todo el atraso se rindio a la vez, el resumen sale antes de la cola vacia.
                $this->finalSummary($state, Carbon::parse($state->opened_at), 0);
                if ($state->first_alert_at !== null) {
                    $this->send('Recuperado: el outbox de Smartmailto ya no tiene filas atrasadas', [
                        'Duracion' => $this->age(Carbon::parse($state->opened_at)),
                        'Filas que se rindieron (failed)' => $this->gaveUpSince($state->opened_at)->count(),
                    ]);
                }
                // El siguiente atraso empieza de cero aunque el "recuperado" no haya salido.
                $this->save('unacked', ['opened_at' => null, 'first_alert_at' => null, 'last_alert_at' => null, 'next_step' => 0, 'count' => 0, 'final_sent_at' => null, 'resolved_at' => now()]);
            }

            return;
        }

        $oldest = (clone $stuck)->orderBy('created_at')->orderBy('id')->first();
        $openedAt = $state->opened_at !== null ? Carbon::parse($state->opened_at) : Carbon::parse($oldest->created_at);
        $minutes = $openedAt->diffInMinutes(now());
        $last = $this->outbox->table()->whereIn('status', [Outbox::PENDING, Outbox::SENDING])->whereNotNull('last_error')
            ->orderByDesc('updated_at')->value('last_error');

        $thresholds = [$alertAfter, ...array_map(intval(...), (array) $this->outbox->config('alert_steps', [60, 240, 720, 1440, 2880]))];
        $due = count(array_filter($thresholds, fn (int $threshold) => $minutes >= $threshold));
        $changes = ['opened_at' => $openedAt, 'count' => $count];

        if ($due > $state->next_step) {
            $first = $state->first_alert_at === null;
            $sent = $this->send(($first ? '' : 'Recordatorio: ')."{$count} fila(s) del outbox de Smartmailto sin acuse", [
                'Pendientes' => $count,
                'La mas vieja' => "hace {$this->age(Carbon::parse($oldest->created_at))} ({$oldest->kind} {$oldest->key})",
                'Ultimo error' => $last ?? '-',
                'Que hacer' => 'Revisa que Smartmailto responda y que el token sea valido (php artisan smartmailto:outbox:status).',
            ]);
            if ($sent) {
                $changes = [...$changes, 'next_step' => $due, 'first_alert_at' => $state->first_alert_at ?? now(), 'last_alert_at' => now()];
            }
        }

        $giveUp = (int) $this->outbox->config('give_up_after', 72 * 60);
        if ($minutes >= $giveUp && $this->finalSummary($state, $openedAt, $count)) {
            $changes['final_sent_at'] = now();
        }

        $this->save('unacked', $changes);
    }

    /** Un solo resumen por atraso de las filas que se rindieron a las 72 h. true si salio ahora. */
    private function finalSummary(object $state, Carbon $openedAt, int $pending): bool
    {
        $failed = $this->gaveUpSince($openedAt);
        if ($state->final_sent_at !== null || (clone $failed)->count() === 0) {
            return false;
        }

        $sent = $this->send('Resumen: filas del outbox de Smartmailto que se rindieron', [
            'Filas failed' => (clone $failed)->count(),
            'Llaves (max 20)' => (clone $failed)->orderBy('id')->limit(20)->pluck('key')->implode(', ') ?: '-',
            'Siguen pendientes' => $pending,
            'Que hacer' => 'Reprocesa con php artisan smartmailto:outbox:retry --failed cuando Smartmailto responda.',
        ]);

        return $sent;
    }

    private function gaveUpSince(mixed $since): Builder
    {
        return $this->outbox->table()->where('status', Outbox::FAILED)->where('reason', 'gave_up')->where('updated_at', '>=', $since);
    }

    /**
     * Correo y Teams. true si salio por algun canal, o si no hay canales (entonces solo queda el log).
     *
     * @param  array<string, string|int>  $facts
     */
    private function send(string $subject, array $facts): bool
    {
        $app = (string) $this->app->make('config')->get('app.name', 'app');
        $subject = "[{$app}] {$subject}";
        $this->app->make('log')->warning($subject, $facts);

        $mailTo = $this->app->make('config')->get('smartmailto.alerts.mail_to');
        $teams = $this->app->make('config')->get('smartmailto.alerts.teams_webhook_url');
        if (! $mailTo && ! $teams) {
            return true;
        }

        $sent = false;

        if ($mailTo) {
            try {
                $text = $subject."\n\n".collect($facts)->map(fn ($value, $key) => "{$key}: {$value}")->implode("\n");
                $this->app->make('mail.manager')->mailer()->raw($text, fn ($message) => $message->to($mailTo)->subject($subject));
                $sent = true;
            } catch (Throwable $e) {
                $this->app->make('log')->error('Smartmailto outbox alert mail failed: '.class_basename($e));
            }
        }

        if ($teams) {
            try {
                $sent = $this->app->make(HttpFactory::class)->timeout(5)->post($teams, $this->card($subject, $facts))->successful() || $sent;
            } catch (Throwable $e) {
                $this->app->make('log')->error('Smartmailto outbox alert Teams failed: '.class_basename($e));
            }
        }

        return $sent;
    }

    /**
     * Tarjeta adaptable para un flujo de Workflows de Power Automate (mismo formato que el servidor).
     *
     * @param  array<string, string|int>  $facts
     * @return array<string, mixed>
     */
    private function card(string $subject, array $facts): array
    {
        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        ['type' => 'TextBlock', 'text' => $subject, 'weight' => 'Bolder', 'wrap' => true],
                        ['type' => 'FactSet', 'facts' => collect($facts)->map(fn ($value, $key) => ['title' => (string) $key, 'value' => (string) $value])->values()->all()],
                    ],
                ],
            ]],
        ];
    }

    private function age(Carbon $since): string
    {
        $minutes = (int) $since->diffInMinutes(now());

        return $minutes < 120 ? "{$minutes} min" : intdiv($minutes, 60).' h';
    }

    private function state(string $type): object
    {
        return $this->outbox->alertTable()->where('type', $type)->first()
            ?? (object) ['type' => $type, 'opened_at' => null, 'first_alert_at' => null, 'last_alert_at' => null, 'next_step' => 0, 'count' => 0, 'window_ends_at' => null, 'final_sent_at' => null, 'context' => null, 'created_at' => null];
    }

    /** @param  array<string, mixed>  $values */
    private function save(string $type, array $values): void
    {
        $this->outbox->alertTable()->updateOrInsert(['type' => $type], [...$values, 'updated_at' => now()]);
    }
}
