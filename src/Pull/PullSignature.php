<?php

namespace Agavesoft\Smartmailto\Pull;

/**
 * F-011: firmas del contrato de pull v1, espejo de `App\Services\Pull\PullSignature` del servidor.
 *
 *  - Peticion:  `sha256=hex(hmac_sha256(secret, "{timestamp}.{cuerpo}"))` (la misma formula del webhook de
 *    falla: la verifica Smartmailto::verifyWebhook() con el secreto del pull).
 *  - Respuesta: `sha256=hex(hmac_sha256(secret, "{timestamp}.{request_id}.{cuerpo}"))`, con el
 *    `X-Smartmailto-Request` de la peticion que contesta: una respuesta grabada no sirve para otra.
 *
 * Los vectores fijos de tests/Feature/PullContractTest.php son los mismos del servidor.
 */
final class PullSignature
{
    public static function request(string $secret, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public static function response(string $secret, int $timestamp, string $requestId, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$requestId.'.'.$body, $secret);
    }

    public static function verifyResponse(string $secret, ?string $timestamp, string $requestId, string $body, ?string $signature, int $toleranceSeconds = 300): bool
    {
        if ($secret === '' || $signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(self::response($secret, (int) $timestamp, $requestId, $body), $signature);
    }
}
