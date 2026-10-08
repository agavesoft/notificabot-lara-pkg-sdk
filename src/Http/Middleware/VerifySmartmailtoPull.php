<?php

namespace Agavesoft\Smartmailto\Http\Middleware;

use Agavesoft\Smartmailto\Smartmailto;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F-011: protege la ruta del pull (alias `smartmailto.pull`).
 *
 * - Sin `smartmailto.pull.secret`: 503. Es un error de configuracion de la app; con 5xx Smartmailto
 *   reintenta en vez de dar la corrida por perdida.
 * - Firma invalida, timestamp fuera de ±`tolerance` s o sin `X-Smartmailto-Request`: 401 (definitivo).
 * - `X-Smartmailto-Request` repetido: 401. Smartmailto genera uno nuevo por intento, asi que un repetido
 *   solo puede ser una peticion grabada. El id se marca despues de verificar la firma: nadie sin el
 *   secreto puede gastar ids ajenos.
 */
class VerifySmartmailtoPull
{
    public function __construct(private readonly Smartmailto $smartmailto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('smartmailto.pull.secret');
        if ($secret === '') {
            report(new \RuntimeException('Smartmailto pull secret is not configured (SMARTMAILTO_PULL_SECRET).'));

            return new JsonResponse(['error' => 'pull_secret_not_configured'], 503);
        }

        $tolerance = max(1, (int) config('smartmailto.pull.tolerance', 300));
        $requestId = (string) $request->header('X-Smartmailto-Request', '');

        if (preg_match('/^[A-Za-z0-9-]{1,100}$/', $requestId) !== 1 || ! $this->smartmailto->verifyWebhook($request, $secret, $tolerance)) {
            return new JsonResponse(['error' => 'invalid_signature'], 401);
        }

        // La firma vale ±tolerancia: el id se recuerda el doble, asi no se puede repetir mientras la firma vive.
        $fresh = app('cache')->store(config('smartmailto.pull.cache_store'))
            ->add('smartmailto:pull:request:'.hash('sha256', $requestId), true, $tolerance * 2);

        if (! $fresh) {
            return new JsonResponse(['error' => 'replayed_request'], 401);
        }

        return $next($request);
    }
}
