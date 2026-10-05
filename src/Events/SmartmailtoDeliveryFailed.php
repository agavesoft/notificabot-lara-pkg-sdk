<?php

namespace Agavesoft\Smartmailto\Events;

/**
 * Una llamada a Smartmailto no se pudo entregar: fue rechazada (4xx) o agoto los reintentos.
 * Escuchalo para registrar o alertar; `$key` es el event_id o la idempotency key de la llamada.
 */
class SmartmailtoDeliveryFailed
{
    public function __construct(
        public readonly string $endpoint,
        public readonly ?string $key,
        public readonly ?int $status,
        public readonly string $error,
        public readonly array $response = [],
    ) {}
}
