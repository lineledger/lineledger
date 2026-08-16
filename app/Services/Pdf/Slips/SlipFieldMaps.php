<?php

namespace App\Services\Pdf\Slips;

/**
 * Year-keyed coordinate maps for drawing slip values onto the official
 * template PDFs. Coordinates are PDF POINTS with a top-left origin, extracted
 * from the AcroForm field rectangles of the official CRA fillable PDFs
 * (mutool: page widgets → rects), so values land exactly inside the form's
 * boxes. Each map carries:
 *
 *   offsets — the per-impression vertical shift (the T4 sheet prints two
 *             identical employee copies per page);
 *   fields  — key => [x, y(top), w, h, align, multiline?].
 *
 * Maps are per-year by policy even when coordinates repeat (2024 == 2025 for
 * the T4): a new tax year must be consciously verified, never inherited.
 */
final class SlipFieldMaps
{
    /**
     * @return array{offsets: list<float>, fields: array<string, array{x: float, y: float, w: float, h: float, align: string, multiline?: bool}>}|null
     */
    public static function for(string $type, int $year): ?array
    {
        return match (true) {
            $type === SlipTemplateRegistry::T4 && in_array($year, [2024, 2025], true) => self::t4(),
            default => null,
        };
    }

    /**
     * The CRA T4 sheet (t4-fill-24e/25e, flattened): letter portrait, two
     * employee-copy slips per page, second slip 387.5pt below the first.
     *
     * @return array{offsets: list<float>, fields: array<string, array{x: float, y: float, w: float, h: float, align: string, multiline?: bool}>}
     */
    private static function t4(): array
    {
        $f = fn (float $x, float $y, float $w, float $h, string $align = 'R'): array => compact('x', 'y', 'w', 'h', 'align');

        return [
            'offsets' => [0.0, 387.5],
            'fields' => [
                'employer_name' => $f(52.0, 36.0, 195.0, 54.0, 'L') + ['multiline' => true],
                'year' => $f(294.8, 37.4, 54.8, 15.2, 'C'),
                'sin' => $f(59.0, 146.8, 119.6, 15.1, 'L'),
                'box10' => $f(291.2, 123.0, 26.0, 15.2, 'C'),
                'box14' => $f(352.4, 74.2, 81.7, 15.1),
                'box22' => $f(474.8, 74.2, 92.6, 15.2),
                'box16' => $f(352.4, 108.0, 81.8, 15.1),
                'box17' => $f(485.5, 108.2, 81.8, 15.1),
                'box16a' => $f(352.4, 145.5, 81.8, 15.1),
                'box17a' => $f(485.5, 145.5, 81.8, 15.1),
                'box24' => $f(352.6, 177.9, 81.7, 15.1),
                'box26' => $f(485.6, 177.6, 81.8, 15.1),
                'box18' => $f(352.4, 209.5, 81.8, 15.1),
                'box44' => $f(485.6, 209.5, 81.8, 15.1),
                'box20' => $f(352.4, 241.8, 81.8, 15.1),
                'box46' => $f(485.6, 241.8, 81.8, 15.1),
                'box52' => $f(352.4, 274.1, 81.8, 15.1),
                'box55' => $f(352.4, 307.2, 81.8, 15.1),
                'box56' => $f(485.6, 307.2, 81.8, 15.1),
                'last_name' => $f(59.0, 206.2, 136.8, 15.2, 'L'),
                'first_name' => $f(198.7, 206.2, 93.6, 15.2, 'L'),
                'address' => $f(50.0, 245.0, 275.0, 60.0, 'L') + ['multiline' => true],
                'other1_code' => $f(111.2, 339.3, 26.0, 15.1, 'C'),
                'other1_amount' => $f(147.2, 339.3, 92.2, 15.1),
                'other2_code' => $f(275.0, 339.3, 26.0, 15.1, 'C'),
                'other2_amount' => $f(311.0, 339.3, 92.2, 15.1),
                'other3_code' => $f(438.8, 339.3, 26.0, 15.1, 'C'),
                'other3_amount' => $f(474.8, 339.3, 92.6, 15.1),
                'other4_code' => $f(111.2, 366.5, 26.0, 15.1, 'C'),
                'other4_amount' => $f(147.2, 366.5, 92.2, 15.1),
                'other5_code' => $f(275.0, 366.5, 26.0, 15.1, 'C'),
                'other5_amount' => $f(311.0, 366.5, 92.2, 15.1),
                'other6_code' => $f(438.8, 366.5, 26.0, 15.1, 'C'),
                'other6_amount' => $f(474.8, 366.5, 92.6, 15.1),
            ],
        ];
    }
}
