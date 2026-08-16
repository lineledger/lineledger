<?php

namespace App\Services\Banking\Import\Support;

use App\Enums\BankStatementFormat;
use DateTimeInterface;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads a CSV or Excel file into a header row plus header-keyed data rows, without
 * needing to know the columns up front (bank exports vary wildly). CSV delimiters
 * are sniffed (comma / semicolon / tab / pipe); Excel is read via openspout. Rows
 * with more cells than headers keep the extras under "column_{i}" so nothing is lost.
 */
final class RawTabularReader
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, ?string>>}
     */
    public function read(string $path, BankStatementFormat $format, int $maxRows = 0): array
    {
        $matrix = $format === BankStatementFormat::Xlsx
            ? $this->readXlsx($path, $maxRows)
            : $this->readCsv($path, $maxRows);

        if ($matrix === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headerCells = array_shift($matrix);
        $headers = $this->normaliseHeaders($headerCells);

        $rows = [];
        foreach ($matrix as $cells) {
            $row = [];
            foreach ($headers as $i => $header) {
                $value = $cells[$i] ?? null;
                $row[$header] = ($value === null || $value === '') ? null : $value;
            }

            // Skip fully empty rows (trailing blanks, separator lines).
            if (array_filter($row, static fn ($v) => $v !== null) !== []) {
                $rows[] = $row;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * A stable signature of a file's columns, used to auto-match a saved profile.
     *
     * @param  list<string>  $headers
     */
    public static function headerSignature(array $headers): string
    {
        $normalized = array_map(static fn (string $h) => strtolower(trim($h)), $headers);

        return sha1(implode('|', $normalized));
    }

    /**
     * Give empty/duplicate header cells a stable synthetic name so every column is
     * addressable and assoc keys never collide.
     *
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function normaliseHeaders(array $cells): array
    {
        $headers = [];
        $seen = [];

        foreach ($cells as $i => $cell) {
            $label = trim((string) $cell);

            if ($label === '') {
                $label = 'column_'.($i + 1);
            }

            if (isset($seen[$label])) {
                $seen[$label]++;
                $label .= ' ('.$seen[$label].')';
            } else {
                $seen[$label] = 1;
            }

            $headers[] = $label;
        }

        return $headers;
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path, int $maxRows): array
    {
        $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->sniffDelimiter($path)));
        $reader->open($path);

        $matrix = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $matrix[] = array_map($this->stringifyCell(...), $row->toArray());

                    if ($maxRows > 0 && count($matrix) > $maxRows) {
                        break 2;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return $matrix;
    }

    /**
     * @return list<list<string>>
     */
    private function readXlsx(string $path, int $maxRows): array
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $matrix = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $matrix[] = array_map($this->stringifyCell(...), $row->toArray());

                    if ($maxRows > 0 && count($matrix) > $maxRows) {
                        break 2;
                    }
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return $matrix;
    }

    /**
     * openspout hands back native types (DateTime, float, int, bool). Flatten them to
     * the string forms our amount/date parsers expect, keeping money to 4 dp so cents
     * survive while ISO dates stay parseable.
     */
    private function stringifyCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
        }

        return trim((string) $value);
    }

    /**
     * Pick the delimiter that yields the most columns on the first non-empty line.
     */
    private function sniffDelimiter(string $path): string
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open file at: {$path}");
        }

        $line = '';
        try {
            while (($candidate = fgets($handle)) !== false) {
                if (trim($candidate) !== '') {
                    $line = $candidate;
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $delimiter) {
            $count = substr_count($line, $delimiter);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $delimiter;
            }
        }

        return $best;
    }
}
