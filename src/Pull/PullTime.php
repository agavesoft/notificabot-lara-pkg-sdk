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

    /** null si no es una fecha valida. */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
