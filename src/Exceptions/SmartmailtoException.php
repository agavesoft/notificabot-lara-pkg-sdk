<?php

namespace Agavesoft\Smartmailto\Exceptions;

use RuntimeException;

/**
 * Error al hablar con Smartmailto.
 *
 * - transient: no se pudo entregar y conviene reintentar (conexion, 5xx, 429).
 * - rejected: Smartmailto rechazo la peticion (4xx de validacion o autenticacion); reintentar no sirve.
 */
class SmartmailtoException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $transient,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly array $response = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function transient(string $message, ?int $status = null, ?int $retryAfter = null, ?\Throwable $previous = null): self
    {
        return new self($message, true, $status, $retryAfter, [], $previous);
    }

    public static function rejected(string $message, int $status, array $response = []): self
    {
        return new self($message, false, $status, null, $response);
    }
}
