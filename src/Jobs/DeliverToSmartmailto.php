<?php

namespace Agavesoft\Smartmailto\Jobs;

use Agavesoft\Smartmailto\Events\SmartmailtoDeliveryFailed;
use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\SmartmailtoClient;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Entrega una llamada a Smartmailto desde la cola.
 *
 * Reintenta solo lo transitorio (conexion, 5xx, 429 respetando Retry-After) con backoff hasta
 * `retry_hours`. Un rechazo (4xx) no se reintenta: se marca fallido y se dispara
 * SmartmailtoDeliveryFailed. Es seguro reintentar porque cada llamada lleva su event_id o
 * idempotency key: Smartmailto descarta los duplicados.
 */
class DeliverToSmartmailto implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 0; // sin tope por numero: manda retryUntil()

    public int $maxExceptions = 20;

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $endpoint,
        public array $body,
        public array $headers = [],
        public ?string $key = null,
    ) {}

    public function backoff(): array
    {
        return config('smartmailto.backoff', [30, 120, 600, 3600]);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('smartmailto.retry_hours', 24));
    }

    public function handle(SmartmailtoClient $client): void
    {
        try {
            $response = $client->post($this->endpoint, $this->body, $this->headers);
        } catch (SmartmailtoException $e) {
            if (! $e->transient) {
                $this->fail($e);

                return;
            }

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }

        // Un lote se acepta aunque traiga items invalidos (207): cada uno se reporta, sin reintento.
        if ($this->endpoint === 'batch') {
            foreach ($response['results'] ?? [] as $result) {
                if (($result['status'] ?? null) === 'invalid') {
                    $item = $this->body['items'][$result['index'] ?? -1] ?? [];
                    app('events')->dispatch(new SmartmailtoDeliveryFailed(
                        endpoint: 'batch',
                        key: $item['event_id'] ?? null,
                        status: 422,
                        error: 'Batch item rejected.',
                        response: $result,
                    ));
                }
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        app('events')->dispatch(new SmartmailtoDeliveryFailed(
            endpoint: $this->endpoint,
            key: $this->key,
            status: $exception instanceof SmartmailtoException ? $exception->status : null,
            error: $exception->getMessage(),
            response: $exception instanceof SmartmailtoException ? $exception->response : [],
        ));
    }
}
