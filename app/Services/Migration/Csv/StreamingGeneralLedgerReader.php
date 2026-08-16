<?php

namespace App\Services\Migration\Csv;

use App\Enums\AccountSubtype;
use Generator;
use RuntimeException;

/**
 * Streams a QuickBooks Desktop transaction export into normalised, balanced
 * transaction blocks — one block per source transaction — without loading the
 * whole file into memory.
 *
 * Two source formats are supported:
 *
 *  - 'csv': the QBD "Journal" report exported to CSV. Every detail row carries
 *    a transaction id ("Trans #"), Account, and Debit/Credit (or a signed
 *    Amount). A new transaction begins on each row that has a non-blank Trans #;
 *    continuation (split) rows have a blank Trans # and inherit the header.
 *
 *  - 'iif': the native Intuit Interchange Format. Transactions are !TRNS / !SPL
 *    blocks terminated by ENDTRNS, with signed AMOUNT (positive = debit).
 *
 * Each yielded block has the shape:
 *   array{
 *     key: string, type: ?string, num: ?string, date: ?string,
 *     name: ?string, memo: ?string,
 *     lines: list<array{account: string, debit_cents: int, credit_cents: int, name: ?string, memo: ?string}>
 *   }
 */
class StreamingGeneralLedgerReader
{
    public const FORMAT_CSV = 'csv';

    public const FORMAT_IIF = 'iif';

    /**
     * Yield normalised transaction blocks from the given file.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function read(string $path, string $format): Generator
    {
        return match ($format) {
            self::FORMAT_CSV => $this->readCsv($path),
            self::FORMAT_IIF => $this->readIif($path),
            default => throw new RuntimeException("Unsupported source format '{$format}'."),
        };
    }

    /**
     * Parse IIF !ACCNT records into a [lowercased account name => AccountSubtype] map,
     * used to give auto-created accounts a sensible type. CSV exports carry no type info.
     *
     * @return array<string, AccountSubtype>
     */
    public function accountTypes(string $path, string $format): array
    {
        if ($format !== self::FORMAT_IIF) {
            return [];
        }

        $handle = $this->open($path);
        $columns = null;
        $map = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $cells = $this->iifCells($line);

                if ($cells === []) {
                    continue;
                }

                $tag = $cells[0];

                if ($tag === '!ACCNT') {
                    $columns = $this->columnPositions($cells);

                    continue;
                }

                if ($tag === 'ACCNT' && $columns !== null) {
                    $name = $this->cell($cells, $columns, 'NAME');
                    $type = $this->cell($cells, $columns, 'ACCNTTYPE');

                    if ($name !== null && $type !== null) {
                        $map[mb_strtolower($name)] = self::subtypeForIifType($type);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $map;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function readCsv(string $path): Generator
    {
        $handle = $this->open($path);

        try {
            // QuickBooks prefixes exports with title rows (report name, basis, date range)
            // before the real column header. Scan past them to the first row that looks
            // like a header — i.e. names an Account column and a Debit/Credit or Amount column.
            $cols = [];
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }

                $candidate = $this->csvColumnMap(array_map(fn ($h) => mb_strtolower(trim((string) $h)), $row));

                if (isset($candidate['account']) && (isset($candidate['debit']) || isset($candidate['credit']) || isset($candidate['amount']))) {
                    $cols = $candidate;
                    break;
                }
            }

            if ($cols === []) {
                throw new RuntimeException('Could not find a column header row. Export the QuickBooks "Journal" report to CSV — it has Account and Debit/Credit columns. (The General Ledger report is organised by account and cannot be imported directly.)');
            }

            $hasDebitCredit = isset($cols['debit']) && isset($cols['credit']);
            $hasTrans = isset($cols['trans']);

            $current = null;

            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                if ($cells === [null] || $cells === false) {
                    continue;
                }

                $get = fn (string $key): ?string => $this->csvValue($cells, $cols, $key);

                $transNo = $get('trans');
                $type = $get('type');
                $date = $get('date');
                $account = $get('account');

                // A new transaction begins on a row carrying a Trans # (preferred), or — when
                // the report has no Trans # column — on the first row of a block, which carries
                // the Type/Date while split rows beneath it leave those blank.
                $startsTransaction = $hasTrans
                    ? ($transNo !== null && $transNo !== '')
                    : ($type !== null || $date !== null);

                if ($startsTransaction) {
                    if ($current !== null) {
                        yield $this->finalizeBlock($current);
                    }

                    $current = [
                        'key' => $transNo ?? '',
                        'type' => $type,
                        'num' => $get('num'),
                        'date' => $date,
                        'name' => $get('name'),
                        'memo' => $get('memo'),
                        'lines' => [],
                    ];
                }

                if ($current === null) {
                    // Stray continuation row before any transaction header — skip defensively.
                    continue;
                }

                if ($account === null || $account === '') {
                    continue;
                }

                [$debit, $credit] = $hasDebitCredit
                    ? [CsvParser::parseCents($get('debit')) ?? 0, CsvParser::parseCents($get('credit')) ?? 0]
                    : $this->splitSignedAmount(CsvParser::parseCents($get('amount')) ?? 0);

                $current['lines'][] = [
                    'account' => $account,
                    'debit_cents' => $debit,
                    'credit_cents' => $credit,
                    'name' => $get('name'),
                    'memo' => $get('memo'),
                ];
            }

            if ($current !== null) {
                yield $this->finalizeBlock($current);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    protected function readIif(string $path): Generator
    {
        $handle = $this->open($path);
        $trnsCols = null;
        $splCols = null;
        $current = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $cells = $this->iifCells($line);

                if ($cells === []) {
                    continue;
                }

                switch ($cells[0]) {
                    case '!TRNS':
                        $trnsCols = $this->columnPositions($cells);
                        break;
                    case '!SPL':
                        $splCols = $this->columnPositions($cells);
                        break;
                    case 'TRNS':
                        if ($trnsCols === null) {
                            throw new RuntimeException('IIF file has a TRNS row before its !TRNS header.');
                        }
                        $current = [
                            'key' => $this->cell($cells, $trnsCols, 'TRNSID') ?? '',
                            'type' => $this->cell($cells, $trnsCols, 'TRNSTYPE'),
                            'num' => $this->cell($cells, $trnsCols, 'DOCNUM'),
                            'date' => $this->cell($cells, $trnsCols, 'DATE'),
                            'name' => $this->cell($cells, $trnsCols, 'NAME'),
                            'memo' => $this->cell($cells, $trnsCols, 'MEMO'),
                            'lines' => [],
                        ];
                        $this->appendIifLine($current, $cells, $trnsCols);
                        break;
                    case 'SPL':
                        if ($current !== null && $splCols !== null) {
                            $this->appendIifLine($current, $cells, $splCols);
                        }
                        break;
                    case 'ENDTRNS':
                        if ($current !== null) {
                            yield $this->finalizeBlock($current);
                            $current = null;
                        }
                        break;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $cells
     * @param  array<string, int>  $cols
     */
    protected function appendIifLine(array &$block, array $cells, array $cols): void
    {
        $account = $this->cell($cells, $cols, 'ACCNT');

        if ($account === null || $account === '') {
            return;
        }

        [$debit, $credit] = $this->splitSignedAmount(CsvParser::parseCents($this->cell($cells, $cols, 'AMOUNT')) ?? 0);

        $block['lines'][] = [
            'account' => $account,
            'debit_cents' => $debit,
            'credit_cents' => $credit,
            'name' => $this->cell($cells, $cols, 'NAME'),
            'memo' => $this->cell($cells, $cols, 'MEMO'),
        ];
    }

    /**
     * Normalise an empty key to a stable fallback so grouping/idempotency still work.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    protected function finalizeBlock(array $block): array
    {
        if ($block['key'] === '' || $block['key'] === null) {
            $block['key'] = ($block['type'] ?? 'TXN').'|'.($block['num'] ?? '').'|'.($block['date'] ?? '');
        }

        return $block;
    }

    /**
     * Positive cents become a debit, negative a credit (QBD/IIF signed-amount convention).
     *
     * @return array{0:int, 1:int}
     */
    protected function splitSignedAmount(int $cents): array
    {
        return $cents >= 0 ? [$cents, 0] : [0, abs($cents)];
    }

    /**
     * Map normalised CSV headers to their column index for the fields we understand.
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    protected function csvColumnMap(array $headers): array
    {
        $cols = [];

        foreach ($headers as $i => $header) {
            $key = match (true) {
                str_contains($header, 'trans') && (str_contains($header, '#') || str_contains($header, 'no') || str_contains($header, 'num')) => 'trans',
                $header === 'type' => 'type',
                $header === 'date' => 'date',
                $header === 'num' || $header === 'number' || $header === 'doc num' => 'num',
                $header === 'name' => 'name',
                $header === 'memo' || $header === 'description' || $header === 'memo/description' => 'memo',
                $header === 'account' => 'account',
                $header === 'debit' => 'debit',
                $header === 'credit' => 'credit',
                $header === 'amount' => 'amount',
                default => null,
            };

            if ($key !== null && ! isset($cols[$key])) {
                $cols[$key] = $i;
            }
        }

        return $cols;
    }

    /**
     * @param  list<?string>  $cells
     * @param  array<string, int>  $cols
     */
    protected function csvValue(array $cells, array $cols, string $key): ?string
    {
        if (! isset($cols[$key])) {
            return null;
        }

        return $this->normalize($cells[$cols[$key]] ?? null);
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, int>
     */
    protected function columnPositions(array $cells): array
    {
        $cols = [];

        // cells[0] is the record tag (e.g. !TRNS); columns follow.
        foreach ($cells as $i => $cell) {
            if ($i === 0) {
                continue;
            }
            $cols[strtoupper(trim($cell))] = $i;
        }

        return $cols;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $cols
     */
    protected function cell(array $cells, array $cols, string $key): ?string
    {
        if (! isset($cols[$key])) {
            return null;
        }

        return $this->normalize($cells[$cols[$key]] ?? null);
    }

    /**
     * Trim a cell and guarantee valid UTF-8. QuickBooks Desktop exports are
     * usually Windows-1252 (smart quotes, em-dashes, "·", accented names), which
     * would otherwise break JSON encoding of the preview. Returns null when empty.
     */
    protected function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        // Strip a leading UTF-8 BOM (the exact 3-byte sequence — not via ltrim, which
        // would shave individual 0xEF/0xBB/0xBF bytes off a legitimate multibyte char).
        if (str_starts_with($value, "\u{FEFF}")) {
            $value = substr($value, 3);
        }

        // Guarantee valid UTF-8 so nothing downstream (JSON, the response) can choke.
        $trimmed = trim(mb_scrub($value, 'UTF-8'));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Split a tab-delimited IIF line into trimmed-of-quotes cells.
     *
     * @return list<string>
     */
    protected function iifCells(string $line): array
    {
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            return [];
        }

        return array_map(
            fn (string $cell): string => trim($cell, '"'),
            explode("\t", $line),
        );
    }

    /**
     * @return resource
     */
    protected function open(string $path)
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open file at: {$path}");
        }

        return $handle;
    }

    /**
     * Map an IIF ACCNTTYPE token to the closest LineLedger account subtype.
     */
    public static function subtypeForIifType(string $type): AccountSubtype
    {
        return AccountSubtype::fromQuickBooksType($type);
    }
}
