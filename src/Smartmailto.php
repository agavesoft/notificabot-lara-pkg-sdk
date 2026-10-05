<?php

namespace Agavesoft\Smartmailto;

use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Jobs\DeliverToSmartmailto;
use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\SyncQueue;
use InvalidArgumentException;

/**
 * Punto de entrada del SDK (facade `Smartmailto`).
 *
 * Contrato de ingesta v2 de Smartmailto. Reglas que tu app debe cumplir:
 *  - `eventId` debe derivar del hecho de negocio ("ff:orden_pagada:{order_id}"), nunca de un uuid
 *    nuevo: asi un reintento de tu listener no duplica el evento.
 *  - `secrets` son valores de un solo uso (ligas de activacion o pago): Smartmailto los guarda
 *    cifrados y solo los usa dentro del correo.
 *  - Por default todo se encola despues del commit y se reintenta si Smartmailto no responde.
 */
class Smartmailto
{
    public const MAX_BATCH = 100;

    public function __construct(private readonly Container $app) {}

    public function enabled(): bool
    {
        return (bool) $this->config('enabled', true);
    }

    public function isConfigured(): bool
    {
        return $this->enabled() && $this->app->make(SmartmailtoClient::class)->isConfigured();
    }

    /**
     * Crea o actualiza el contacto y sus atributos. Con user_id + email une al invitado con su cuenta.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function identify(Identity $identity, array $attributes = []): ?array
    {
        return $this->deliver('identify', [...$identity->toArray(), 'attributes' => $attributes]);
    }

    /**
     * Registra un evento. Es idempotente por `eventId`.
     *
     * @param  array<string, mixed>  $properties  datos para decidir y armar el correo (sin datos fiscales)
     * @param  array<string, string>  $secrets  valores de un solo uso, solo visibles dentro del correo
     * @param  array{0: string, 1: string|int}|array{type: string, id: string|int}|null  $object  objeto de negocio, ej. ['order', 100]
     */
    public function track(
        string $event,
        Identity $identity,
        array $properties = [],
        string $eventId = '',
        array $secrets = [],
        ?array $object = null,
        ?DateTimeInterface $occurredAt = null,
    ): ?array {
        return $this->deliver('track', $this->trackBody($event, $identity, $properties, $eventId, $secrets, $object, $occurredAt), key: $eventId);
    }

    /**
     * Envio transaccional inmediato con una plantilla. Es idempotente por `idempotencyKey`.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(string $template, Identity $identity, array $data = [], string $idempotencyKey = ''): ?array
    {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Smartmailto::send() requires an idempotencyKey (e.g. "app:receipt:{order_id}").');
        }

        return $this->deliver(
            'send',
            [...$identity->toArray(), 'template' => $template, 'data' => $data, 'idempotency_key' => $idempotencyKey],
            ['Idempotency-Key' => $idempotencyKey],
            $idempotencyKey,
        );
    }

    /**
     * Lote de identify/track. Con backfill=true los eventos se guardan con su fecha real y no
     * disparan workflows (carga inicial). Se parte en lotes de 100.
     */
    public function batch(bool $backfill = false): PendingBatch
    {
        return new PendingBatch($this, $backfill);
    }

    /** Estado de un evento ya registrado (sincrono). */
    public function eventStatus(int $id): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->get("events/{$id}");
    }

    /**
     * @param  list<array<string, mixed>>  $items
     *
     * @internal usado por PendingBatch
     */
    public function deliverBatch(array $items, bool $backfill): void
    {
        foreach (array_chunk($items, self::MAX_BATCH) as $index => $chunk) {
            $this->deliver('batch', ['backfill' => $backfill, 'items' => $chunk], key: 'batch:'.$index);
        }
    }

    /**
     * @internal usado por PendingBatch
     *
     * @return array<string, mixed>
     */
    public function trackBody(string $event, Identity $identity, array $properties, string $eventId, array $secrets, ?array $object, ?DateTimeInterface $occurredAt): array
    {
        if (trim($eventId) === '') {
            throw new InvalidArgumentException('Smartmailto::track() requires an eventId derived from the business fact (e.g. "app:order_paid:{order_id}").');
        }

        return array_filter([
            ...$identity->toArray(),
            'event' => $event,
            'event_id' => $eventId,
            'properties' => $properties,
            'secrets' => $secrets ?: null,
            'object' => $this->normalizeObject($object),
            'occurred_at' => $occurredAt?->format(DATE_ATOM),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function deliver(string $endpoint, array $body, array $headers = [], ?string $key = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        if (! $this->config('queue', true)) {
            return $this->app->make(SmartmailtoClient::class)->post($endpoint, $body, $headers);
        }

        $job = (new DeliverToSmartmailto($endpoint, $body, $headers, $key))->afterCommit();

        $connection = $this->config('queue_connection');
        if ($connection) {
            $job->onConnection($connection);
        }

        // Con la cola `sync` no hay reintento posible (release() no reencola y una excepcion saldria del
        // commit de la app): se entrega una vez y una falla se reporta con SmartmailtoDeliveryFailed.
        if ($this->app->make('queue')->connection($connection ?: null) instanceof SyncQueue) {
            $this->app->bound('db')
                ? $this->app->make('db')->afterCommit(fn () => $this->deliverOnce($job))
                : $this->deliverOnce($job);

            return null;
        }
        if ($queue = $this->config('queue_name')) {
            $job->onQueue($queue);
        }

        $this->app->make(Dispatcher::class)->dispatch($job);

        return null;
    }

    private function deliverOnce(DeliverToSmartmailto $job): void
    {
        try {
            $response = $this->app->make(SmartmailtoClient::class)->post($job->endpoint, $job->body, $job->headers);
            $job->reportBatchItems($response);
        } catch (SmartmailtoException $e) {
            $job->failed($e);
        }
    }

    /** @return array{type: string, id: string}|null */
    private function normalizeObject(?array $object): ?array
    {
        if ($object === null) {
            return null;
        }

        $type = $object['type'] ?? $object[0] ?? null;
        $id = $object['id'] ?? $object[1] ?? null;

        if (! is_string($type) || $id === null || $id === '') {
            throw new InvalidArgumentException('Smartmailto object must be [type, id], e.g. ["order", 100].');
        }

        return ['type' => $type, 'id' => (string) $id];
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get("smartmailto.{$key}", $default);
    }
}
