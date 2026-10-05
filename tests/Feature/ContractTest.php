<?php

// Cuerpos exactos del contrato de ingesta v2 de Smartmailto (F-003). Si el servidor cambia el
// contrato, estas pruebas y tests/Feature/F003 del servidor deben cambiar juntas.

use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['smartmailto.queue' => false]));

test('track manda identidad, event_id, secrets, object y occurred_at', function () {
    Http::fake(['smartmailto.test/api/track' => Http::response(['id' => 10], 202)]);

    Smartmailto::track(
        'orden_pagada',
        Identity::guest('ana@example.com'),
        ['timbres' => 50, 'total' => 199.0],
        eventId: 'ff:orden_pagada:100',
        secrets: ['activation_url' => 'https://ff.mx/activar?t=abc'],
        object: ['order', 100],
        occurredAt: Carbon::parse('2026-09-01T10:00:00-06:00'),
    );

    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/track'
        && $request->hasHeader('Authorization', 'Bearer mf_live_test')
        && $request->data() === [
            'email' => 'ana@example.com',
            'event' => 'orden_pagada',
            'event_id' => 'ff:orden_pagada:100',
            'properties' => ['timbres' => 50, 'total' => 199.0],
            'secrets' => ['activation_url' => 'https://ff.mx/activar?t=abc'],
            'object' => ['type' => 'order', 'id' => '100'],
            'occurred_at' => '2026-09-01T10:00:00-06:00',
        ]);
});

test('identify de una cuenta con su correo une al invitado', function () {
    Http::fake(['*' => Http::response(['id' => 1], 200)]);

    Smartmailto::identify(Identity::user(7, 'Ana@Example.com '), ['name' => 'Ana']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/identify')
        && $request->data() === ['user_id' => '7', 'email' => 'Ana@Example.com', 'attributes' => ['name' => 'Ana']]);
});

test('send manda la idempotency key en el cuerpo y en el header', function () {
    Http::fake(['*' => Http::response(['id' => 5], 202)]);

    Smartmailto::send('recibo-compra', Identity::user(7, 'ana@example.com'), ['total' => 100], idempotencyKey: 'ff:recibo:55');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/send')
        && $request->hasHeader('Idempotency-Key', 'ff:recibo:55')
        && $request['idempotency_key'] === 'ff:recibo:55'
        && $request['template'] === 'recibo-compra');
});

test('el lote de carga inicial se parte en grupos de 100 con backfill', function () {
    Http::fake(['*' => Http::response(['results' => []], 207)]);

    $batch = Smartmailto::batch(backfill: true);
    foreach (range(1, 150) as $i) {
        $batch->track('orden_pagada', Identity::guest("p{$i}@example.com"), eventId: "ff:orden_pagada:{$i}");
    }
    $batch->dispatch();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['backfill'] === true && count($request['items']) === 100 && $request['items'][0]['type'] === 'track');
});

test('track sin event_id y send sin idempotency key se rechazan antes de salir', function () {
    Http::fake();

    expect(fn () => Smartmailto::track('x', Identity::guest('a@example.com')))->toThrow(InvalidArgumentException::class, 'eventId');
    expect(fn () => Smartmailto::send('x', Identity::guest('a@example.com')))->toThrow(InvalidArgumentException::class, 'idempotencyKey');
    Http::assertNothingSent();
});

test('una identidad necesita id o correo', function () {
    expect(fn () => Identity::user(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Identity::guest(' '))->toThrow(InvalidArgumentException::class);
});
