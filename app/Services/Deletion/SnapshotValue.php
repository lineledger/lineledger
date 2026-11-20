<?php

namespace App\Services\Deletion;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Plain strings for a deletion snapshot from whatever a model attribute holds
 * (a cast Carbon, a raw string, null).
 */
final class SnapshotValue
{
    public static function date(mixed $value): ?string
    {
        return self::parse($value)?->toDateString();
    }

    public static function stamp(mixed $value): ?string
    {
        return self::parse($value)?->format('Y-m-d H:i:s');
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
