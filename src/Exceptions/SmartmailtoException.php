<?php

namespace Agavesoft\Smartmailto\Exceptions;

use RuntimeException;

/**
 * Error al hablar con Smartmailto.
 *
 * - transient: no se pudo entregar y conviene reintentar (conexion, 5xx, 429).
 * - rejected: Smartmailto rechazo la peticion (4xx de validacion o autenticacion); reintentar no sirve.
 *
 * F-009: el cuerpo del rechazo queda en `response`; error(), items(), usages() y warnings() lo leen.
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

    /** Codigo del contrato: `provision_failed`, `invalid_references`, `in_use`, `type_locked`... */
    public function error(): ?string
    {
        $error = $this->response['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    /**
     * F-009: lo que fallo en un paquete o una activacion (`provision_failed`, `invalid_references`):
     * `{ type, name, code, ref, location, message?, errors? }`.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        return $this->listOf('items');
    }

    /**
     * F-009: donde se usa la variable que no se pudo borrar o cambiar (`in_use`, `type_locked`,
     * `allowed_value_in_use`): `{ type, name, location }`.
     *
     * @return list<array<string, mixed>>
     */
    public function usages(): array
    {
        return $this->listOf('usages');
    }

    /**
     * F-009: avisos que venian en la respuesta rechazada (no hacen fallar por si solos).
     *
     * @return list<array<string, mixed>>
     */
    public function warnings(): array
    {
        return $this->listOf('warnings');
    }

    /** @return list<array<string, mixed>> */
    private function listOf(string $field): array
    {
        $value = $this->response[$field] ?? [];

        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }
}
