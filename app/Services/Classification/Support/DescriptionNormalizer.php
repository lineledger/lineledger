<?php

namespace App\Services\Classification\Support;

/**
 * Canonicalizes a transaction description so the same merchant reads identically
 * across statements and documents: lower-cased, trimmed, and with every run of
 * whitespace collapsed to a single space. Used both to fingerprint statement
 * lines for de-duplication and to match a line against how the same merchant was
 * categorized before. Keep this the single definition — the fingerprint depends
 * on it being byte-stable.
 */
final class DescriptionNormalizer
{
    public static function normalize(?string $description): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim((string) $description))) ?? '';
    }
}
