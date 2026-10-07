<?php

// F-008 (B2): verificacion del webhook de falla. Firma igual que App\Services\FailureWebhook::signature()
// del servidor: sha256=hex(hmac_sha256(secret, "{timestamp}.{body}")).

use Agavesoft\Smartmailto\Facades\Smartmailto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

const SECRET = 'whsec_test';

function webhookRequest(string $body, ?int $timestamp = null, ?string $secret = SECRET, ?string $signature = null): Request
{
    $timestamp ??= now()->getTimestamp();
    $signature ??= 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $secret);

    return Request::create('/smartmailto/webhook', 'POST', server: [
        'HTTP_X_SMARTMAILTO_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SMARTMAILTO_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], content: $body);
}

$body = '{"version":1,"event":"send_expired","delivery_id":"7f0c","project_id":3,"send_id":812,"idempotency_key":"ff:recibo:9","template":"recibo","reason":"expired","occurred_at":"2026-10-07T18:04:11Z"}';

test('una firma valida y reciente pasa', function () use ($body) {
    expect(Smartmailto::verifyWebhook(webhookRequest($body), SECRET))->toBeTrue();
});

test('firma mala, cuerpo alterado o secreto vacio no pasan', function () use ($body) {
    expect(Smartmailto::verifyWebhook(webhookRequest($body, secret: 'otro'), SECRET))->toBeFalse()
        ->and(Smartmailto::verifyWebhook(webhookRequest($body, signature: 'sha256=00'), SECRET))->toBeFalse()
        ->and(Smartmailto::verifyWebhook(webhookRequest($body, signature: ''), SECRET))->toBeFalse()
        ->and(Smartmailto::verifyWebhook(webhookRequest($body), ''))->toBeFalse();

    $tampered = webhookRequest($body);
    $tampered = Request::create('/', 'POST', server: $tampered->server->all(), content: str_replace('812', '813', $body));
    expect(Smartmailto::verifyWebhook($tampered, SECRET))->toBeFalse();
});

test('un timestamp fuera de la ventana no pasa (replay), en ambos sentidos', function () use ($body) {
    $now = now()->getTimestamp();

    expect(Smartmailto::verifyWebhook(webhookRequest($body, $now - 301), SECRET))->toBeFalse()
        ->and(Smartmailto::verifyWebhook(webhookRequest($body, $now + 301), SECRET))->toBeFalse()
        ->and(Smartmailto::verifyWebhook(webhookRequest($body, $now - 299), SECRET))->toBeTrue()
        ->and(Smartmailto::verifyWebhook(webhookRequest($body, $now - 600), SECRET, toleranceSeconds: 900))->toBeTrue();

    $request = webhookRequest($body);
    $request->headers->set('X-Smartmailto-Timestamp', 'abc');
    expect(Smartmailto::verifyWebhook($request, SECRET))->toBeFalse();
});

test('sin secreto explicito usa smartmailto.webhook_secret', function () use ($body) {
    config(['smartmailto.webhook_secret' => SECRET]);

    expect(Smartmailto::verifyWebhook(webhookRequest($body)))->toBeTrue();
});

test('el middleware deja pasar lo firmado y rechaza lo demas con 401', function () use ($body) {
    config(['smartmailto.webhook_secret' => SECRET]);
    Route::post('/smartmailto/webhook', fn () => response()->json(['ok' => true]))->middleware('smartmailto.webhook');
    $timestamp = now()->getTimestamp();
    $headers = fn (string $secret) => [
        'X-Smartmailto-Timestamp' => (string) $timestamp,
        'X-Smartmailto-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        'Content-Type' => 'application/json',
    ];

    $this->call('POST', '/smartmailto/webhook', server: $this->transformHeadersToServerVars($headers(SECRET)), content: $body)
        ->assertOk();
    $this->call('POST', '/smartmailto/webhook', server: $this->transformHeadersToServerVars($headers('otro')), content: $body)
        ->assertStatus(401);
});

test('el middleware sin secreto configurado responde 500 para que Smartmailto reintente', function () use ($body) {
    config(['smartmailto.webhook_secret' => null]);
    Route::post('/smartmailto/webhook', fn () => 'ok')->middleware('smartmailto.webhook');

    $this->call('POST', '/smartmailto/webhook', content: $body)->assertStatus(500);
});
