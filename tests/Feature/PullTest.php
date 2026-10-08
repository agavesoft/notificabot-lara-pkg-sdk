<?php

// F-011: ruta del pull del SDK (registro, firma, catalogo, historia, cursor y fakePull).

use Agavesoft\Smartmailto\Contracts\PullResolver;
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Pull\PullCatalog;
use Agavesoft\Smartmailto\Pull\PullContact;
use Agavesoft\Smartmailto\Pull\PullCursor;
use Agavesoft\Smartmailto\Pull\PullEvent;
use Agavesoft\Smartmailto\Pull\PullPage;
use Agavesoft\Smartmailto\Pull\PullRoute;
use Agavesoft\Smartmailto\Pull\PullSignature;
use Agavesoft\Smartmailto\Testing\PullTester;
use Agavesoft\Smartmailto\Tests\Fixtures\InMemoryPullResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    Carbon::setTestNow('2026-10-08 12:00:00');
    $this->resolver = new InMemoryPullResolver;
    $this->resolver->rows = [
        new PullContact(Identity::user(7, 'ana@example.com'), CarbonImmutable::parse('2026-10-01T10:00:00Z'),
            contactSince: CarbonImmutable::parse('2024-03-01T10:00:00Z'),
            attributes: ['plan' => 'pro', 'last_purchase_at' => '2026-09-30', 'rfc' => 'XAXX010101000'],
            events: [
                new PullEvent('ff:orden_pagada:991', 'orden_pagada', CarbonImmutable::parse('2026-09-30T10:00:00Z'), ['total' => 100], ['order', 991]),
                new PullEvent('ff:orden_pagada:12', 'orden_pagada', CarbonImmutable::parse('2023-01-10T10:00:00Z')),
            ],
            key: 7),
        new PullContact(Identity::guest('luis@example.com'), CarbonImmutable::parse('2026-10-02T10:00:00Z'), unsubscribed: true, key: 8),
    ];
});

afterEach(fn () => Carbon::setTestNow());

test('sin pull.enabled la ruta no existe (404)', function () {
    expect(Route::has('smartmailto.pull'))->toBeFalse()
        ->and(PullRoute::register(app()))->toBeFalse();

    $this->postJson('/api/smartmailto/pull', ['version' => 1])->assertNotFound();
});

test('con pull.enabled pero sin resolver la ruta no existe (404)', function () {
    config(['smartmailto.pull.enabled' => true, 'smartmailto.pull.secret' => 'pullsec_x']);

    expect(PullRoute::register(app()))->toBeFalse()
        ->and(Route::has('smartmailto.pull'))->toBeFalse();

    config(['smartmailto.pull.resolver' => stdClass::class]);
    expect(PullRoute::register(app()))->toBeFalse();
});

test('con pull.enabled y resolver la ruta existe, con su middleware y throttle', function () {
    config(['smartmailto.pull.enabled' => true, 'smartmailto.pull.resolver' => InMemoryPullResolver::class, 'smartmailto.pull.route.middleware' => ['app.switch']]);

    expect(PullRoute::register(app()))->toBeTrue()
        ->and(PullRoute::register(app()))->toBeTrue();

    $route = Route::getRoutes()->getByName('smartmailto.pull');
    expect($route->uri())->toBe('api/smartmailto/pull')
        ->and($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toBe(['throttle:120,1', 'smartmailto.pull', 'app.switch'])
        ->and(collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => $r->getName() === 'smartmailto.pull'))->toHaveCount(1);
});

test('un contacto: atributos filtrados por el catalogo, toda la historia y respuesta firmada', function () {
    $pull = Smartmailto::fakePull($this->resolver, catalog: ['plan', 'last_purchase_at']);

    expect($pull->contact(Identity::user(7)))->toBe([
        'user_id' => '7',
        'email' => 'ana@example.com',
        'contact_since' => '2024-03-01T10:00:00Z',
        'updated_at' => '2026-10-01T10:00:00Z',
        'unsubscribed' => false,
        'attributes' => ['plan' => 'pro', 'last_purchase_at' => '2026-09-30'],
        'events' => [
            ['event_id' => 'ff:orden_pagada:991', 'event' => 'orden_pagada', 'occurred_at' => '2026-09-30T10:00:00Z', 'properties' => ['total' => 100], 'object' => ['type' => 'order', 'id' => '991']],
            ['event_id' => 'ff:orden_pagada:12', 'event' => 'orden_pagada', 'occurred_at' => '2023-01-10T10:00:00Z', 'properties' => []],
        ],
    ])
        ->and($pull->contact(Identity::guest('nadie@example.com')))->toBeNull()
        // history_months = null: el resolver recibe eventsSince null (toda la historia).
        ->and($this->resolver->calls[0]['events_since'])->toBeNull();
});

test('history_months limita los eventos y el eventsSince del resolver', function () {
    config(['smartmailto.pull.history_months' => 12]);
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);

    $contact = $pull->contact(Identity::user(7));

    expect(array_column($contact['events'], 'event_id'))->toBe(['ff:orden_pagada:991'])
        ->and($contact['attributes'])->toBe([])
        ->and($this->resolver->calls[0]['events_since']->toIso8601ZuluString())->toBe('2025-10-08T12:00:00Z')
        ->and(array_column($pull->contacts()['contacts'][0]['events'], 'event_id'))->toBe(['ff:orden_pagada:991']);

    // Un events_since de Smartmailto mas reciente que el horizonte gana.
    $pull->contact(Identity::user(7), CarbonImmutable::parse('2026-09-01T00:00:00Z'));
    expect($this->resolver->calls[2]['events_since']->toIso8601ZuluString())->toBe('2026-09-01T00:00:00Z');
});

test('la carga paginada recorre todo con un cursor estable aunque haya empates de updated_at', function () {
    $same = CarbonImmutable::parse('2026-10-05T10:00:00Z');
    foreach ([12, 10, 11, 13, 9] as $id) {
        $this->resolver->rows[] = new PullContact(Identity::user($id, "u{$id}@example.com"), $same, key: $id);
    }
    $pull = Smartmailto::fakePull($this->resolver, catalog: ['plan']);

    $all = $pull->assertPullContract(limit: 2);

    expect(array_map(fn ($c) => $c['user_id'] ?? $c['email'], $all))->toBe(['7', 'luis@example.com', '9', '10', '11', '12', '13'])
        ->and($this->resolver->calls[1]['after']->key)->toBe(8)
        ->and($this->resolver->calls[0]['limit'])->toBe(2);
});

test('updated_since llega al resolver y el limite se topa en max_limit', function () {
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);

    $page = $pull->contacts(CarbonImmutable::parse('2026-10-02T00:00:00Z'), limit: 9999);

    expect(array_column($page['contacts'], 'email'))->toBe(['luis@example.com'])
        ->and($page['next_cursor'])->toBeNull()
        ->and($this->resolver->calls[0]['updated_since']->toIso8601ZuluString())->toBe('2026-10-02T00:00:00Z')
        ->and($this->resolver->calls[0]['limit'])->toBe(500);
});

test('las fechas llegan al resolver en la zona de la app (el query builder no convierte zona)', function () {
    $tz = date_default_timezone_get();
    date_default_timezone_set('America/Mexico_City');

    try {
        $pull = Smartmailto::fakePull($this->resolver, catalog: []);
        $first = $pull->contacts(CarbonImmutable::parse('2026-10-01T00:00:00Z'), limit: 1);
        $pull->contacts(CarbonImmutable::parse('2026-10-01T00:00:00Z'), $first['next_cursor'], limit: 1);

        expect($this->resolver->calls[0]['updated_since']->format('Y-m-d H:i:s'))->toBe('2026-09-30 18:00:00')
            ->and($this->resolver->calls[1]['after']->updatedAt->format('Y-m-d H:i:s'))->toBe('2026-10-01 04:00:00');
    } finally {
        date_default_timezone_set($tz);
    }
});

test('la lista deleted viaja con la pagina', function () {
    $this->resolver->deleted = [Identity::user(77), Identity::guest('x@example.com')];
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);

    expect($pull->contacts()['deleted'])->toBe([['user_id' => '77'], ['email' => 'x@example.com']]);
});

test('un cursor alterado o firmado con otro secreto se rechaza (422)', function () {
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);
    $foreign = (new PullCursor(CarbonImmutable::parse('2026-10-01T10:00:00Z'), 7))->encode('pullsec_otro');

    expect($pull->request(['op' => 'contacts', 'cursor' => $foreign])->getStatusCode())->toBe(422)
        ->and($pull->request(['op' => 'contacts', 'cursor' => 'basura'])->getContent())->toContain('invalid_cursor');
});

test('peticiones invalidas: version, op, identidad y limite (422)', function (array $payload, string $error) {
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);

    $response = $pull->request($payload);

    expect($response->getStatusCode())->toBe(422)->and(json_decode($response->getContent(), true))->toBe(['error' => $error]);
})->with([
    'version' => [['version' => 2, 'op' => 'contacts'], 'unsupported_version'],
    'op' => [['op' => 'borrar'], 'unsupported_op'],
    'identidad' => [['op' => 'contact', 'identity' => ['user_id' => '']], 'invalid_identity'],
    'limite' => [['op' => 'contacts', 'limit' => 0], 'invalid_limit'],
    'fecha' => [['op' => 'contacts', 'updated_since' => 'ayer'], 'invalid_updated_since'],
]);

test('firma invalida, timestamp viejo, sin request-id y replay se rechazan con 401', function () {
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);
    $payload = ['op' => 'contacts'];

    expect($pull->request($payload, signature: 'sha256=00')->getStatusCode())->toBe(401)
        ->and($pull->request($payload, timestamp: now()->getTimestamp() - 301)->getStatusCode())->toBe(401)
        ->and($pull->request($payload, requestId: '')->getStatusCode())->toBe(401)
        ->and($pull->request($payload, requestId: 'r-1')->getStatusCode())->toBe(200);

    $replay = $pull->request($payload, requestId: 'r-1');
    expect($replay->getStatusCode())->toBe(401)->and($replay->getContent())->toContain('replayed_request')
        ->and($this->resolver->calls)->toHaveCount(1);
});

test('un id gastado con firma invalida no bloquea la peticion legitima', function () {
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);

    expect($pull->request(['op' => 'contacts'], requestId: 'r-2', signature: 'sha256=00')->getStatusCode())->toBe(401)
        ->and($pull->request(['op' => 'contacts'], requestId: 'r-2')->getStatusCode())->toBe(200);
});

test('sin pull.secret responde 503 (Smartmailto reintenta)', function () {
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);
    config(['smartmailto.pull.secret' => null]);

    $response = $pull->request(['op' => 'contacts']);

    expect($response->getStatusCode())->toBe(503)->and($response->getContent())->toContain('pull_secret_not_configured');
});

test('si el pull se apaga con la ruta ya registrada responde 404', function () {
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);
    config(['smartmailto.pull.enabled' => false]);

    expect($pull->request(['op' => 'contacts'])->getStatusCode())->toBe(404);
});

test('sin lista en config el catalogo se lee de Smartmailto, en cache', function () {
    Http::fake(['smartmailto.test/api/variables?scope=contact' => Http::response(['data' => [
        ['scope' => 'contact', 'key' => 'plan'],
        ['scope' => 'contact', 'key' => 'last_purchase_at'],
    ]], 200)]);
    $pull = Smartmailto::fakePull($this->resolver);

    expect($pull->contact(Identity::user(7))['attributes'])->toBe(['plan' => 'pro', 'last_purchase_at' => '2026-09-30']);
    $pull->contact(Identity::user(7));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/variables?scope=contact');
});

test('si el catalogo no se puede leer responde 503 y nunca manda atributos sin filtrar', function () {
    Http::fake(['*' => Http::response(['error' => 'server_error'], 500)]);
    $pull = Smartmailto::fakePull($this->resolver);

    $response = $pull->request(['op' => 'contact', 'identity' => ['user_id' => '7']]);

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toContain('catalog_unavailable')
        ->and(cache()->get(PullCatalog::CACHE_KEY))->toBeNull();
});

test('sin attributes en include no hace falta el catalogo ni viajan atributos', function () {
    Http::fake();
    $pull = Smartmailto::fakePull($this->resolver);

    $page = $pull->contacts(limit: 1, include: []);

    expect($page['contacts'][0])->not->toHaveKey('attributes')->not->toHaveKey('events');
    Http::assertNothingSent();
});

test('una pagina que no cabe en max_response_bytes se recorta y el cursor sigue desde lo que cupo', function () {
    config(['smartmailto.pull.max_response_bytes' => 1500]);
    foreach (range(20, 25) as $id) {
        $this->resolver->rows[] = new PullContact(Identity::user($id), CarbonImmutable::parse("2026-10-06T10:00:{$id}Z"), attributes: ['plan' => str_repeat('x', 200)], key: $id);
    }
    $pull = Smartmailto::fakePull($this->resolver, catalog: ['plan']);

    $first = $pull->contacts(limit: 50);
    $all = $pull->assertPullContract(limit: 50);

    expect(count($first['contacts']))->toBeLessThan(8)
        ->and($first['next_cursor'])->not->toBeNull()
        ->and($all)->toHaveCount(8);
});

test('un resolver que regresa en el tiempo falla en voz alta (500)', function () {
    $resolver = new class implements PullResolver
    {
        public function contact(Identity $identity, ?CarbonInterface $eventsSince): ?PullContact
        {
            return null;
        }

        public function contacts(?CarbonInterface $updatedSince, ?PullCursor $after, int $limit): PullPage
        {
            return new PullPage([
                new PullContact(Identity::user(2), CarbonImmutable::parse('2026-10-02T00:00:00Z')),
                new PullContact(Identity::user(1), CarbonImmutable::parse('2026-10-01T00:00:00Z')),
            ]);
        }
    };
    $this->withoutExceptionHandling();
    $pull = Smartmailto::fakePull($resolver, catalog: []);

    expect(fn () => $pull->request(['op' => 'contacts']))->toThrow(RuntimeException::class, 'ordered');
});

test('hasMore true con una pagina vacia falla en voz alta en vez de terminar la corrida', function () {
    $resolver = new class implements PullResolver
    {
        public function contact(Identity $identity, ?CarbonInterface $eventsSince): ?PullContact
        {
            return null;
        }

        public function contacts(?CarbonInterface $updatedSince, ?PullCursor $after, int $limit): PullPage
        {
            return new PullPage([], hasMore: true);
        }
    };
    $this->withoutExceptionHandling();
    $pull = Smartmailto::fakePull($resolver, catalog: []);

    expect(fn () => $pull->request(['op' => 'contacts']))->toThrow(RuntimeException::class, 'cannot advance');
});

test('fakePull recorre cargas de mas de 120 paginas sin chocar con el throttle y no prende el push', function () {
    config(['smartmailto.enabled' => false]);
    foreach (range(100, 230) as $id) {
        $this->resolver->rows[] = new PullContact(Identity::user($id), CarbonImmutable::parse('2026-10-06T10:00:00Z'), key: $id);
    }
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);

    expect($pull->assertPullContract(limit: 1))->toHaveCount(133)
        ->and(config('smartmailto.enabled'))->toBeFalse();
});

test('fakePull sin resolver falla con un mensaje claro', function () {
    expect(fn () => Smartmailto::fakePull())->toThrow(AssertionFailedError::class, 'needs a resolver');
});

test('fakePull funciona junto con Smartmailto::fake()', function () {
    $fake = Smartmailto::fake();
    $fake->variablesResponse = [['scope' => 'contact', 'key' => 'plan']];
    $pull = Smartmailto::fakePull($this->resolver);

    expect($pull->contact(Identity::user(7))['attributes'])->toBe(['plan' => 'pro']);
});

test('el resolver se puede enlazar en el contenedor o por clase en config', function () {
    app()->bind(PullResolver::class, fn () => $this->resolver);
    config(['smartmailto.pull.enabled' => true, 'smartmailto.pull.secret' => 'pullsec_x', 'smartmailto.pull.catalog' => []]);

    expect(PullRoute::register(app()))->toBeTrue()
        ->and(PullRoute::resolver(app()))->toBe($this->resolver);

    app()->offsetUnset(PullResolver::class);
    config(['smartmailto.pull.resolver' => InMemoryPullResolver::class]);
    expect(PullRoute::resolver(app()))->toBeInstanceOf(InMemoryPullResolver::class);
});

test('la respuesta va firmada con el request-id de la peticion', function () {
    $pull = Smartmailto::fakePull($this->resolver, catalog: []);

    $response = $pull->request(['op' => 'contacts'], requestId: 'req-abc');

    expect(PullSignature::verifyResponse(PullTester::SECRET, $response->headers->get('X-Smartmailto-Timestamp'), 'req-abc', $response->getContent(), $response->headers->get('X-Smartmailto-Signature')))->toBeTrue()
        ->and(PullSignature::verifyResponse(PullTester::SECRET, $response->headers->get('X-Smartmailto-Timestamp'), 'otro', $response->getContent(), $response->headers->get('X-Smartmailto-Signature')))->toBeFalse();
});
