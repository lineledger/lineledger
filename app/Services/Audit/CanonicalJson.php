<?php

namespace App\Services\Audit;

/**
 * Deterministic JSON encoding for hash-chain inputs.
 *
 * Recursively sorts associative keys so two semantically equal payloads
 * always serialize to the same byte sequence regardless of insertion
 * order. Numeric-indexed (list) arrays preserve their order — order
 * carries meaning for journal lines.
 */
class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    protected static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        $normalized = [];
        foreach ($value as $k => $v) {
            $normalized[$k] = self::normalize($v);
        }

        if (! $isList) {
            ksort($normalized);
        }

        return $normalized;
    }
}
