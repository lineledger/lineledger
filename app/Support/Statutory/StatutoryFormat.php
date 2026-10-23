<?php

namespace App\Support\Statutory;

use Carbon\CarbonInterface;

/**
 * Presentation helpers for statutory accounts: whole pounds with thousands
 * separators, negatives in brackets and nil as a dash, and long-form dates.
 */
final class StatutoryFormat
{
    public static function amount(int $pounds, bool $dashForZero = true): string
    {
        if ($pounds === 0) {
            return $dashForZero ? '-' : '0';
        }

        $formatted = number_format(abs($pounds));

        return $pounds < 0 ? '('.$formatted.')' : $formatted;
    }

    /**
     * An amount shown as a deduction — "(13,755)" — whatever its stored sign.
     */
    public static function deduction(int $pounds): string
    {
        return self::amount(-$pounds);
    }

    public static function date(CarbonInterface $date): string
    {
        return $date->format('jS F Y');
    }

    /**
     * The date with its ordinal raised, for HTML output: 5<sup>th</sup> April 2022.
     */
    public static function dateHtml(CarbonInterface $date): string
    {
        return $date->format('j').'<sup>'.$date->format('S').'</sup> '.$date->format('F Y');
    }

    public static function year(CarbonInterface $date): string
    {
        return $date->format('Y');
    }
}
