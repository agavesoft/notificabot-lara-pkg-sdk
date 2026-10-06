<?php

namespace Agavesoft\Smartmailto\Testing;

use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Smartmailto;
use Closure;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Doble de pruebas: `Smartmailto::fake()` registra cada llamada (con el cuerpo exacto que se
 * mandaria) en lugar de encolarla. Las validaciones del SDK (eventId, idempotencyKey) siguen activas.
 */
class SmartmailtoFake extends Smartmailto
{
    /** @var list<array{endpoint: string, body: array<string, mixed>}> */
    private array $calls = [];

    public function enabled(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function eventStatus(int $id): ?array
    {
        return null;
    }

    /** @var array<string, mixed> */
    public array $healthResponse = ['status' => 'ok'];

    public function health(): ?array
    {
        return $this->healthResponse;
    }

    /** @var list<Identity> */
    public array $forgotten = [];

    public function contact(Identity $identity): ?array
    {
        return null;
    }

    public function forget(Identity $identity): bool
    {
        $this->forgotten[] = $identity;

        return true;
    }

    /**
     * Cuerpos registrados por endpoint. `track` e `identify` incluyen los items de los lotes.
     *
     * @return list<array<string, mixed>>
     */
    public function calls(?string $endpoint = null): array
    {
        $bodies = [];

        foreach ($this->calls as $call) {
            if ($endpoint === null || $call['endpoint'] === $endpoint) {
                $bodies[] = $call['body'];
            }

            if ($call['endpoint'] === 'batch' && in_array($endpoint, ['track', 'identify'], true)) {
                foreach ($call['body']['items'] as $item) {
                    if ($item['type'] === $endpoint) {
                        $bodies[] = $item;
                    }
                }
            }
        }

        return $bodies;
    }

    public function assertTracked(string $event, ?Closure $callback = null): void
    {
        $matches = array_filter($this->calls('track'), fn ($body) => $body['event'] === $event && ($callback === null || $callback($body)));

        PHPUnit::assertNotEmpty($matches, "The expected [{$event}] event was not tracked.");
    }

    public function assertNotTracked(string $event): void
    {
        PHPUnit::assertEmpty(array_filter($this->calls('track'), fn ($body) => $body['event'] === $event), "The [{$event}] event was tracked.");
    }

    public function assertIdentified(?Closure $callback = null): void
    {
        PHPUnit::assertNotEmpty(array_filter($this->calls('identify'), fn ($body) => $callback === null || $callback($body)), 'No matching identify call.');
    }

    public function assertSent(string $template, ?Closure $callback = null): void
    {
        $matches = array_filter($this->calls('send'), fn ($body) => $body['template'] === $template && ($callback === null || $callback($body)));

        PHPUnit::assertNotEmpty($matches, "The expected [{$template}] email was not sent.");
    }

    public function assertNothingDelivered(): void
    {
        PHPUnit::assertEmpty($this->calls, 'Smartmailto calls were made.');
    }

    /** Registra en vez de entregar. */
    protected function deliver(string $endpoint, array $body, array $headers = [], ?string $key = null): ?array
    {
        $this->calls[] = ['endpoint' => $endpoint, 'body' => $body];

        return null;
    }
}
