<?php

namespace Agavesoft\Smartmailto\Http\Middleware;

use Agavesoft\Smartmailto\Smartmailto;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * F-008 (B2): protege la ruta que recibe el webhook de falla de Smartmailto (alias `smartmailto.webhook`).
 *
 * - Firma invalida o timestamp fuera de la ventana: 401. Smartmailto toma un 4xx como definitivo.
 * - Sin `smartmailto.webhook_secret` configurado: 500. Es un error de la app, no del aviso: con 5xx
 *   Smartmailto reintenta (~10 h) y el aviso no se pierde mientras se corrige la configuracion.
 *
 * Uso: `Route::post('/smartmailto/webhook', ...)->middleware('smartmailto.webhook');` fuera de CSRF
 * (por ejemplo en routes/api.php). Opcional: `smartmailto.webhook:600` cambia la ventana (segundos).
 */
class VerifySmartmailtoWebhook
{
    public function __construct(private readonly Smartmailto $smartmailto) {}

    public function handle(Request $request, Closure $next, string $toleranceSeconds = '300'): Response
    {
        $secret = (string) config('smartmailto.webhook_secret');
        if ($secret === '') {
            report(new \RuntimeException('Smartmailto webhook secret is not configured (SMARTMAILTO_WEBHOOK_SECRET).'));

            return new JsonResponse(['error' => 'webhook_secret_not_configured'], 500);
        }

        // Un parametro no numerico (`:10m`) seria una ventana de 0 s que rechaza todo con 4xx definitivo.
        $tolerance = ctype_digit($toleranceSeconds) && (int) $toleranceSeconds > 0 ? (int) $toleranceSeconds : 300;

        if (! $this->smartmailto->verifyWebhook($request, $secret, $tolerance)) {
            return new JsonResponse(['error' => 'invalid_signature'], 401);
        }

        return $next($request);
    }
}
