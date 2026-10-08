<?php

namespace Agavesoft\Smartmailto\Exceptions;

use RuntimeException;

/**
 * F-010 (regla 17.5): la fila del outbox esta `sending` (el worker la esta entregando). No se puede
 * marcar `superseded` en vuelo: reintenta en unos segundos (el worker revisa el estado al volver).
 */
class OutboxRowInFlight extends RuntimeException {}
