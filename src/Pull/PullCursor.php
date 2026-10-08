<?php

namespace Agavesoft\Smartmailto\Pull;

use Carbon\CarbonImmutable;

/**
 * F-011: posicion `(updatedAt, key)` del ultimo contacto entregado. El resolver la recibe en
 * contacts() y pide lo que sigue: `updated_at > updatedAt OR (updated_at = updatedAt AND key > key)`.
 *
 * Viaja opaco y firmado con `smartmailto.pull.secret`: Smartmailto lo guarda para reanudar una corrida y
 * no puede alterarlo. Rotar el secreto invalida los cursores guardados (la corrida empieza de cero).
 */
final class PullCursor
{
    public function __construct(
        public readonly CarbonImmutable $updatedAt,
        public readonly int|string $key,
    ) {}

    public static function after(PullContact $contact): self
    {
        return new self(CarbonImmutable::instance($contact->updatedAt)->utc(), $contact->cursorKey());
    }

    public function encode(string $secret): string
    {
        $payload = self::base64(json_encode([
            'u' => $this->updatedAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'k' => $this->key,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload.'.'.self::sign($payload, $secret);
    }

    /** null si el cursor esta mal formado o no lo firmo este secreto. */
    public static function decode(string $cursor, string $secret): ?self
    {
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || ! hash_equals(self::sign($parts[0], $secret), $parts[1])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        $updatedAt = PullTime::parse($data['u'] ?? null);
        $key = $data['k'] ?? null;

        if ($updatedAt === null || ! (is_int($key) || (is_string($key) && $key !== ''))) {
            return null;
        }

        return new self($updatedAt, $key);
    }

    private static function sign(string $payload, string $secret): string
    {
        return self::base64(hash_hmac('sha256', 'smartmailto-pull-cursor.'.$payload, $secret, true));
    }

    private static function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
