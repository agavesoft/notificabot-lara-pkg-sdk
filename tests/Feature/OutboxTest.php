<?php

// F-010: outbox transaccional, acuse, reintentos, emergencia sin duplicados (regla 17) y alertas
// agrupadas (regla 2). Criterios de aceptacion 1, 2, 3, 22, 29, 31, 32, 34, 35, 37 y la parte del SDK de 36.

use Agavesoft\Smartmailto\Attachment;
use Agavesoft\Smartmailto\Events\SmartmailtoOutboxFailed;
use Agavesoft\Smartmailto\Exceptions\OutboxRowInFlight;
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Outbox\Outbox;
use Agavesoft\Smartmailto\Outbox\OutboxWorker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const OB_PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

beforeEach(function () {
    config([
        'smartmailto.outbox.enabled' => true,
        'smartmailto.alerts.mail_to' => 'soporte@agavesoft.com.mx',
        'smartmailto.alerts.teams_webhook_url' => 'https://teams.test/hook',
        'mail.default' => 'array',
        'app.name' => 'FF',
    ]);
    (require __DIR__.'/../../database/migrations/2026_10_08_000000_create_smartmailto_outbox_tables.php')->up();
    Carbon::setTestNow('2026-10-08 12:00:00');
    $this->failures = [];
    Event::listen(SmartmailtoOutboxFailed::class, fn (SmartmailtoOutboxFailed $event) => $this->failures[] = $event);
});

afterEach(fn () => Carbon::setTestNow());

/** Servidor falso: heartbeat y Teams responden; lo demas lo decide $handler (null = 503, Smartmailto caido). */
function obServer(?Closure $handler = null): void
{
    Http::fake(function (Request $request) use ($handler) {
        if (str_starts_with($request->url(), 'https://teams.test')) {
            return Http::response('', 202);
        }
        $path = Str::after($request->url(), 'https://smartmailto.test/api/');
        if ($path === 'outbox/heartbeat') {
            return Http::response(['ok' => true, 'received_at' => '2026-10-08T12:00:00Z'], 200);
        }

        return ($handler ? $handler($path, $request) : null) ?? Http::response(['message' => 'down'], 503);
    });
}

/** Acuses del servidor con F-010 (D20 y §2b del contrato). */
function obAcking(): Closure
{
    return fn (string $path, Request $request) => match ($path) {
        'track' => Http::response(['ack' => true, 'event_id' => $request['event_id'], 'received_at' => '2026-10-08T12:00:00Z', 'id' => 10, 'duplicate' => false], 202),
        'send' => Http::response(['ack' => true, 'idempotency_key' => $request['idempotency_key'], 'received_at' => '2026-10-08T12:00:00Z', 'id' => 812, 'duplicate' => false], 202),
        'identify' => Http::response(['id' => 1, 'email' => $request['email'] ?? null], 200),
        'contacts/link' => Http::response(['link_id' => $request['link_id'], 'duplicate' => false, 'contact' => ['id' => 1, 'user_id' => '7']], 200),
        'send/external' => Http::response(['ack' => true, 'idempotency_key' => $request['idempotency_key'], 'received_at' => '2026-10-08T12:10:00Z', 'id' => 812, 'duplicate' => false, 'status' => 'sent_externally'], 201),
        default => null,
    };
}

function obWork(int $times = 1): array
{
    $stats = [];
    for ($i = 0; $i < $times; $i++) {
        $stats = app(OutboxWorker::class)->runOnce();
    }

    return $stats;
}

function obRows(): Collection
{
    return app(Outbox::class)->table()->orderBy('id')->get();
}

function obAt(int $minutes): void
{
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00')->addMinutes($minutes));
}

/** @return list<string> asuntos de los correos de alerta */
function obMails(): array
{
    return array_map(fn ($message) => $message->getOriginalMessage()->getSubject(), iterator_to_array(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()));
}

function obTeams(): int
{
    return count(Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://teams.test')));
}

function obPosts(string $path): array
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST' && $request->url() === "https://smartmailto.test/api/{$path}")
        ->map(fn ($pair) => $pair[0])->values()->all();
}

function obTrack(int $i = 1): void
{
    Smartmailto::track('orden_pagada', Identity::guest("p{$i}@example.com"), ['total' => 100], eventId: "ff:orden_pagada:{$i}");
}

function obSend(string $key = 'ff:recibo:555', int $minutes = 10): void
{
    Smartmailto::send('recibo', Identity::user(7, 'ana@example.com'), ['folio' => 'A-9'], idempotencyKey: $key, sendBefore: now()->addMinutes($minutes));
}

test('la fila se escribe en la transaccion: un rollback no deja fila (criterio 1)', function () {
    obServer(obAcking());

    try {
        DB::transaction(function () {
            obTrack();
            throw new RuntimeException('la venta fallo');
        });
    } catch (RuntimeException) {
    }
    expect(obRows())->toHaveCount(0);

    DB::transaction(fn () => obTrack());
    Http::assertNothingSent();

    $row = obRows()->first();
    $body = app(Outbox::class)->decode($row);
    expect($row->kind)->toBe('track')
        ->and($row->key)->toBe('ff:orden_pagada:1')
        ->and($row->status)->toBe('pending')
        // El payload va cifrado: el correo no esta en claro en la tabla.
        ->and($row->payload)->not->toContain('p1@example.com')
        // J4: sin occurredAt, la hora real es la del commit, no la de llegada a Smartmailto.
        ->and($body['occurred_at'])->toBe('2026-10-08T12:00:00+00:00');
});

test('el mismo event_id en la app deja una sola fila pendiente', function () {
    obTrack();
    obTrack();

    expect(obRows())->toHaveCount(1);
});

test('Smartmailto caido 2 h: quedan pending; al volver todo queda acked una vez (criterio 2)', function () {
    $down = true;
    obServer(function (string $path, Request $request) use (&$down) {
        return $down ? null : obAcking()($path, $request);
    });

    foreach (range(1, 3) as $i) {
        obTrack($i);
    }

    foreach ([0, 1, 3, 13, 73, 120] as $minute) {
        obAt($minute);
        obWork();
    }
    expect(obRows()->pluck('status')->unique()->all())->toBe(['pending'])
        ->and(obRows()->first()->last_error)->toBe('503 transient');

    $down = false;
    obAt(200);
    obWork();

    expect(obRows()->pluck('status')->all())->toBe(['acked', 'acked', 'acked'])
        ->and(json_decode(obRows()->first()->ack, true))->toEqual(['id' => 10, 'duplicate' => false, 'received_at' => '2026-10-08T12:00:00Z']);

    $acceptedTracks = count(Http::recorded(fn (Request $request, $response) => str_ends_with($request->url(), '/api/track') && $response->status() === 202));
    expect($acceptedTracks)->toBe(3);

    obAt(300);
    obWork();
    expect(count(Http::recorded(fn (Request $request, $response) => str_ends_with($request->url(), '/api/track') && $response->status() === 202)))->toBe(3);
});

test('sin acuse con la llave (servidor sin F-010) la fila no se cierra', function () {
    obServer(fn (string $path) => $path === 'track' ? Http::response(['id' => 10], 202) : null);

    obTrack();
    obWork();

    expect(obRows()->first())->status->toBe('pending')->last_error->toBe('missing ack');
});

test('401, 403 y un SDK sin configurar no fallan la fila: se reintenta (token rotado)', function () {
    obServer(fn (string $path) => $path === 'track' ? Http::response(['message' => 'Unauthenticated.'], 401) : null);
    obTrack();
    obWork();
    expect(obRows()->first()->status)->toBe('pending');

    config(['smartmailto.api_token' => '']);
    obAt(5);
    obWork();
    expect(obRows()->first())->status->toBe('pending')->last_error->toBe('not configured');
});

test('el backoff sigue 30 s, 2 min, 10 min, 1 h y despues cada hora', function () {
    obServer();
    obTrack();

    $next = [];
    foreach ([0, 1, 4, 15, 76, 137] as $minute) {
        obAt($minute);
        obWork();
        $next[] = now()->diffInSeconds(Carbon::parse(obRows()->first()->next_attempt_at));
    }

    expect($next)->toBe([30.0, 120.0, 600.0, 3600.0, 3600.0, 3600.0]);
});

test('identify en el outbox: una fila por llamada, con updated_at y consent (D7)', function () {
    obServer(obAcking());

    Smartmailto::identify(Identity::user(7, 'a@example.com'), ['plan' => 'free']);
    Smartmailto::identify(Identity::user(7, 'a@example.com'), ['plan' => 'pro']);
    Smartmailto::identify(Identity::user(7, 'a@example.com'), ['plan' => 'free'], consent: true);

    expect(obRows())->toHaveCount(3)
        ->and(app(Outbox::class)->decode(obRows()->last()))->toBe(['user_id' => '7', 'email' => 'a@example.com', 'attributes' => ['plan' => 'free'], 'consent' => true, 'updated_at' => '2026-10-08T12:00:00+00:00']);

    obWork();
    expect(obRows()->pluck('status')->unique()->all())->toBe(['acked'])
        ->and(array_map(fn ($request) => $request['attributes']['plan'], obPosts('identify')))->toBe(['free', 'pro', 'free']);
});

test('una fila sending colgada (worker muerto) vuelve a pending', function () {
    obServer(obAcking());
    obTrack();
    app(Outbox::class)->table()->update(['status' => 'sending', 'updated_at' => now()->subMinutes(11)]);

    obWork();

    expect(obRows()->first()->status)->toBe('acked');
});

test('aviso de vida: POST /api/outbox/heartbeat cada 5 min (regla 25)', function () {
    obServer(obAcking());

    obWork();
    obAt(3);
    obWork();
    obAt(5);
    obWork();

    $heartbeats = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/outbox/heartbeat'));
    expect($heartbeats)->toHaveCount(2)
        ->and($heartbeats[0][0]->method())->toBe('POST');
});

test('con 72 h sin acuse la fila queda failed; outbox:retry la reprocesa y prune la limpia (criterio 22)', function () {
    $down = true;
    obServer(function (string $path, Request $request) use (&$down) {
        return $down ? null : obAcking()($path, $request);
    });
    obTrack();
    obWork();

    obAt(72 * 60);
    obWork();

    expect(obRows()->first())->status->toBe('failed')->reason->toBe('gave_up')
        ->and($this->failures)->toHaveCount(1)
        ->and($this->failures[0]->reason)->toBe('gave_up')
        ->and($this->failures[0]->needsEmergencySend())->toBeFalse();

    $down = false;
    $this->artisan('smartmailto:outbox:retry', ['--failed' => true])->assertSuccessful();
    obWork();
    expect(obRows()->first()->status)->toBe('acked');

    obAt(72 * 60 + 6 * 24 * 60);
    $this->artisan('smartmailto:outbox:prune')->assertSuccessful();
    expect(obRows())->toHaveCount(1);

    obAt(72 * 60 + 8 * 24 * 60);
    $this->artisan('smartmailto:outbox:prune')->assertSuccessful();
    expect(obRows())->toHaveCount(0);
});

test('caida de 72 h: una alerta a los 15 min, recordatorios escalonados, un resumen y un recuperado (criterio 31)', function () {
    obServer();
    foreach (range(1, 5) as $i) {
        obTrack($i);
    }

    $alerts = [];
    foreach ([0, 10, 16, 30, 61, 120, 241, 300, 721, 1441, 2000, 2881, 4000, 72 * 60] as $minute) {
        obAt($minute);
        obWork();
        $alerts[$minute] = count(obMails());
    }

    expect($alerts[10])->toBe(0)
        ->and($alerts[16])->toBe(1)
        ->and($alerts[30])->toBe(1)
        ->and($alerts[61])->toBe(2)
        ->and($alerts[120])->toBe(2)
        ->and($alerts[241])->toBe(3)
        ->and($alerts[300])->toBe(3)
        ->and($alerts[721])->toBe(4)
        ->and($alerts[1441])->toBe(5)
        ->and($alerts[2881])->toBe(6)
        ->and($alerts[4000])->toBe(6)
        // A las 72 h: el resumen de las 5 rendidas y, con la cola vacia, el recuperado.
        ->and($alerts[72 * 60])->toBe(8)
        ->and(obTeams())->toBe(8);

    $subjects = obMails();
    expect($subjects[0])->toBe('[FF] 5 fila(s) del outbox de Smartmailto sin acuse')
        ->and($subjects[1])->toStartWith('[FF] Recordatorio: 5 fila(s)')
        ->and($subjects[6])->toBe('[FF] Resumen: filas del outbox de Smartmailto que se rindieron')
        ->and($subjects[7])->toStartWith('[FF] Recuperado');

    // El cuerpo no lleva correos: solo conteos, llaves y errores.
    $text = iterator_to_array(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())[0]->getOriginalMessage()->getTextBody();
    expect($text)->toContain('Pendientes: 5')->toContain('ff:orden_pagada:1')->toContain('503 transient')->not->toContain('@example.com');

    obAt(72 * 60 + 30);
    obWork();
    expect(obMails())->toHaveCount(8);
});

test('si el escalon no sale por ningun canal se reintenta en la siguiente pasada', function () {
    Http::fake(['teams.test/*' => Http::response('', 500), '*' => Http::response(['message' => 'down'], 503)]);
    config(['smartmailto.alerts.mail_to' => null]);
    obTrack();

    obAt(16);
    obWork();
    expect(obTeams())->toBe(1);

    obAt(17);
    obWork();
    expect(obTeams())->toBe(2);
});

test('20 rechazos 422 de la misma plantilla: una alerta inmediata; otra plantilla va aparte (criterio 32)', function () {
    obServer(fn (string $path, Request $request) => match (true) {
        $path === 'send' => Http::response(['message' => 'invalid', 'errors' => ['data.folio' => ['x']]], 422),
        str_starts_with($path, 'send/') => Http::response(['idempotency_key' => 'x', 'status' => 'not_found', 'sent_at' => null], 404),
        default => null,
    });

    foreach (range(1, 20) as $i) {
        obSend("ff:recibo:{$i}");
    }
    Smartmailto::send('cfdi', Identity::user(7, 'a@example.com'), [], idempotencyKey: 'ff:cfdi:1', sendBefore: now()->addMinutes(10));

    obWork();

    expect(obMails())->toBe(['[FF] 20 rechazo(s) definitivo(s) de recibo', '[FF] 1 rechazo(s) definitivo(s) de cfdi'])
        ->and(obRows()->pluck('status')->unique()->all())->toBe(['failed'])
        ->and(obRows()->first()->reason)->toBe('422');

    // Dentro de la ventana de 15 min se juntan; al cerrarla sale un solo aviso con el conteo.
    obAt(5);
    obSend('ff:recibo:21');
    obSend('ff:recibo:22');
    obWork();
    expect(obMails())->toHaveCount(2);

    obAt(16);
    obWork();
    expect(obMails())->toHaveCount(3)
        ->and(obMails()[2])->toBe('[FF] 2 rechazo(s) definitivo(s) de recibo');
});

test('un send con 422 queda failed, dispara SmartmailtoOutboxFailed y el reporte de emergencia cierra el ciclo (criterio 29)', function () {
    $reported = false;
    obServer(fn (string $path, Request $request) => match (true) {
        $path === 'send' => Http::response(['error' => 'missing_variables', 'field' => 'data'], 422),
        $request->method() === 'GET' && str_starts_with($path, 'send/') => Http::response(['idempotency_key' => 'ff:recibo:555', 'status' => 'not_found', 'sent_at' => null], 404),
        $path === 'send/external' => obAcking()($path, $request),
        default => null,
    });

    // El listener de tu app: manda por SES y lo reporta.
    Event::listen(SmartmailtoOutboxFailed::class, function (SmartmailtoOutboxFailed $event) use (&$reported) {
        if ($event->needsEmergencySend()) {
            Smartmailto::reportExternalSend($event->key, reason: $event->reason);
            $reported = true;
        }
    });

    obSend();
    obWork();

    expect($reported)->toBeTrue()
        ->and($this->failures)->toHaveCount(1)
        ->and($this->failures[0])->kind->toBe('send')->key->toBe('ff:recibo:555')->reason->toBe('422')
        ->template->toBe('recibo')->status->toBe(422)->error->toBe('missing_variables')
        ->and(obRows()->pluck('status', 'kind')->all())->toBe(['send' => 'superseded', 'external_report' => 'pending'])
        ->and(obMails())->toBe(['[FF] 1 rechazo(s) definitivo(s) de recibo']);

    obAt(1);
    obWork();

    expect(obRows()->pluck('status', 'kind')->all())->toBe(['send' => 'superseded', 'external_report' => 'acked'])
        ->and(obPosts('send/external')[0]->data())->toBe([
            'idempotency_key' => 'ff:recibo:555', 'template' => 'recibo', 'user_id' => '7', 'email' => 'ana@example.com',
            'sent_at' => '2026-10-08T12:00:00+00:00', 'channel' => 'ses_direct', 'reason' => '422',
        ]);

    // La fila superseded nunca vuelve a salir (ni con outbox:retry, que solo toma failed).
    $this->artisan('smartmailto:outbox:retry', ['--failed' => true]);
    obAt(120);
    obWork();
    expect(obPosts('send'))->toHaveCount(1);
});

test('acuse perdido y Smartmailto caido: al llegar a send_before la fila queda expired y dispara la emergencia (criterio 34)', function () {
    obServer();
    obSend(minutes: 10);

    foreach ([0, 1, 3, 9] as $minute) {
        obAt($minute);
        obWork();
    }
    // Un fallo de red antes de send_before no es emergencia.
    expect($this->failures)->toBe([])->and(obRows()->first()->status)->toBe('pending');

    // El backoff de 10 min no se pasa de send_before.
    expect(obRows()->first()->next_attempt_at)->toBe('2026-10-08 12:10:00');

    obAt(10);
    obWork();

    expect(obRows()->first())->status->toBe('expired')->reason->toBe('expired')
        ->and($this->failures)->toHaveCount(1)
        ->and($this->failures[0]->reason)->toBe('expired')
        ->and($this->failures[0]->needsEmergencySend())->toBeTrue()
        // Se intento la consulta (Smartmailto no respondio).
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'https://smartmailto.test/api/send/ff%3Arecibo%3A555'))->toHaveCount(1);
});

test('consulta previa: si el envio esta en cola, enviado o suprimido no hay emergencia y la fila queda acked (criterio 35)', function (string $state) {
    obServer(fn (string $path, Request $request) => $request->method() === 'GET' && str_starts_with($path, 'send/')
        ? Http::response(['idempotency_key' => 'ff:recibo:555', 'status' => $state, 'sent_at' => null], 200)
        : null);
    obSend();
    obWork();

    obAt(10);
    obWork();

    expect(obRows()->first()->status)->toBe('acked')
        ->and(json_decode(obRows()->first()->ack, true))->toBe(['status' => $state])
        ->and($this->failures)->toBe([]);
})->with(['queued', 'sending', 'sent', 'sent_externally', 'duplicate_external', 'suppressed', 'skipped']);

test('consulta previa: expired, failed o not_found si disparan la emergencia', function (string $state, int $http) {
    obServer(fn (string $path, Request $request) => $request->method() === 'GET' && str_starts_with($path, 'send/')
        ? Http::response(['idempotency_key' => 'ff:recibo:555', 'status' => $state, 'sent_at' => null], $http)
        : null);
    obSend();
    obAt(10);
    obWork();

    expect(obRows()->first()->status)->toBe('expired')->and($this->failures)->toHaveCount(1);
})->with([['expired', 200], ['failed', 200], ['not_found', 404]]);

test('410 de /api/send cierra la fila como expired (regla 17)', function () {
    obServer(fn (string $path, Request $request) => match (true) {
        $path === 'send' => Http::response(['error' => 'expired'], 410),
        $request->method() === 'GET' => Http::response(['idempotency_key' => 'k', 'status' => 'not_found', 'sent_at' => null], 404),
        default => null,
    });
    obSend();
    obWork();

    expect(obRows()->first()->status)->toBe('expired')
        ->and($this->failures[0]->reason)->toBe('expired')
        // Vencer no es un rechazo: no alerta por plantilla.
        ->and(obMails())->toBe([]);
});

test('con la fila en vuelo (sending) el reporte de emergencia falla y la fila no queda inconsistente (criterio 37)', function () {
    $thrown = null;
    obServer(function (string $path, Request $request) use (&$thrown) {
        if ($path !== 'send') {
            return null;
        }
        // Mientras el worker espera la respuesta, la app intenta reportar una emergencia.
        try {
            Smartmailto::reportExternalSend('ff:recibo:555');
        } catch (OutboxRowInFlight $e) {
            $thrown = $e;
        }

        return obAcking()($path, $request);
    });

    obSend();
    obWork();

    expect($thrown)->toBeInstanceOf(OutboxRowInFlight::class)
        ->and(obRows()->pluck('status', 'kind')->all())->toBe(['send' => 'acked']);
});

test('reportar despues del acuse (webhook send_expired) deja el reporte y no toca la fila', function () {
    obServer(obAcking());
    obSend();
    obWork();

    Smartmailto::reportExternalSend('ff:recibo:555', reason: 'expired');

    expect(obRows()->pluck('status', 'kind')->all())->toBe(['send' => 'acked', 'external_report' => 'pending']);

    // Reportar dos veces es idempotente.
    Smartmailto::reportExternalSend('ff:recibo:555', reason: 'expired');
    expect(obRows())->toHaveCount(2);
});

test('reportar un envio que no paso por el outbox exige identidad y plantilla', function () {
    expect(fn () => Smartmailto::reportExternalSend('ff:recibo:999'))->toThrow(InvalidArgumentException::class, 'identity');

    Smartmailto::reportExternalSend('ff:cfdi:1', Identity::external('receptor@cliente.mx'), 'cfdi-documentos', Carbon::parse('2026-10-08T12:05:00Z'));

    expect(app(Outbox::class)->decode(obRows()->first()))->toBe([
        'idempotency_key' => 'ff:cfdi:1', 'template' => 'cfdi-documentos', 'email' => 'receptor@cliente.mx',
        'recipient_kind' => 'external', 'sent_at' => '2026-10-08T12:05:00+00:00', 'channel' => 'ses_direct',
    ]);
});

test('un reporte 409 (send_in_flight) se reintenta; un 422 queda failed', function () {
    $status = 409;
    obServer(function (string $path, Request $request) use (&$status) {
        return $path === 'send/external' ? Http::response(['error' => $status === 409 ? 'send_in_flight' : 'email_required'], $status) : null;
    });
    Smartmailto::reportExternalSend('ff:recibo:1', Identity::user(7, 'a@example.com'), 'recibo');

    obWork();
    expect(obRows()->first())->status->toBe('pending')->last_error->toBe('409 send_in_flight');

    $status = 422;
    obAt(1);
    obWork();
    expect(obRows()->first())->status->toBe('failed')
        ->and($this->failures[0]->kind)->toBe('external_report')
        ->and($this->failures[0]->needsEmergencySend())->toBeFalse();
});

test('send con el outbox exige sendBefore', function () {
    expect(fn () => Smartmailto::send('recibo', Identity::user(7), [], 'ff:recibo:1'))->toThrow(InvalidArgumentException::class, 'sendBefore');
});

test('link: cuerpo del contrato, 404 se reintenta (el contacto puede venir atras) y link_id_conflict falla', function () {
    $response = Http::response(['error' => 'contact_not_found', 'link_id' => 'ff:link:order:555'], 404);
    obServer(function (string $path) use (&$response) {
        return $path === 'contacts/link' ? $response : null;
    });

    Smartmailto::link(Identity::user(7, 'a@example.com'), Identity::guest('compra@x.com'), 'timbres-en-otra-cuenta', 'ff:link:order:555');
    obWork();

    expect(obRows()->first())->kind->toBe('link')->key->toBe('ff:link:order:555')->status->toBe('pending')
        ->and(obPosts('contacts/link')[0]->data())->toBe([
            'survivor' => ['user_id' => '7', 'email' => 'a@example.com'],
            'absorbed' => ['email' => 'compra@x.com'],
            'reason' => 'timbres-en-otra-cuenta',
            'link_id' => 'ff:link:order:555',
        ]);

    $response = Http::response(['error' => 'link_id_conflict', 'link_id' => 'ff:link:order:555'], 409);
    obAt(1);
    obWork();
    expect(obRows()->first()->status)->toBe('failed');
});

test('adjuntos: con el outbox uno de mas de 1 MB exige URL; fromDisk se guarda como referencia y se firma en cada intento (J12)', function () {
    Storage::fake('cfdi', ['serve' => true]);
    Storage::disk('cfdi')->put('big.pdf', OB_PDF.str_repeat(' ', 2 * 1024 * 1024));
    $sha = hash('sha256', Storage::disk('cfdi')->get('big.pdf'));
    obServer();

    expect(fn () => Smartmailto::send('recibo', Identity::user(7), [], 'ff:r:1', now()->addHour(), attachments: [Attachment::fromData(OB_PDF.str_repeat(' ', 1024 * 1024 + 1), 'a.pdf')]))
        ->toThrow(InvalidArgumentException::class, 'fromDisk');

    Smartmailto::send('cfdi', Identity::user(7), [], 'ff:cfdi:1', now()->addDay(), attachments: [
        Attachment::fromDisk('cfdi', 'big.pdf', 'RFC_UUID.pdf'),
        Attachment::fromData(OB_PDF, 'chico.pdf'),
    ]);

    $stored = app(Outbox::class)->decode(obRows()->first())['attachments'];
    expect($stored[0])->toBe(['filename' => 'RFC_UUID.pdf', 'content_type' => 'application/pdf', 'disk' => 'cfdi', 'path' => 'big.pdf', 'sha256' => $sha])
        ->and($stored[1]['content'])->toBe(base64_encode(OB_PDF));

    obWork();
    obAt(1);
    obWork();

    $sent = obPosts('send');
    expect($sent)->toHaveCount(2)
        ->and(array_keys($sent[0]['attachments'][0]))->toBe(['filename', 'content_type', 'url', 'sha256'])
        ->and($sent[0]['attachments'][0]['sha256'])->toBe($sha)
        // URL nueva en cada intento (la firma de un intento viejo ya pudo caducar).
        ->and($sent[0]['attachments'][0]['url'])->not->toBe($sent[1]['attachments'][0]['url']);
});

test('outbox:prune --contact borra las filas de una persona (ARCO) y status reporta', function () {
    obTrack(1);
    obTrack(2);
    Smartmailto::link(Identity::user(9), Identity::guest('p2@example.com'), 'x', 'ff:link:1');

    $this->artisan('smartmailto:outbox:status')->assertSuccessful()->expectsOutputToContain('Pendiente mas vieja: track ff:orden_pagada:1');
    $this->artisan('smartmailto:outbox:prune', ['--contact' => 'P2@example.com'])->expectsOutputToContain('2 fila(s)')->assertSuccessful();

    expect(obRows()->pluck('key')->all())->toBe(['ff:orden_pagada:1']);
});

test('smartmailto:outbox:work --once entrega lo pendiente', function () {
    obServer(obAcking());
    obTrack();

    $this->artisan('smartmailto:outbox:work', ['--once' => true])->assertSuccessful();

    expect(obRows()->first()->status)->toBe('acked');
});

test('con el outbox apagado todo sigue igual (cola despues del commit)', function () {
    config(['smartmailto.outbox.enabled' => false, 'smartmailto.queue' => false]);
    obServer(obAcking());

    obTrack();
    Smartmailto::send('recibo', Identity::user(7, 'a@example.com'), [], 'ff:recibo:1');

    expect(obRows())->toHaveCount(0)->and(obPosts('track'))->toHaveCount(1)->and(obPosts('send'))->toHaveCount(1);
});

test('prune --contact respeta mayusculas del user_id, encuentra cc y no borra filas en vuelo', function () {
    Smartmailto::identify(Identity::user('01HZXABC', 'x@example.com'));
    Smartmailto::send('recibo', Identity::user(7, 'a@example.com'), [], 'ff:recibo:1', now()->addHour(), cc: ['Contador@Example.com']);
    Smartmailto::send('recibo', Identity::user(8, 'contador@example.com'), [], 'ff:recibo:2', now()->addHour());
    app(Outbox::class)->table()->where('key', 'ff:recibo:2')->update(['status' => 'sending']);

    $this->artisan('smartmailto:outbox:prune', ['--contact' => '01HZXABC'])->expectsOutputToContain('1 fila(s)')->assertSuccessful();
    $this->artisan('smartmailto:outbox:prune', ['--contact' => 'contador@example.com'])
        ->expectsOutputToContain('1 fila(s) de la persona')->expectsOutputToContain('en vuelo')->assertFailed();

    expect(obRows()->pluck('key')->all())->toBe(['ff:recibo:2']);
});

test('outbox:retry no reintenta un send failed (ya paso a la emergencia)', function () {
    obTrack();
    obSend();
    app(Outbox::class)->table()->update(['status' => 'failed']);

    $this->artisan('smartmailto:outbox:retry', ['--failed' => true])->expectsOutputToContain('1 send failed no se reintentan')->assertSuccessful();

    expect(obRows()->pluck('status', 'kind')->all())->toBe(['track' => 'pending', 'send' => 'failed']);
});

test('fromDisk rechaza al llamar un disco sin URLs temporales', function () {
    config(['filesystems.disks.privado' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/smartmailto-privado']]);
    Storage::disk('privado')->put('a.pdf', OB_PDF);

    expect(fn () => Attachment::fromDisk('privado', 'a.pdf'))->toThrow(InvalidArgumentException::class, 'temporary URLs');
});
