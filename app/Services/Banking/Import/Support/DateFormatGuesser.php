<?php

namespace App\Services\Banking\Import\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Infers the date format used by a column from a handful of sample values. The
 * day-first vs month-first ambiguity (e.g. 03/04/2026) is genuinely unresolvable
 * from values alone when every day is ≤ 12, so we report it for the user to confirm.
 */
final class DateFormatGuesser
{
    /** Candidate formats, most-specific / least-ambiguous first. */
    private const CANDIDATES = [
        'Y-m-d', 'Y/m/d', 'd M Y', 'd-M-Y', 'd M, Y', 'M d, Y', 'M d Y', 'j M Y', 'd F Y', 'F j, Y',
        'm/d/Y', 'd/m/Y', 'm-d-Y', 'd-m-Y', 'm/d/y', 'd/m/y', 'Y.m.d', 'd.m.Y', 'Ymd',
    ];

    /**
     * @param  list<string>  $samples
     * @return array{format: string, ambiguous: bool}
     */
    public static function guess(array $samples): array
    {
        $samples = array_values(array_filter(array_map(
            static fn ($s) => is_string($s) ? trim($s) : '',
            $samples,
        ), static fn (string $s) => $s !== ''));

        if ($samples === []) {
            return ['format' => 'Y-m-d', 'ambiguous' => false];
        }

        $best = 'Y-m-d';
        $bestHits = -1;

        foreach (self::CANDIDATES as $format) {
            $hits = 0;
            foreach ($samples as $sample) {
                if (self::parses($sample, $format)) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $best = $format;
            }
        }

        // Ambiguous when a US (month-first) and EU (day-first) reading both parse
        // every sample — true only when no value disambiguates with a part > 12.
        $ambiguous = self::allParse($samples, 'm/d/Y') && self::allParse($samples, 'd/m/Y')
            && ! self::anyHasFirstPartOver12($samples);

        return ['format' => $best, 'ambiguous' => $ambiguous];
    }

    private static function parses(string $value, string $format): bool
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);
        } catch (Throwable) {
            return false;
        }

        // Round-trip check rejects lenient over-matches (e.g. "13/01" as m/d).
        return $parsed !== false && $parsed->format($format) === $value;
    }

    /**
     * @param  list<string>  $samples
     */
    private static function allParse(array $samples, string $format): bool
    {
        foreach ($samples as $sample) {
            if (! self::parses($sample, $format)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $samples
     */
    private static function anyHasFirstPartOver12(array $samples): bool
    {
        foreach ($samples as $sample) {
            if (preg_match('#^(\d{1,2})[/\-.]#', $sample, $m) === 1 && (int) $m[1] > 12) {
                return true;
            }
        }

        return false;
    }
}
