<?php

namespace App\Support\Reporting;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The "Generated …" stamp every exported report carries, and the general
 * company-local display conversion it's built on.
 *
 * Reports are read by the people keeping the books, so the stamp belongs in the
 * company's own timezone — the same one `Company::currentDateTime()` uses to
 * default transaction dates. Exporting at 2:34pm Pacific must not print
 * "21:34". A PDF layout that only knows its report group (multi-company), and
 * anything rendered off the request, falls back to the company bound for the
 * render (ReportRenderer and the tenant middleware both bind one) and finally
 * to the app timezone.
 */
final class GeneratedAt
{
    public static function for(?Company $company = null): CarbonImmutable
    {
        $company = self::resolve($company);

        return $company instanceof Company
            ? $company->currentDateTime()
            : CarbonImmutable::now();
    }

    /** The stamp as printed on a report: 'Y-m-d H:i', company-local. */
    public static function label(?Company $company = null): string
    {
        return self::for($company)->format('Y-m-d H:i');
    }

    /**
     * Converts a stored (UTC) instant to a company's timezone for display.
     * Storage and business-logic dates stay in UTC / Company::currentDateTime();
     * this is display-only and never round-trips back to the database.
     */
    public static function at(CarbonInterface $value, ?Company $company = null): CarbonImmutable
    {
        $company = self::resolve($company);

        return CarbonImmutable::parse($value)->setTimezone($company?->timezone ?: 'UTC');
    }

    private static function resolve(?Company $company): ?Company
    {
        return $company ?? (app()->bound('current_company') ? app('current_company') : null);
    }
}
