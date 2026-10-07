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
     * F-008 (B3): aprovisionamiento registrado sin red. `templates()` devuelve `$templatesResponse`.
     *
     * @var list<array{resource: string, name: string, body: array<string, mixed>}>
     */
    public array $provisioned = [];

    /** @var list<array<string, mixed>> */
    public array $templatesResponse = [];

    public function templates(): ?array
    {
        return $this->templatesResponse;
    }

    public function renderedEmail(int $sendId): ?array
    {
        return null;
    }

    protected function provision(string $resource, string $name, array $body): ?array
    {
        $this->provisioned[] = ['resource' => $resource, 'name' => $name, 'body' => $body];

        return ['result' => 'created', 'name' => $name];
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

    /** @param  string  $resource  templates | partials | workflows */
    public function assertProvisioned(string $resource, string $name, ?Closure $callback = null): void
    {
        $matches = array_filter($this->provisioned, fn ($call) => $call['resource'] === $resource && $call['name'] === $name && ($callback === null || $callback($call['body'])));

        PHPUnit::assertNotEmpty($matches, "The expected [{$resource}/{$name}] was not provisioned.");
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
