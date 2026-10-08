<?php

namespace Agavesoft\Smartmailto\Testing;

use Agavesoft\Smartmailto\Contracts\PullResolver;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Pull\PullRoute;
use Agavesoft\Smartmailto\Pull\PullSignature;
use Agavesoft\Smartmailto\Pull\PullTime;
use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\HttpFoundation\Response;

/**
 * F-011: cliente de prueba de `Smartmailto::fakePull()`. Hace las mismas peticiones firmadas que hace
 * Smartmailto, pero por el kernel HTTP de tu app (sin red), y verifica la firma de cada respuesta. Asi tu
 * resolver pasa por la ruta real: firma, filtro del catalogo, historia, cursor y limites.
 *
 *   $pull = Smartmailto::fakePull(MiResolver::class, catalog: ['plan', 'last_purchase_at']);
 *   $pull->contact(Identity::user(7));          // el contacto como lo recibiria Smartmailto (o null)
 *   $pull->assertPullContract(limit: 2);        // recorre todas las paginas y valida el contrato
 */
class PullTester
{
    public const SECRET = 'pullsec_fake';

    /** @var list<array<string, mixed>> cuerpos de las peticiones hechas */
    public array $requests = [];

    public function __construct(private readonly Container $app, private readonly string $secret) {}

    /**
     * @param  PullResolver|class-string<PullResolver>|null  $resolver
     * @param  list<string>|null  $catalog
     */
    public static function install(Container $app, PullResolver|string|null $resolver = null, ?array $catalog = null): self
    {
        $config = $app->make('config');
        $secret = (string) $config->get('smartmailto.pull.secret') ?: self::SECRET;

        $config->set(['smartmailto.enabled' => true, 'smartmailto.pull.enabled' => true, 'smartmailto.pull.secret' => $secret]);
        if ($catalog !== null) {
            $config->set('smartmailto.pull.catalog', array_values($catalog));
        }

        if ($resolver instanceof PullResolver) {
            $app->instance(PullResolver::class, $resolver);
        } elseif (is_string($resolver)) {
            $app->bind(PullResolver::class, $resolver);
        }

        PHPUnit::assertTrue(PullRoute::register($app), 'Smartmailto pull needs a resolver: pass one to fakePull() or set smartmailto.pull.resolver.');

        return new self($app, $secret);
    }

    /**
     * Pagina de `op: contacts`, ya verificada. `cursor` es el `next_cursor` de la pagina anterior.
     *
     * @return array{version: int, contacts: list<array<string, mixed>>, deleted?: list<array<string, string>>, next_cursor: string|null}
     */
    public function contacts(?DateTimeInterface $updatedSince = null, ?string $cursor = null, int $limit = 200, array $include = ['attributes', 'events']): array
    {
        return $this->ok([
            'op' => 'contacts',
            'updated_since' => $updatedSince ? PullTime::format($updatedSince) : null,
            'cursor' => $cursor,
            'limit' => $limit,
            'include' => $include,
        ]);
    }

    /**
     * `op: contact`: el contacto como lo recibiria Smartmailto, o null si el resolver no lo devuelve.
     *
     * @return array<string, mixed>|null
     */
    public function contact(Identity $identity, ?DateTimeInterface $eventsSince = null): ?array
    {
        $body = $this->ok([
            'op' => 'contact',
            'identity' => $identity->toArray(),
            'include' => ['attributes', 'events'],
            'events_since' => $eventsSince ? PullTime::format($eventsSince) : null,
        ]);

        PHPUnit::assertLessThanOrEqual(1, count($body['contacts']), 'op contact must return at most one contact.');

        return $body['contacts'][0] ?? null;
    }

    /**
     * Recorre todas las paginas como una corrida de Smartmailto y valida el contrato: cada contacto con
     * identidad, `updated_at` y eventos completos; orden por `updated_at`; ningun contacto repetido entre
     * paginas (el cursor es estable) y fin con `next_cursor: null`.
     *
     * @return list<array<string, mixed>> todos los contactos entregados
     */
    public function assertPullContract(?DateTimeInterface $updatedSince = null, int $limit = 200, int $maxPages = 1000): array
    {
        $all = [];
        $seen = [];
        $cursor = null;
        $previous = null;

        for ($page = 1; $page <= $maxPages; $page++) {
            $body = $this->contacts($updatedSince, $cursor, $limit);

            PHPUnit::assertLessThanOrEqual($limit, count($body['contacts']), 'A page must not exceed the requested limit.');

            foreach ($body['contacts'] as $contact) {
                $this->assertContactShape($contact);

                $id = isset($contact['user_id']) ? 'user:'.$contact['user_id'] : 'email:'.strtolower($contact['email']);
                PHPUnit::assertArrayNotHasKey($id, $seen, "Contact [{$id}] was delivered twice: the order (updatedAt, key) is not stable.");
                $seen[$id] = true;

                $updatedAt = PullTime::parse($contact['updated_at']);
                PHPUnit::assertTrue($previous === null || $updatedAt >= $previous, 'Contacts must be ordered by updatedAt.');
                $previous = $updatedAt;

                $all[] = $contact;
            }

            $cursor = $body['next_cursor'];
            if ($cursor === null) {
                return $all;
            }
        }

        PHPUnit::fail("The pull did not finish after {$maxPages} pages.");
    }

    /**
     * Peticion firmada cruda (para probar rechazos).
     *
     * @param  array<string, mixed>  $payload
     */
    public function request(array $payload, ?string $requestId = null, ?int $timestamp = null, ?string $signature = null): Response
    {
        $body = json_encode(['version' => 1, ...$payload, 'project_id' => 1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp ??= now()->getTimestamp();
        $requestId ??= (string) Str::uuid();
        $this->requests[] = json_decode($body, true);

        $request = Request::create('/'.PullRoute::uri(), 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'Smartmailto-Pull/1',
            'HTTP_X_SMARTMAILTO_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SMARTMAILTO_REQUEST' => $requestId,
            'HTTP_X_SMARTMAILTO_SIGNATURE' => $signature ?? PullSignature::request($this->secret, $timestamp, $body),
        ], content: $body);

        return $this->app->make(HttpKernel::class)->handle($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ok(array $payload): array
    {
        $requestId = (string) Str::uuid();
        $response = $this->request($payload, $requestId);
        $raw = (string) $response->getContent();

        PHPUnit::assertSame(200, $response->getStatusCode(), "Smartmailto pull answered {$response->getStatusCode()}: {$raw}");
        PHPUnit::assertTrue(
            PullSignature::verifyResponse($this->secret, $response->headers->get('X-Smartmailto-Timestamp'), $requestId, $raw, $response->headers->get('X-Smartmailto-Signature')),
            'The pull response signature is not valid.',
        );

        $body = json_decode($raw, true);
        PHPUnit::assertIsArray($body);
        PHPUnit::assertSame(1, $body['version'] ?? null);
        PHPUnit::assertIsList($body['contacts'] ?? null, 'contacts must be a JSON list.');
        PHPUnit::assertArrayHasKey('next_cursor', $body);

        return $body;
    }

    /** @param array<string, mixed> $contact */
    private function assertContactShape(array $contact): void
    {
        PHPUnit::assertTrue(isset($contact['user_id']) || isset($contact['email']), 'Each contact needs user_id or email.');
        PHPUnit::assertNotNull(PullTime::parse($contact['updated_at'] ?? null), 'Each contact needs updated_at.');

        foreach ($contact['events'] ?? [] as $event) {
            PHPUnit::assertNotEmpty($event['event_id'] ?? null, 'Each event needs the same event_id you send by push.');
            PHPUnit::assertNotEmpty($event['event'] ?? null);
            PHPUnit::assertNotNull(PullTime::parse($event['occurred_at'] ?? null), 'Each event needs occurred_at.');
        }
    }
}
