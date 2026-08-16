<?php

namespace App\Services\Migration\Csv;

use RuntimeException;

/**
 * Parses uploaded CSV files into header-keyed row arrays.
 * Trims values, normalises empty strings to null, and validates required headers.
 */
class CsvParser
{
    /**
     * @param  list<string>  $requiredHeaders  Columns that must be present in the CSV header.
     * @param  list<string>  $optionalHeaders  Extra columns the importer knows about — included as null when absent. Avoids "undefined key" lookups.
     * @return list<array<string, ?string>>
     */
    public function parse(string $path, array $requiredHeaders, array $optionalHeaders = []): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file at: {$path}");
        }

        try {
            $headerRow = fgetcsv($handle);

            if ($headerRow === false) {
                throw new RuntimeException('CSV file is empty.');
            }

            $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headerRow);

            $missing = array_diff(
                array_map(fn ($h) => strtolower($h), $requiredHeaders),
                $headers,
            );

            if ($missing !== []) {
                throw new RuntimeException('CSV is missing required column(s): '.implode(', ', $missing));
            }

            $allExpected = array_unique(array_merge(
                array_map(fn ($h) => strtolower($h), $requiredHeaders),
                array_map(fn ($h) => strtolower($h), $optionalHeaders),
            ));

            $rows = [];

            while (($cells = fgetcsv($handle)) !== false) {
                if ($cells === [null] || $cells === false) {
                    continue;
                }

                $row = array_fill_keys($allExpected, null);

                foreach ($headers as $i => $header) {
                    $value = $cells[$i] ?? null;
                    $trimmed = $value === null ? null : trim((string) $value);
                    $row[$header] = ($trimmed === '' ? null : $trimmed);
                }

                $isEmpty = true;
                foreach ($row as $v) {
                    if ($v !== null) {
                        $isEmpty = false;
                        break;
                    }
                }

                if (! $isEmpty) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse "123.45" or "123,45" or "1,234.56" into integer cents.
     * Returns null if the value cannot be parsed.
     */
    public static function parseCents(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $clean = str_replace([',', '$', ' '], ['', '', ''], $value);

        if (! is_numeric($clean)) {
            return null;
        }

        return (int) round(((float) $clean) * 100);
    }

    public static function parseBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 't'], true);
    }

    /**
     * Render integer cents as a "1,234.56" string for preview tables.
     */
    public static function centsLabel(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);
        $whole = number_format(intdiv($abs, 100));
        $frac = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.'.'.$frac;
    }
}
