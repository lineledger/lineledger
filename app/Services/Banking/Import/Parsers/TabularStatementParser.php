<?php

namespace App\Services\Banking\Import\Parsers;

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\Contracts\StatementParser;
use App\Services\Banking\Import\DTO\ColumnMapping;
use App\Services\Banking\Import\DTO\ParsedStatement;
use App\Services\Banking\Import\DTO\ParsedTransaction;
use App\Services\Banking\Import\DTO\ParseOptions;
use App\Services\Banking\Import\DTO\StatementProbe;
use App\Services\Banking\Import\Mapping\ColumnMappingDetector;
use App\Services\Banking\Import\Support\AmountParser;
use App\Services\Banking\Import\Support\RawTabularReader;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses CSV and Excel statements. The layout varies per bank, so a {@see ColumnMapping}
 * (auto-detected, loaded from a saved profile, or confirmed in the wizard) drives a fully
 * deterministic pass over every row — the AI layer, when enabled, only ever supplies the
 * mapping, never transcribes data.
 */
final class TabularStatementParser implements StatementParser
{
    private const SNIFF_ROWS = 25;

    public function __construct(
        private readonly RawTabularReader $reader,
        private readonly ColumnMappingDetector $detector,
    ) {}

    public function supports(BankStatementFormat $format): bool
    {
        return $format === BankStatementFormat::Csv || $format === BankStatementFormat::Xlsx;
    }

    public function sniff(string $absolutePath, BankStatementFormat $format): StatementProbe
    {
        ['headers' => $headers, 'rows' => $rows] = $this->reader->read($absolutePath, $format, self::SNIFF_ROWS);

        $mapping = $headers === [] ? null : $this->detector->detect($headers, $rows);

        return new StatementProbe(
            format: $format,
            needsMapping: $mapping === null,
            headerSignature: RawTabularReader::headerSignature($headers),
            headers: $headers,
            sampleRows: array_slice($rows, 0, 10),
            detectedMapping: $mapping,
            confidence: $mapping !== null ? $this->detector->confidence($headers, $mapping) : null,
        );
    }

    public function parse(string $absolutePath, BankStatementFormat $format, ParseOptions $options): ParsedStatement
    {
        ['headers' => $headers, 'rows' => $rows] = $this->reader->read($absolutePath, $format);

        $mapping = $options->mapping
            ?? ($headers === [] ? null : $this->detector->detect($headers, $rows));

        if ($mapping === null || ! $mapping->isComplete()) {
            throw new \RuntimeException('A column mapping is required to parse this statement.');
        }

        $transactions = [];
        $skipped = 0;
        $beginDate = null;
        $endDate = null;
        $endBalance = null;

        foreach ($rows as $row) {
            $txn = $this->rowToTransaction($row, $mapping);

            if ($txn === null) {
                $skipped++;

                continue;
            }

            $transactions[] = $txn;

            if ($beginDate === null || $txn->date->lessThan($beginDate)) {
                $beginDate = $txn->date;
            }
            // Latest date wins for the closing balance; ties resolve to file order.
            if ($endDate === null || $txn->date->greaterThanOrEqualTo($endDate)) {
                $endDate = $txn->date;
                $endBalance = $txn->balanceCents ?? $endBalance;
            }
        }

        return new ParsedStatement(
            transactions: $transactions,
            beginDate: $beginDate,
            endDate: $endDate,
            endBalanceCents: $endBalance,
            meta: [
                'parser' => 'tabular',
                'format' => $format->value,
                'date_format' => $mapping->dateFormat,
                'decimal_separator' => $mapping->decimalSeparator,
                'amount_mode' => $mapping->amountMode,
                'flip_sign' => $mapping->flipSign,
                'rows_skipped' => $skipped,
                'ai_used' => false,
            ],
        );
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function rowToTransaction(array $row, ColumnMapping $mapping): ?ParsedTransaction
    {
        $date = $this->parseDate($row[$mapping->dateColumn] ?? null, $mapping->dateFormat);

        if ($date === null) {
            return null; // header/footer/summary junk — skip silently
        }

        $amount = $this->rowAmount($row, $mapping);

        if ($amount === null) {
            return null;
        }

        $description = $this->rowDescription($row, $mapping);

        $check = $mapping->checkNumberColumn !== null ? ($row[$mapping->checkNumberColumn] ?? null) : null;
        $balance = $mapping->balanceColumn !== null
            ? AmountParser::toCents($row[$mapping->balanceColumn] ?? null, $mapping->decimalSeparator)
            : null;

        return new ParsedTransaction(
            date: $date,
            amountCents: $amount,
            description: $description,
            checkNumber: $check !== null ? trim($check) : null,
            balanceCents: $balance,
            raw: $row,
        );
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function rowAmount(array $row, ColumnMapping $mapping): ?int
    {
        if ($mapping->amountMode === 'debit_credit') {
            $out = AmountParser::toCents($row[$mapping->debitColumn] ?? null, $mapping->decimalSeparator);
            $in = AmountParser::toCents($row[$mapping->creditColumn] ?? null, $mapping->decimalSeparator);

            if ($out === null && $in === null) {
                return null;
            }

            // Statement "credit"/"deposit" = money in = a debit to the asset bank (+).
            return abs($in ?? 0) - abs($out ?? 0);
        }

        $amount = AmountParser::toCents($row[$mapping->amountColumn] ?? null, $mapping->decimalSeparator);

        if ($amount === null) {
            return null;
        }

        return $mapping->flipSign ? -$amount : $amount;
    }

    /**
     * @param  array<string, ?string>  $row
     */
    private function rowDescription(array $row, ColumnMapping $mapping): string
    {
        $parts = [];
        foreach ($mapping->descriptionColumns as $column) {
            $value = $row[$column] ?? null;
            if ($value !== null && trim($value) !== '') {
                $parts[] = trim($value);
            }
        }

        return implode(' — ', $parts);
    }

    private function parseDate(?string $value, string $format): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
            if ($parsed !== false) {
                return $parsed;
            }
        } catch (Throwable) {
            // fall through to the lenient attempt
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
