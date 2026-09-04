<?php

namespace App\Enums;

use App\Services\Classification\Support\MerchantKey;

/**
 * How a bank rule's pattern is tested against an imported statement line's
 * description. All comparisons are case-insensitive. MerchantKey compares the
 * payee part of the description only (reference numbers, dates and amounts
 * stripped — see {@see MerchantKey}), which is what "Always do this" writes.
 */
enum BankRuleMatchType: string
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case Equals = 'equals';
    case Regex = 'regex';
    case MerchantKey = 'merchant_key';

    public function label(): string
    {
        return match ($this) {
            self::Contains => __('Contains'),
            self::StartsWith => __('Starts with'),
            self::Equals => __('Equals'),
            self::Regex => __('Matches regex'),
            self::MerchantKey => __('Same payee (ignores numbers and dates)'),
        };
    }

    /**
     * How narrowly the type matches — used to break ties between rules of equal
     * priority so a payee-specific rule beats a broad "contains" one.
     */
    public function specificity(): int
    {
        return match ($this) {
            self::Equals => 0,
            self::MerchantKey => 1,
            self::StartsWith => 2,
            self::Contains => 3,
            self::Regex => 4,
        };
    }

    public function matches(string $haystack, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        if ($this === self::MerchantKey) {
            $key = MerchantKey::from($pattern);

            return MerchantKey::isUsable($key) && MerchantKey::from($haystack) === $key;
        }

        $h = mb_strtolower($haystack);
        $p = mb_strtolower($pattern);

        return match ($this) {
            self::Contains => str_contains($h, $p),
            self::StartsWith => str_starts_with($h, $p),
            self::Equals => $h === $p,
            // Delimited, case-insensitive; a malformed pattern simply never matches.
            self::Regex => @preg_match('/'.str_replace('/', '\/', $pattern).'/i', $haystack) === 1,
        };
    }
}
