<?php

// Entrega y reintentos: solo lo transitorio se reintenta; un rechazo dispara
// SmartmailtoDeliveryFailed; con enabled=false no se hace nada; todo se encola tras commit.

use Agavesoft\Smartmailto\Events\SmartmailtoDeliveryFailed;
use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Jobs\DeliverToSmartmailto;
use Agavesoft\Smartmailto\SmartmailtoClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function runDelivery(DeliverToSmartmailto $job): Job
{
    $queueJob = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job->setJob($queueJob);
    $job->handle(app(SmartmailtoClient::class));

    return $queueJob;
}

test('por default la llamada se encola despues del commit', function () {
    Queue::fake();

    Smartmailto::track('orden_creada', Identity::guest('a@example.com'), eventId: 'ff:orden_creada:1');

    Queue::assertPushed(DeliverToSmartmailto::class, fn ($job) => $job->endpoint === 'track' && $job->afterCommit === true && $job->key === 'ff:orden_creada:1');
});

test('con enabled=false no se encola ni se construye el cliente', function () {
    config(['smartmailto.enabled' => false, 'smartmailto.api_token' => null]);
    Queue::fake();

    expect(Smartmailto::track('x', Identity::guest('a@example.com'), eventId: 'k'))->toBeNull();
    Queue::assertNothingPushed();
});

test('un error de conexion se relanza para que la cola reintente', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7'));

    expect(fn () => runDelivery(new DeliverToSmartmailto('track', ['event' => 'x'])))
        ->toThrow(SmartmailtoException::class);
});

test('un 5xx se relanza para reintentar', function () {
    Http::fake(['*' => Http::response([], 503)]);

    expect(fn () => runDelivery(new DeliverToSmartmailto('track', ['event' => 'x'])))
        ->toThrow(fn (SmartmailtoException $e) => expect($e->transient)->toBeTrue()->and($e->status)->toBe(503));
});

test('un 429 respeta Retry-After liberando el job', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);

    $queueJob = runDelivery(new DeliverToSmartmailto('track', ['event' => 'x']));

    $queueJob->shouldHaveReceived('release')->with(42);
});

test('un 422 no se reintenta y avisa con SmartmailtoDeliveryFailed', function () {
    Event::fake([SmartmailtoDeliveryFailed::class]);
    Http::fake(['*' => Http::response(['error' => 'email_required', 'field' => 'email'], 422)]);

    $queueJob = runDelivery(new DeliverToSmartmailto('track', ['event' => 'x'], key: 'ff:x:1'));
    $queueJob->shouldHaveReceived('fail');

    (new DeliverToSmartmailto('track', ['event' => 'x'], key: 'ff:x:1'))
        ->failed(SmartmailtoException::rejected('rejected', 422, ['error' => 'email_required']));

    Event::assertDispatched(SmartmailtoDeliveryFailed::class, fn ($e) => $e->key === 'ff:x:1' && $e->status === 422);
});

test('los items invalidos de un lote se reportan uno por uno', function () {
    Event::fake([SmartmailtoDeliveryFailed::class]);
    Http::fake(['*' => Http::response(['results' => [
        ['index' => 0, 'status' => 'accepted', 'id' => 1],
        ['index' => 1, 'status' => 'invalid', 'errors' => ['email' => ['required']]],
    ]], 207)]);

    runDelivery(new DeliverToSmartmailto('batch', ['backfill' => true, 'items' => [
        ['type' => 'track', 'event_id' => 'ff:a:1'], ['type' => 'track', 'event_id' => 'ff:a:2'],
    ]]));

    Event::assertDispatchedTimes(SmartmailtoDeliveryFailed::class, 1);
    Event::assertDispatched(SmartmailtoDeliveryFailed::class, fn ($e) => $e->key === 'ff:a:2');
});

test('sin credenciales la llamada sincrona falla con mensaje claro', function () {
    config(['smartmailto.queue' => false, 'smartmailto.api_token' => null]);

    expect(fn () => Smartmailto::identify(Identity::user(1, 'a@example.com')))
        ->toThrow(SmartmailtoException::class, 'not configured');
});

test('el fake registra las llamadas sin red', function () {
    Http::fake();
    $fake = Smartmailto::fake();

    Smartmailto::track('orden_pagada', Identity::user(7, 'a@example.com'), ['timbres' => 50], eventId: 'ff:orden_pagada:9');
    Smartmailto::send('recibo', Identity::user(7), [], idempotencyKey: 'ff:recibo:9');

    $fake->assertTracked('orden_pagada', fn ($body) => $body['properties']['timbres'] === 50);
    $fake->assertSent('recibo');
    $fake->assertNotTracked('orden_creada');
    Http::assertNothingSent();
});

test('un item con error del servidor reintenta el lote completo', function () {
    Http::fake(['*' => Http::response(['results' => [
        ['index' => 0, 'status' => 'accepted', 'id' => 1],
        ['index' => 1, 'status' => 'error', 'error' => 'item_failed'],
    ]], 207)]);

    expect(fn () => runDelivery(new DeliverToSmartmailto('batch', ['items' => [['type' => 'track'], ['type' => 'track']]])))
        ->toThrow(fn (SmartmailtoException $e) => expect($e->transient)->toBeTrue());
});

test('el job no limita excepciones: reintenta hasta retryUntil', function () {
    $job = new DeliverToSmartmailto('track', []);

    expect(property_exists($job, 'maxExceptions') ? $job->maxExceptions : null)->toBeNull()
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(23)->getTimestamp());
});

test('con cola sync una falla no se pierde: dispara SmartmailtoDeliveryFailed', function () {
    config(['queue.default' => 'sync']);
    Event::fake([SmartmailtoDeliveryFailed::class]);
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '30'])]);

    Smartmailto::track('x', Identity::guest('a@example.com'), eventId: 'ff:x:9');

    Event::assertDispatched(SmartmailtoDeliveryFailed::class, fn ($e) => $e->key === 'ff:x:9' && $e->status === 429);
});

test('el fake ve los track enviados dentro de un lote', function () {
    $fake = Smartmailto::fake();

    Smartmailto::batch(backfill: true)->track('orden_pagada', Identity::guest('a@example.com'), eventId: 'ff:op:1')->dispatch();

    $fake->assertTracked('orden_pagada');
});

test('un envio vencido (410) no se reintenta y avisa para mandarlo directo', function () {
    Event::fake([SmartmailtoDeliveryFailed::class]);
    Http::fake(['*' => Http::response(['error' => 'expired'], 410)]);

    $job = new DeliverToSmartmailto('send', ['template' => 'recibo'], key: 'ff:recibo:9');
    runDelivery($job)->shouldHaveReceived('fail');
    $job->failed(SmartmailtoException::rejected('expired', 410));

    Event::assertDispatched(SmartmailtoDeliveryFailed::class, fn ($e) => $e->key === 'ff:recibo:9' && $e->status === 410);
});
