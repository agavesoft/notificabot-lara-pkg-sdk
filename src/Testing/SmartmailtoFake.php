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
     * F-009: paquetes (`provisionPackage` y `validatePackage`), variables y activaciones registrados sin
     * red. Las respuestas se pueden ajustar con las propiedades `*Response`.
     *
     * @var list<array{package: array<string, mixed>, activate: bool, validate: bool}>
     */
    public array $packages = [];

    /** @var list<array{scope: string, key: string, event: string|null, definition: array<string, mixed>}> */
    public array $variablesPut = [];

    /** @var list<array{scope: string, key: string, event: string|null}> */
    public array $variablesObsoleted = [];

    /** @var list<array{scope: string, key: string, event: string|null}> */
    public array $variablesDeleted = [];

    /** @var list<array{type: string, name: string}> */
    public array $activated = [];

    /** @var array<string, mixed> */
    public array $provisionResponse = ['results' => [], 'warnings' => []];

    /** @var array<string, mixed> */
    public array $validateResponse = ['valid' => true, 'errors' => [], 'warnings' => [], 'results' => []];

    /** @var list<array<string, mixed>> */
    public array $variablesResponse = [];

    /** @var array<string, mixed> */
    public array $schemaResponse = [];

    public function provisionPackage(array $package, bool $activate = false): ?array
    {
        $this->packages[] = ['package' => $package, 'activate' => $activate, 'validate' => false];

        return $this->provisionResponse;
    }

    public function validatePackage(array $package, bool $activate = false): ?array
    {
        $this->packages[] = ['package' => $package, 'activate' => $activate, 'validate' => true];

        return $this->validateResponse;
    }

    public function activateTemplate(string $name): ?array
    {
        $this->activated[] = ['type' => 'template', 'name' => $name];

        return ['result' => 'activated', 'status' => 'active'];
    }

    public function activateWorkflow(string $name): ?array
    {
        $this->activated[] = ['type' => 'workflow', 'name' => $name];

        return ['result' => 'activated', 'status' => 'active'];
    }

    public function variables(?string $scope = null, ?string $event = null): ?array
    {
        return array_values(array_filter($this->variablesResponse, fn ($variable) => ($scope === null || ($variable['scope'] ?? null) === $scope)
            && ($event === null || ($variable['event'] ?? null) === $event)));
    }

    public function putVariable(string $scope, string $key, array $definition, ?string $event = null): ?array
    {
        $this->variablesPut[] = ['scope' => $scope, 'key' => $key, 'event' => $event, 'definition' => $definition];

        return ['result' => 'created', 'scope' => $scope, 'key' => $key, 'warnings' => []];
    }

    public function obsoleteVariable(string $scope, string $key, ?string $event = null): ?array
    {
        $this->variablesObsoleted[] = ['scope' => $scope, 'key' => $key, 'event' => $event];

        return ['result' => 'obsolete', 'scope' => $scope, 'key' => $key, 'status' => 'obsolete'];
    }

    public function deleteVariable(string $scope, string $key, ?string $event = null): bool
    {
        $this->variablesDeleted[] = ['scope' => $scope, 'key' => $key, 'event' => $event];

        return true;
    }

    public function variableUsages(string $scope, string $key, ?string $event = null): ?array
    {
        return [];
    }

    public function schema(): ?array
    {
        return $this->schemaResponse;
    }

    /** F-009: algun paquete provisionado (no solo validado) cumple el callback `fn (array $package, bool $activate)`. */
    public function assertPackageProvisioned(?Closure $callback = null): void
    {
        $matches = array_filter($this->packages, fn ($call) => ! $call['validate'] && ($callback === null || $callback($call['package'], $call['activate'])));

        PHPUnit::assertNotEmpty($matches, 'The expected Smartmailto package was not provisioned.');
    }

    public function assertVariablePut(string $scope, string $key, ?Closure $callback = null): void
    {
        $matches = array_filter($this->variablesPut, fn ($call) => $call['scope'] === $scope && $call['key'] === $key && ($callback === null || $callback($call['definition'], $call['event'])));

        PHPUnit::assertNotEmpty($matches, "The expected variable [{$scope}:{$key}] was not put.");
    }

    /** @param  string  $type  template | workflow */
    public function assertActivated(string $type, string $name): void
    {
        PHPUnit::assertContains(['type' => $type, 'name' => $name], $this->activated, "The [{$type}/{$name}] was not activated.");
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
