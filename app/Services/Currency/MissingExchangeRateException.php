<?php

namespace App\Services\Currency;

use RuntimeException;

/**
 * Thrown when no rate (manual override, stored global, or provider fetch) can be
 * resolved for a currency pair on a date. Callers surface this to force the user
 * to enter a manual rate rather than guessing.
 */
class MissingExchangeRateException extends RuntimeException
{
    public static function for(string $base, string $quote, string $date): self
    {
        return new self("No exchange rate available for {$base}→{$quote} on or before {$date}.");
    }
}
