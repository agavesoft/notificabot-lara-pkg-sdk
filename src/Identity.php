<?php

namespace Agavesoft\Smartmailto;

use InvalidArgumentException;

/**
 * Quien es la persona para Smartmailto.
 *
 * - Identity::user($id, $email): persona con cuenta en tu app. Mandar el correo junto con el id
 *   permite unirla con su historial de invitado (por ejemplo al reclamar una orden).
 * - Identity::guest($email): persona sin cuenta (compra como invitado), identificada por correo.
 * - Identity::external($email): F-010 (R2) destinatario externo de un envio transaccional (por ejemplo
 *   el receptor de un CFDI). Nunca se vuelve contacto: solo vale en send() y reportExternalSend().
 */
final class Identity
{
    private function __construct(
        public readonly ?string $userId,
        public readonly ?string $email,
        public readonly bool $external = false,
    ) {}

    public static function external(string $email): self
    {
        $email = self::cleanEmail($email);

        if ($email === null) {
            throw new InvalidArgumentException('Identity::external() requires an email.');
        }

        return new self(null, $email, true);
    }

    public static function user(string|int $userId, ?string $email = null): self
    {
        $userId = trim((string) $userId);

        if ($userId === '') {
            throw new InvalidArgumentException('Identity::user() requires a non-empty user id.');
        }

        return new self($userId, self::cleanEmail($email));
    }

    public static function guest(string $email): self
    {
        $email = self::cleanEmail($email);

        if ($email === null) {
            throw new InvalidArgumentException('Identity::guest() requires an email.');
        }

        return new self(null, $email);
    }

    /** @return array{user_id?: string, email?: string} */
    public function toArray(): array
    {
        return array_filter(['user_id' => $this->userId, 'email' => $this->email], fn ($value) => $value !== null);
    }

    private static function cleanEmail(?string $email): ?string
    {
        $email = $email === null ? '' : trim($email);

        return $email === '' ? null : $email;
    }
}
