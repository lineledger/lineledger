<?php

namespace App\Support\Deadlines;

use Carbon\CarbonInterface;

/**
 * Dates on the Deadlines screens and emails, in the reader's language:
 * "Nov 30, 2026" in English, "30 nov. 2026" in French. Built on Carbon's
 * locale-aware ISO formats so day/month order follows the locale.
 */
final class DeadlineDates
{
    /** "Nov 30" / "30 nov." */
    public static function short(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return self::french() ? $date->locale('fr')->isoFormat('D MMM') : $date->locale(app()->getLocale())->isoFormat('MMM D');
    }

    /** "Mon, Nov 30" / "lun. 30 nov." */
    public static function shortWithWeekday(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return self::french()
            ? $date->locale('fr')->isoFormat('ddd D MMM')
            : $date->locale(app()->getLocale())->isoFormat('ddd, MMM D');
    }

    /** "Nov 30, 2026" / "30 nov. 2026" */
    public static function medium(?CarbonInterface $date): string
    {
        return $date === null ? '' : $date->locale(app()->getLocale())->isoFormat('ll');
    }

    /** "Mon, Nov 30, 2026" / "lun. 30 nov. 2026" */
    public static function mediumWithWeekday(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return self::french()
            ? $date->locale('fr')->isoFormat('ddd ll')
            : $date->locale(app()->getLocale())->isoFormat('ddd, ll');
    }

    /** "Monday, November 30, 2026" / "lundi 30 novembre 2026" */
    public static function long(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        return self::french()
            ? $date->locale('fr')->isoFormat('dddd LL')
            : $date->locale(app()->getLocale())->isoFormat('dddd, LL');
    }

    /** "Nov 2026" / "nov. 2026" */
    public static function monthYear(CarbonInterface $date): string
    {
        return $date->locale(app()->getLocale())->isoFormat('MMM YYYY');
    }

    /** "November 2026" / "novembre 2026" */
    public static function fullMonthYear(CarbonInterface $date): string
    {
        return $date->locale(app()->getLocale())->isoFormat('MMMM YYYY');
    }

    /** "Nov" / "nov." */
    public static function month(CarbonInterface $date): string
    {
        return $date->locale(app()->getLocale())->isoFormat('MMM');
    }

    /** "November" / "novembre" */
    public static function monthName(CarbonInterface $date): string
    {
        return $date->locale(app()->getLocale())->isoFormat('MMMM');
    }

    private static function french(): bool
    {
        return str_starts_with(app()->getLocale(), 'fr');
    }
}
