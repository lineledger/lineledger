<?php

namespace App\Support;

use App\Enums\Country;

/**
 * ISO 4217 currency metadata and exact integer conversion helpers.
 *
 * Mirrors the role {@see Country} plays for jurisdictions: a small,
 * curated table the app reads for symbols, names, and decimal places. Kept as a
 * support map (not an enum) because the set of currencies a company can transact
 * in is open-ended and the per-company enablement lives in `company_currencies`.
 *
 * Phase 1 of multi-currency is gated to two-decimal currencies because
 * {@see Money} assumes two decimals; zero-/three-decimal currencies (JPY, BHD)
 * are listed for display but excluded from {@see selectable} until Money grows
 * variable precision.
 */
final class Currency
{
    /**
     * code => [name, symbol, decimals]. The common set; extend as needed.
     *
     * @var array<string, array{name: string, symbol: string, decimals: int}>
     */
    private const CURRENCIES = [
        'CAD' => ['name' => 'Canadian Dollar', 'symbol' => '$', 'decimals' => 2],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2],
        'EUR' => ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2],
        'GBP' => ['name' => 'British Pound', 'symbol' => '£', 'decimals' => 2],
        'AUD' => ['name' => 'Australian Dollar', 'symbol' => '$', 'decimals' => 2],
        'NZD' => ['name' => 'New Zealand Dollar', 'symbol' => '$', 'decimals' => 2],
        'CHF' => ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2],
        'JPY' => ['name' => 'Japanese Yen', 'symbol' => '¥', 'decimals' => 0],
        'MXN' => ['name' => 'Mexican Peso', 'symbol' => '$', 'decimals' => 2],
        'INR' => ['name' => 'Indian Rupee', 'symbol' => '₹', 'decimals' => 2],
        'SGD' => ['name' => 'Singapore Dollar', 'symbol' => '$', 'decimals' => 2],
        'HKD' => ['name' => 'Hong Kong Dollar', 'symbol' => '$', 'decimals' => 2],
        'CNY' => ['name' => 'Chinese Yuan', 'symbol' => '¥', 'decimals' => 2],
        'ZAR' => ['name' => 'South African Rand', 'symbol' => 'R', 'decimals' => 2],
        'BRL' => ['name' => 'Brazilian Real', 'symbol' => 'R$', 'decimals' => 2],
        'SEK' => ['name' => 'Swedish Krona', 'symbol' => 'kr', 'decimals' => 2],
        'NOK' => ['name' => 'Norwegian Krone', 'symbol' => 'kr', 'decimals' => 2],
        'DKK' => ['name' => 'Danish Krone', 'symbol' => 'kr', 'decimals' => 2],
    ];

    public static function isKnown(string $code): bool
    {
        return isset(self::CURRENCIES[mb_strtoupper($code)]);
    }

    public static function name(string $code): string
    {
        return self::CURRENCIES[mb_strtoupper($code)]['name'] ?? mb_strtoupper($code);
    }

    public static function symbol(string $code): string
    {
        return self::CURRENCIES[mb_strtoupper($code)]['symbol'] ?? mb_strtoupper($code);
    }

    public static function decimals(string $code): int
    {
        return self::CURRENCIES[mb_strtoupper($code)]['decimals'] ?? 2;
    }

    /**
     * Currencies a company may enable today: known and two-decimal (the precision
     * {@see Money} supports). Returns code => "USD — US Dollar" for pickers.
     *
     * @return array<string, string>
     */
    public static function selectable(): array
    {
        $options = [];

        foreach (self::CURRENCIES as $code => $meta) {
            if ($meta['decimals'] === 2) {
                $options[$code] = $code.' — '.$meta['name'];
            }
        }

        return $options;
    }

    /**
     * Convert an integer foreign amount (in foreign minor units) to home minor
     * units at the given rate (home units per 1 foreign unit), rounded half-up.
     *
     * Uses bcmath so there is no float drift: the foreign and home cents are both
     * exact integers and the only inexact step (the rate multiply) is rounded
     * deterministically. Assumes both currencies share the same minor-unit scale
     * (two decimals), consistent with {@see selectable}.
     */
    public static function toHomeCents(int $foreignCents, string $rate): int
    {
        $product = bcmul((string) $foreignCents, $rate, 8);
        $halfUp = bcadd($product, str_starts_with($product, '-') ? '-0.5' : '0.5', 0);

        return (int) $halfUp;
    }

    /**
     * The four foreign memo columns for a journal line, or an empty array for a
     * home-currency line (so callers can spread it unconditionally into a line's
     * attributes). $currency null means the line is in the home currency.
     *
     * @return array{currency_code?: string, fx_rate?: string, foreign_debit_cents?: int, foreign_credit_cents?: int}
     */
    public static function lineMemo(?string $currency, ?string $rate, int $foreignDebitCents, int $foreignCreditCents): array
    {
        if ($currency === null) {
            return [];
        }

        return [
            'currency_code' => mb_strtoupper($currency),
            'fx_rate' => $rate,
            'foreign_debit_cents' => $foreignDebitCents,
            'foreign_credit_cents' => $foreignCreditCents,
        ];
    }
}
