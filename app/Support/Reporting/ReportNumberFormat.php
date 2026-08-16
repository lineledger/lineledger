<?php

namespace App\Support\Reporting;

/**
 * Display preferences for money figures on the core statements (QuickBooks
 * "Negative numbers" / "Show numbers" options): how negatives render
 * ('minus', 'paren', 'red') and what unit precision is shown on screen and
 * PDF ('cents' = 2 decimals, 'whole' = nearest dollar, 'thousands').
 *
 * Reports compute in integer cents throughout; this object only changes how
 * a figure is *displayed*. Rounded columns ('whole'/'thousands') may not
 * visually sum — totals are computed in cents and rounded independently,
 * exactly as QBO behaves.
 */
final class ReportNumberFormat
{
    private const NEGATIVE_STYLES = ['minus', 'paren', 'red'];

    private const UNITS = ['cents', 'whole', 'thousands'];

    public function __construct(
        public readonly string $negativeStyle = 'minus',
        public readonly string $units = 'cents',
    ) {}

    /** Invalid values (hand-edited URLs, stale memorized settings) fall back to defaults. */
    public static function fromProps(string $negativeStyle, string $units): self
    {
        return new self(
            in_array($negativeStyle, self::NEGATIVE_STYLES, true) ? $negativeStyle : 'minus',
            in_array($units, self::UNITS, true) ? $units : 'cents',
        );
    }

    /**
     * @return array<string, string> value => label
     */
    public static function negativeOptions(): array
    {
        return [
            'minus' => '-1,234.56',
            'paren' => '(1,234.56)',
            'red' => '-1,234.56 in red',
        ];
    }

    /**
     * @return array<string, string> value => label
     */
    public static function unitsOptions(): array
    {
        return [
            'cents' => '1,234.56',
            'whole' => '1,235',
            'thousands' => 'thousands',
        ];
    }

    /** Render integer cents per the active units and negative style. */
    public function format(int $cents): string
    {
        [$value, $decimals] = match ($this->units) {
            'whole' => [(int) round($cents / 100), 0],
            'thousands' => [(int) round($cents / 100_000), 0],
            default => [$cents / 100, 2],
        };

        $formatted = number_format(abs($value), $decimals);

        if ($value < 0) {
            return $this->negativeStyle === 'paren' ? '('.$formatted.')' : '-'.$formatted;
        }

        return $formatted;
    }

    /** Screen class for an amount cell — red negatives only under the 'red' style. */
    public function cssClass(int $cents): string
    {
        return $this->negativeStyle === 'red' && $cents < 0 ? 'text-red-600' : '';
    }

    /** PDF equivalent of cssClass() — the print layout's `.neg` rule. */
    public function pdfClass(int $cents): string
    {
        return $this->negativeStyle === 'red' && $cents < 0 ? 'neg' : '';
    }

    /** Appended to report subtitles so a rounded view is unambiguous. */
    public function unitsSuffix(): ?string
    {
        return $this->units === 'thousands' ? ' · $ in thousands' : null;
    }

    /**
     * Excel number format honouring the negative style only. Spreadsheets keep
     * full 2-decimal precision regardless of the on-screen units — 'units' is a
     * screen/PDF display preference (see HasReportNumberFormat).
     */
    public function xlsxMoneyFormat(): string
    {
        return match ($this->negativeStyle) {
            'paren' => '#,##0.00;(#,##0.00)',
            'red' => '#,##0.00;[Red]-#,##0.00',
            default => '#,##0.00;-#,##0.00',
        };
    }
}
