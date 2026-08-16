<?php

namespace App\Services\Reporting;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream a CSV download. Rows are written as the response streams,
 * which keeps memory flat for large reports.
 */
class CsvExporter
{
    /**
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     * @param  array<int, string>  $headers
     */
    public function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, array_map([self::class, 'neutralize'], $headers));

            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'neutralize'], $row));
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Neutralize CSV formula injection (CWE-1236). CSV cells carry no type, so
     * a spreadsheet auto-evaluates any cell beginning with = + @ (or a tab /
     * carriage return). Prefix those with a single quote so they're treated as
     * text. Genuine numbers (including negatives like "-5.00") are left intact.
     */
    public static function neutralize(string|int|float|null $value): string|int|float|null
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Format cents as a 2-decimal string suitable for CSV cells.
     */
    public static function cents(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);

        return ($negative ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
