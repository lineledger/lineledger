<?php

namespace App\Services\Classification\Support;

/**
 * The "merchant" part of a bank-statement description: what stays the same for
 * the same payee month after month once the variable noise is removed —
 * reference / trace numbers, dates, amounts, store numbers, a trailing branch
 * province. "PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812" and
 * "Pre-Authorized Payment, L SOCIO DIGITAL FEE/FRA    ," both key to
 * "pre authorized payment l socio digital fee fra".
 *
 * Used for fuzzy history matching and for merchant-key bank rules. It is NOT
 * the import fingerprint — {@see DescriptionNormalizer} stays the byte-stable
 * key for de-duplication and is this key's starting point.
 *
 * Boilerplate prefixes ("pos purchase", "interac e transfer") are deliberately
 * kept: the key must be stable per payee and distinct across payees, and a
 * bare "payment" would collapse many payees into one.
 */
final class MerchantKey
{
    /** Keys shorter than this are too generic to match on ("chq", "fee"). */
    public const MIN_LENGTH = 5;

    public const MAX_LENGTH = 191;

    /** Labels that introduce a value we have already stripped (or are about to). */
    private const STOP_LABELS = [
        'ref', 'reference', 'trace', 'conf', 'confirmation', 'auth', 'authorization',
        'seq', 'txn', 'trn', 'id', 'no', 'nbr', 'num',
    ];

    /** A trailing Canadian province code is a branch location, not the payee. */
    private const PROVINCES = ['ab', 'bc', 'mb', 'nb', 'nl', 'ns', 'nt', 'nu', 'on', 'pe', 'qc', 'sk', 'yt'];

    private const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    public static function from(?string $description): string
    {
        // The fingerprint normalizer is byte-only; the merchant key is a
        // matching key, so it may lower-case accented capitals too.
        $text = mb_strtolower(DescriptionNormalizer::normalize($description));

        if ($text === '') {
            return '';
        }

        $text = self::stripAmounts($text);
        $text = self::stripDates($text);

        // Apostrophes vanish ("horton's" → "hortons"); other punctuation separates.
        $text = str_replace(["'", '’'], '', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        $kept = [];

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $token) {
            if ($token === '' || self::isNoise($token)) {
                continue;
            }

            $kept[] = $token;
        }

        if (count($kept) >= 3 && in_array(end($kept), self::PROVINCES, true)) {
            array_pop($kept);
        }

        return mb_substr(implode(' ', $kept), 0, self::MAX_LENGTH);
    }

    public static function isUsable(string $key): bool
    {
        return mb_strlen($key) >= self::MIN_LENGTH;
    }

    private static function stripAmounts(string $text): string
    {
        $text = (string) preg_replace('/(?:cad|usd|c\$|us\$|\$)\s?-?\d[\d,]*(?:\.\d{2})?/u', ' ', $text);

        return (string) preg_replace('/-?\b\d[\d,]*\.\d{2}\b/u', ' ', $text);
    }

    private static function stripDates(string $text): string
    {
        $months = self::MONTHS;

        $patterns = [
            '/\b\d{4}[-\/.]?\d{2}[-\/.]?\d{2}\b/u',                // 2026-09-03, 20260903
            '/\b\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}\b/u',            // 03/09/2026, 3.9.26
            '/\b\d{1,2}\s?(?:'.$months.')\b\.?(?:\s?\d{2,4})?/u',   // 3 sep 2026, 03sep
            '/\b(?:'.$months.')\b\.?\s\d{1,2}(?:,?\s?\d{2,4})?\b/u', // sep 3, 2026
        ];

        foreach ($patterns as $pattern) {
            $text = (string) preg_replace($pattern, ' ', $text);
        }

        return $text;
    }

    /**
     * Purely numeric, digit-heavy (a reference / store / card fragment), or a
     * label that only introduces such a value.
     */
    private static function isNoise(string $token): bool
    {
        if (preg_match('/^\d+$/u', $token) === 1) {
            return true;
        }

        $digits = (int) preg_match_all('/\d/u', $token);
        $length = mb_strlen($token);

        if ($digits >= 3) {
            return true;
        }

        if ($digits >= 2 && $digits * 2 >= $length) {
            return true;
        }

        return in_array($token, self::STOP_LABELS, true);
    }
}
