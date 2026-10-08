<?php

namespace Agavesoft\Smartmailto\Contracts;

use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Pull\PullContact;
use Agavesoft\Smartmailto\Pull\PullCursor;
use Agavesoft\Smartmailto\Pull\PullPage;
use Carbon\CarbonInterface;

/**
 * F-011: lo implementa tu app para que Smartmailto pueda pedirle datos (pull). El SDK registra la ruta,
 * verifica la firma, filtra los atributos contra el catalogo y firma la respuesta; el resolver solo decide
 * que datos manda.
 *
 * Reglas:
 *  - Mismas identidades y mismos `eventId` que mandas por push (identify/track): si no coinciden, la carga
 *    duplica eventos.
 *  - `contactSince` lo decides tu ("desde cuando esta persona es contacto"); sin el, Smartmailto usa la
 *    fecha en que la conocio.
 *  - No devuelvas a quien no es contacto (por ejemplo destinatarios externos de un correo).
 */
interface PullResolver
{
    /**
     * Un contacto por identidad (`user_id` o correo). null si no existe o no es contacto.
     *
     * @param  CarbonInterface|null  $eventsSince  eventos desde esta fecha (null = toda la historia)
     */
    public function contact(Identity $identity, ?CarbonInterface $eventsSince): ?PullContact;

    /**
     * Contactos cambiados desde `$updatedSince` (null = carga inicial), en orden `(updatedAt, key)` y
     * estrictamente despues de `$after` cuando viene. Devuelve a lo mas `$limit`.
     */
    public function contacts(?CarbonInterface $updatedSince, ?PullCursor $after, int $limit): PullPage;
}
