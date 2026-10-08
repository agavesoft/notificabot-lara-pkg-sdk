<?php

namespace Agavesoft\Smartmailto\Pull;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** @internal formato de fechas del contrato de pull v1 (UTC, ISO 8601 con `Z`). */
final class PullTime
{
    public static function format(DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * null si no es una fecha valida. Se entrega en la zona de la app (`app.timezone`): el query builder
     * formatea las fechas sin convertir zona, asi el resolver puede compararlas directo con sus columnas.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(date_default_timezone_get());
        } catch (\Throwable) {
            return null;
        }
    }
}
