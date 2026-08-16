<?php

namespace App\Services\Banking\Import\Mapping;

use App\Services\Banking\Import\DTO\ColumnMapping;
use App\Services\Banking\Import\Support\AmountParser;
use App\Services\Banking\Import\Support\DateFormatGuesser;

/**
 * Deterministically guesses how a bank's CSV / Excel columns map to a transaction
 * by matching header labels against synonym sets, then sampling the data to pin
 * down the date format, decimal separator, and (where a running-balance column
 * exists) the sign convention. Returns null when it cannot find at least a date
 * and an amount — the caller then falls back to the manual wizard or the AI layer.
 */
final class ColumnMappingDetector
{
    /** @var list<string> */
    private const DATE = ['date', 'transaction date', 'posted date', 'posting date', 'date posted', 'trans date', 'value date', 'effective date', 'transaction date posted'];

    /** @var list<string> */
    private const DESCRIPTION = ['description', 'memo', 'payee', 'details', 'narrative', 'transaction', 'name', 'particulars', 'merchant', 'note'];

    /** @var list<string> */
    private const AMOUNT = ['amount', 'transaction amount', 'value', 'amount (cad)', 'amount (usd)', 'net amount'];

    /** Money OUT of the account (a credit to an asset bank in the ledger). */
    private const DEBIT = ['debit', 'withdrawal', 'withdrawals', 'money out', 'paid out', 'payment', 'payments', 'debit amount', 'withdrawal amount', 'funds out', 'outflow'];

    /** Money INTO the account (a debit to an asset bank in the ledger). */
    private const CREDIT = ['credit', 'deposit', 'deposits', 'money in', 'paid in', 'credit amount', 'deposit amount', 'funds in', 'inflow'];

    /** @var list<string> */
    private const BALANCE = ['balance', 'running balance', 'balance amount', 'ledger balance', 'account balance'];

    /** @var list<string> */
    private const CHECK = ['cheque', 'check', 'cheque number', 'check number', 'cheque no', 'check no', 'chq', 'serial'];

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, ?string>>  $rows  a sample is enough
     */
    public function detect(array $headers, array $rows): ?ColumnMapping
    {
        $date = $this->match($headers, self::DATE);

        if ($date === null) {
            return null;
        }

        $debit = $this->match($headers, self::DEBIT);
        $credit = $this->match($headers, self::CREDIT);
        $amount = $this->match($headers, self::AMOUNT, exclude: array_filter([$debit, $credit]));

        $balance = $this->match($headers, self::BALANCE);
        $check = $this->match($headers, self::CHECK);

        $used = array_filter([$date, $debit, $credit, $amount, $balance, $check]);
        $descriptions = $this->descriptionColumns($headers, $used);

        $dateSamples = $this->sample($rows, $date);
        $dateGuess = DateFormatGuesser::guess($dateSamples);

        if ($debit !== null && $credit !== null) {
            return new ColumnMapping(
                amountMode: 'debit_credit',
                dateColumn: $date,
                descriptionColumns: $descriptions,
                debitColumn: $debit,
                creditColumn: $credit,
                balanceColumn: $balance,
                checkNumberColumn: $check,
                dateFormat: $dateGuess['format'],
                decimalSeparator: $this->detectDecimalSeparator($rows, [$debit, $credit]),
            );
        }

        if ($amount === null) {
            // A lone credit or debit column still works as a signed single column.
            $amount = $credit ?? $debit;
        }

        if ($amount === null) {
            return null;
        }

        $decimal = $this->detectDecimalSeparator($rows, [$amount]);

        return new ColumnMapping(
            amountMode: 'single',
            dateColumn: $date,
            descriptionColumns: $descriptions,
            amountColumn: $amount,
            balanceColumn: $balance,
            checkNumberColumn: $check,
            dateFormat: $dateGuess['format'],
            decimalSeparator: $decimal,
            flipSign: $this->detectFlipSign($rows, $date, $amount, $balance, $dateGuess['format'], $decimal),
        );
    }

    /**
     * A rough 0–1 confidence: 1.0 when the core roles matched by exact header name.
     *
     * @param  list<string>  $headers
     */
    public function confidence(array $headers, ColumnMapping $mapping): float
    {
        $score = 0.0;
        $score += $this->isExact($mapping->dateColumn, self::DATE) ? 0.4 : 0.2;

        if ($mapping->amountMode === 'debit_credit') {
            $score += $this->isExact($mapping->debitColumn, self::DEBIT) ? 0.3 : 0.15;
            $score += $this->isExact($mapping->creditColumn, self::CREDIT) ? 0.3 : 0.15;
        } else {
            $score += $this->isExact($mapping->amountColumn, array_merge(self::AMOUNT, self::CREDIT, self::DEBIT)) ? 0.5 : 0.25;
        }

        return min(1.0, round($score, 2));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $synonyms
     * @param  list<string>  $exclude
     */
    private function match(array $headers, array $synonyms, array $exclude = []): ?string
    {
        // Exact, normalized match wins over a substring match.
        foreach ($headers as $header) {
            if (in_array($header, $exclude, true)) {
                continue;
            }
            if (in_array($this->norm($header), $synonyms, true)) {
                return $header;
            }
        }

        foreach ($headers as $header) {
            if (in_array($header, $exclude, true)) {
                continue;
            }
            $norm = $this->norm($header);
            foreach ($synonyms as $synonym) {
                if ($norm !== '' && str_contains($norm, $synonym)) {
                    return $header;
                }
            }
        }

        return null;
    }

    /**
     * Unmatched text-ish columns become the description, in header order.
     *
     * @param  list<string>  $headers
     * @param  array<int, string>  $used
     * @return list<string>
     */
    private function descriptionColumns(array $headers, array $used): array
    {
        $explicit = $this->match($headers, self::DESCRIPTION, exclude: array_values($used));

        if ($explicit !== null) {
            return [$explicit];
        }

        return [];
    }

    private function isExact(?string $header, array $synonyms): bool
    {
        return $header !== null && in_array($this->norm($header), $synonyms, true);
    }

    /**
     * @param  list<array<string, ?string>>  $rows
     * @return list<string>
     */
    private function sample(array $rows, ?string $column, int $limit = 12): array
    {
        if ($column === null) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            $value = $row[$column] ?? null;
            if ($value !== null && trim($value) !== '') {
                $values[] = $value;
            }
            if (count($values) >= $limit) {
                break;
            }
        }

        return $values;
    }

    /**
     * Comma-decimal when a sampled value ends in ",dd" and has no '.' decimal.
     *
     * @param  list<array<string, ?string>>  $rows
     * @param  array<int, ?string>  $columns
     */
    private function detectDecimalSeparator(array $rows, array $columns): string
    {
        foreach (array_filter($columns) as $column) {
            foreach ($this->sample($rows, $column) as $value) {
                if (preg_match('/,\d{2}$/', trim($value)) === 1 && ! str_contains($value, '.')) {
                    return ',';
                }
            }
        }

        return '.';
    }

    /**
     * When a running-balance column is present, confirm the single amount column's
     * sign by checking that each balance step equals the (assumed money-in-positive)
     * amount. If the steps consistently match the negated amount, the column is
     * positive-for-withdrawals and we flip it.
     *
     * @param  list<array<string, ?string>>  $rows
     */
    private function detectFlipSign(array $rows, string $dateColumn, string $amountColumn, ?string $balanceColumn, string $dateFormat, string $decimal): bool
    {
        if ($balanceColumn === null) {
            return false;
        }

        $prevBalance = null;
        $asIs = 0;
        $flipped = 0;

        foreach ($rows as $row) {
            $amount = AmountParser::toCents($row[$amountColumn] ?? null, $decimal);
            $balance = AmountParser::toCents($row[$balanceColumn] ?? null, $decimal);

            if ($amount === null || $balance === null) {
                $prevBalance = $balance ?? $prevBalance;

                continue;
            }

            if ($prevBalance !== null) {
                $step = $balance - $prevBalance;
                if ($step === $amount) {
                    $asIs++;
                } elseif ($step === -$amount) {
                    $flipped++;
                }
            }

            $prevBalance = $balance;
        }

        return $flipped > $asIs;
    }

    private function norm(string $header): string
    {
        $lower = strtolower(trim($header));
        $collapsed = preg_replace('/[^a-z0-9]+/', ' ', $lower) ?? $lower;

        return trim($collapsed);
    }
}
