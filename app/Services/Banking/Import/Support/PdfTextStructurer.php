<?php

namespace App\Services\Banking\Import\Support;

use App\Services\Banking\Import\DTO\ParsedTransaction;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns the plain text of a bank-statement PDF into transactions.
 *
 * Two strategies, chosen automatically:
 *
 *  1. Running-balance delta (preferred): when the statement has an opening-balance
 *     row and a trailing balance column, each transaction's SIGN and amount come from
 *     balance[i] − balance[i-1]. This is bank-agnostic and the only reliable way to
 *     read statements (BMO/RBC/TD business etc.) that print UNSIGNED amounts in
 *     separate Withdrawals / Deposits columns — column geometry is never needed.
 *
 *  2. Trailing amount (fallback): a leading date, then a signed amount and optional
 *     running balance on the line, for single-signed-amount layouts.
 *
 * Dates without a year ("Mar 09") take the year inferred from the statement period.
 * For genuinely awkward layouts the optional AI layer is the robust path.
 */
final class PdfTextStructurer
{
    private const MONEY_PATTERN = '/\(?-?\$?\d[\d,]*\.\d{2}\)?(?:\s?(?:CR|DR))?/i';

    private const OPENING_PATTERN = '/\b(opening balance|balance (brought|carried) forward|previous balance|balance forward)\b/i';

    private const SUMMARY_PATTERN = '/\b(closing|sub-?total|totals?)\b/i';

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * @return array{transactions: list<ParsedTransaction>, beginDate: ?CarbonImmutable, endDate: ?CarbonImmutable, endBalanceCents: ?int, skipped: int}
     */
    public function structure(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $year = $this->inferYear($lines);

        $events = [];
        foreach ($lines as $line) {
            $date = $this->leadingDate($line, $year);
            if ($date === null) {
                continue;
            }
            $tokens = $this->moneyTokens($line);
            if ($tokens === []) {
                continue;
            }
            $events[] = ['date' => $date['date'], 'end' => $date['end'], 'line' => $line, 'tokens' => $tokens];
        }

        // A running-balance column + an opening-balance seed lets us sign every row
        // from the balance delta. Otherwise fall back to reading a signed amount.
        $seed = $this->openingSeed($events);

        $built = $seed !== null
            ? $this->byBalanceDelta($events, $seed)
            : $this->byTrailingAmount($events);

        // The running balance on the last row is the most reliable closing balance;
        // only scan the text for a labelled "closing balance" when we have none.
        $built['endBalanceCents'] ??= $this->closingBalance($lines);

        return $built;
    }

    /**
     * @param  list<array{date: CarbonImmutable, end: int, line: string, tokens: list<array{cents: int, offset: int}>}>  $events
     * @return array{transactions: list<ParsedTransaction>, beginDate: ?CarbonImmutable, endDate: ?CarbonImmutable, endBalanceCents: ?int, skipped: int}
     */
    private function byBalanceDelta(array $events, int $seed): array
    {
        $previous = $seed;
        $transactions = [];
        $skipped = 0;
        $beginDate = null;
        $endDate = null;

        foreach ($events as $event) {
            $balance = $event['tokens'][count($event['tokens']) - 1]['cents'];

            // The opening-balance row only seeds the running balance; never emit it.
            if (preg_match(self::OPENING_PATTERN, $event['line']) === 1) {
                $previous = $balance;

                continue;
            }

            // Summary rows (e.g. "Closing totals") carry column totals, not a running
            // balance — skip without disturbing the running balance.
            if (preg_match(self::SUMMARY_PATTERN, $event['line']) === 1) {
                continue;
            }

            $amount = $balance - $previous;
            $previous = $balance;

            if ($amount === 0) {
                $skipped++;

                continue;
            }

            $transactions[] = new ParsedTransaction(
                date: $event['date'],
                amountCents: $amount,
                description: $this->descriptionBetween($event['line'], $event['end'], $event['tokens'][0]['offset']),
                balanceCents: $balance,
                raw: ['line' => trim($event['line'])],
            );

            [$beginDate, $endDate] = $this->extend($beginDate, $endDate, $event['date']);
        }

        // The closing balance is the running balance after the last real transaction.
        $endBalance = $transactions === [] ? null : $previous;

        return ['transactions' => $transactions, 'beginDate' => $beginDate, 'endDate' => $endDate, 'endBalanceCents' => $endBalance, 'skipped' => $skipped];
    }

    /**
     * @param  list<array{date: CarbonImmutable, end: int, line: string, tokens: list<array{cents: int, offset: int}>}>  $events
     * @return array{transactions: list<ParsedTransaction>, beginDate: ?CarbonImmutable, endDate: ?CarbonImmutable, endBalanceCents: ?int, skipped: int}
     */
    private function byTrailingAmount(array $events): array
    {
        $transactions = [];
        $skipped = 0;
        $beginDate = null;
        $endDate = null;
        $endBalance = null;

        foreach ($events as $event) {
            if (preg_match(self::OPENING_PATTERN, $event['line']) === 1
                || preg_match(self::SUMMARY_PATTERN, $event['line']) === 1) {
                $skipped++;

                continue;
            }

            $tokens = $event['tokens'];

            // Two+ amounts → last is the running balance, the previous the amount.
            if (count($tokens) >= 2) {
                $amount = $tokens[count($tokens) - 2]['cents'];
                $balance = $tokens[count($tokens) - 1]['cents'];
                $endBalance = $balance;
            } else {
                $amount = $tokens[0]['cents'];
                $balance = null;
            }

            $transactions[] = new ParsedTransaction(
                date: $event['date'],
                amountCents: $amount,
                description: $this->descriptionBetween($event['line'], $event['end'], $tokens[0]['offset']),
                balanceCents: $balance,
                raw: ['line' => trim($event['line'])],
            );

            [$beginDate, $endDate] = $this->extend($beginDate, $endDate, $event['date']);
        }

        return ['transactions' => $transactions, 'beginDate' => $beginDate, 'endDate' => $endDate, 'endBalanceCents' => $endBalance, 'skipped' => $skipped];
    }

    /**
     * The balance on the first opening-balance row, used to seed delta signing.
     *
     * @param  list<array{date: CarbonImmutable, end: int, line: string, tokens: list<array{cents: int, offset: int}>}>  $events
     */
    private function openingSeed(array $events): ?int
    {
        foreach ($events as $event) {
            if (preg_match(self::OPENING_PATTERN, $event['line']) === 1) {
                return $event['tokens'][count($event['tokens']) - 1]['cents'];
            }
        }

        return null;
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function extend(?CarbonImmutable $beginDate, ?CarbonImmutable $endDate, CarbonImmutable $date): array
    {
        if ($beginDate === null || $date->lessThan($beginDate)) {
            $beginDate = $date;
        }
        if ($endDate === null || $date->greaterThanOrEqualTo($endDate)) {
            $endDate = $date;
        }

        return [$beginDate, $endDate];
    }

    /**
     * @return array{date: CarbonImmutable, end: int}|null
     */
    private function leadingDate(string $line, int $year): ?array
    {
        // "Mar 09" / "Mar. 9" — month abbreviation then day, no year.
        if (preg_match('/^\s*([A-Za-z]{3})[a-z]*\.?\s+(\d{1,2})(?!\d)/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $month = self::MONTHS[strtolower($m[1][0])] ?? null;
            if ($month !== null) {
                $date = $this->makeDate($year, $month, (int) $m[2][0]);
                if ($date !== null) {
                    return ['date' => $date, 'end' => $m[2][1] + strlen($m[2][0])];
                }
            }
        }

        // "09 Mar" — day then month abbreviation, no year.
        if (preg_match('/^\s*(\d{1,2})\s+([A-Za-z]{3})[a-z]*\.?/', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $month = self::MONTHS[strtolower($m[2][0])] ?? null;
            if ($month !== null) {
                $date = $this->makeDate($year, $month, (int) $m[1][0]);
                if ($date !== null) {
                    return ['date' => $date, 'end' => $m[2][1] + strlen($m[2][0])];
                }
            }
        }

        // Full dates that carry their own year.
        $pattern = '/^\s*(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}|[A-Za-z]{3,9}\.?\s+\d{1,2},?\s+\d{4}|\d{1,2}[ -][A-Za-z]{3,9}\.?[ -]\d{2,4})/u';
        if (preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $parsed = $this->parseFullDate(trim($m[1][0]));
            if ($parsed !== null) {
                return ['date' => $parsed, 'end' => $m[1][1] + strlen($m[1][0])];
            }
        }

        return null;
    }

    /**
     * @return list<array{cents: int, offset: int}>
     */
    private function moneyTokens(string $line): array
    {
        if (preg_match_all(self::MONEY_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $tokens = [];
        foreach ($matches[0] as [$raw, $offset]) {
            $cents = AmountParser::toCents($raw);
            if ($cents !== null) {
                $tokens[] = ['cents' => $cents, 'offset' => $offset];
            }
        }

        return $tokens;
    }

    private function descriptionBetween(string $line, int $start, int $moneyOffset): string
    {
        $length = max(0, $moneyOffset - $start);
        $slice = $length > 0 ? substr($line, $start, $length) : substr($line, $start);

        return trim(preg_replace('/\s+/', ' ', $slice) ?? '');
    }

    /**
     * @param  list<string>  $lines
     */
    private function closingBalance(array $lines): ?int
    {
        foreach ($lines as $line) {
            if (preg_match('/\b(closing|ending|new)\s+(balance|totals?)\b/i', $line) === 1) {
                $tokens = $this->moneyTokens($line);
                if ($tokens !== []) {
                    return $tokens[count($tokens) - 1]['cents'];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function inferYear(array $lines): int
    {
        foreach ($lines as $line) {
            if (preg_match('/(20\d{2})/', $line, $m) === 1
                && preg_match('/\b(period|statement|closing|ending|as of|date)\b/i', $line) === 1) {
                return (int) $m[1];
            }
        }

        $counts = [];
        foreach ($lines as $line) {
            if (preg_match_all('/\b(20\d{2})\b/', $line, $mm) > 0) {
                foreach ($mm[1] as $found) {
                    $counts[$found] = ($counts[$found] ?? 0) + 1;
                }
            }
        }

        if ($counts !== []) {
            arsort($counts);

            return (int) array_key_first($counts);
        }

        return CarbonImmutable::now()->year;
    }

    private function makeDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::create($year, $month, $day);
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && (int) $date->month === $month && (int) $date->day === $day
            ? $date
            : null;
    }

    private function parseFullDate(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d', 'm/d/Y', 'd/m/Y', 'M d, Y', 'M d Y', 'd M Y', 'd-M-Y', 'd/m/y', 'm/d/y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($parsed !== false && $parsed->format($format) === $value) {
                    return $parsed;
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
