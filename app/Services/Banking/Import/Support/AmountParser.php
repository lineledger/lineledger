<?php

namespace App\Services\Banking\Import\Support;

/**
 * Parses the wild variety of money strings found in bank exports into integer
 * cents: "1,234.56", "1.234,56" (EU), "$1 234.56", "(45.00)" (parens = negative),
 * "45.00 CR" / "45.00 DR" (suffix sign). Two-decimal currencies only.
 */
final class AmountParser
{
    /**
     * @return int|null integer cents, or null when the value is blank / unparseable
     */
    public static function toCents(?string $value, string $decimalSeparator = '.'): ?int
    {
        if ($value === null) {
            return null;
        }

        $raw = trim($value);

        if ($raw === '') {
            return null;
        }

        $negative = false;

        // Parentheses denote a negative amount: "(45.00)".
        if (preg_match('/^\((.*)\)$/', $raw, $m) === 1) {
            $negative = true;
            $raw = $m[1];
        }

        // Trailing/leading CR (credit, +) or DR (debit, −) markers.
        if (preg_match('/\b(dr|debit)\b/i', $raw) === 1) {
            $negative = true;
        }
        $raw = preg_replace('/\b(cr|dr|credit|debit)\b/i', '', $raw) ?? $raw;

        if (str_contains($raw, '-')) {
            $negative = true;
        }

        // Keep only digits and the two separators, then normalise to a PHP float string.
        $clean = preg_replace('/[^0-9.,]/', '', $raw) ?? '';

        if ($decimalSeparator === ',') {
            // EU style: '.' is the thousands separator, ',' the decimal point.
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            // Anglo style: ',' is the thousands separator.
            $clean = str_replace(',', '', $clean);
        }

        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        $cents = (int) round(((float) $clean) * 100);

        return $negative ? -abs($cents) : $cents;
    }
}
