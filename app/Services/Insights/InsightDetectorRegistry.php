<?php

namespace App\Services\Insights;

use App\Enums\InsightCategory;
use App\Models\Company;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\AverageDaysToPaidDetector;
use App\Services\Insights\Detectors\BillsDueSoonDetector;
use App\Services\Insights\Detectors\CashflowRunwayDetector;
use App\Services\Insights\Detectors\CashflowShortfallDetector;
use App\Services\Insights\Detectors\CashTrendDetector;
use App\Services\Insights\Detectors\DonationsYtdDetector;
use App\Services\Insights\Detectors\ExpenseCategoryShiftDetector;
use App\Services\Insights\Detectors\OverdueReceivablesDetector;
use App\Services\Insights\Detectors\PayrollRemittanceDueDetector;
use App\Services\Insights\Detectors\ReceivablesConcentrationDetector;
use App\Services\Insights\Detectors\RecordRevenueMonthDetector;
use App\Services\Insights\Detectors\RecurringTemplatePausedDetector;
use App\Services\Insights\Detectors\RevenuePaceFiscalYtdDetector;
use App\Services\Insights\Detectors\SalesTaxSetAsideDetector;
use App\Services\Insights\Detectors\SameMonthLastYearDetector;
use App\Services\Insights\Detectors\StaleDraftInvoicesDetector;
use App\Services\Insights\Detectors\UnmatchedBankLinesDetector;

/**
 * The detector catalogue. Append new detectors here — keys are stored on
 * daily_insights.type, so a shipped detector's key must never change (its
 * class may move, the key may not).
 */
final class InsightDetectorRegistry
{
    /**
     * @return list<class-string<InsightDetector>>
     */
    public static function detectors(): array
    {
        return [
            OverdueReceivablesDetector::class,
            BillsDueSoonDetector::class,
            UnmatchedBankLinesDetector::class,
            PayrollRemittanceDueDetector::class,
            SalesTaxSetAsideDetector::class,
            RecurringTemplatePausedDetector::class,
            StaleDraftInvoicesDetector::class,
            RecordRevenueMonthDetector::class,
            RevenuePaceFiscalYtdDetector::class,
            AverageDaysToPaidDetector::class,
            ReceivablesConcentrationDetector::class,
            ExpenseCategoryShiftDetector::class,
            CashTrendDetector::class,
            SameMonthLastYearDetector::class,
            DonationsYtdDetector::class,
            CashflowRunwayDetector::class,
            CashflowShortfallDetector::class,
        ];
    }

    /**
     * Fresh key-indexed instances from the container. Resolve once per
     * render when looking up many rows (history page) — instances are not
     * cached here, so nothing stale survives the request or a test's app.
     *
     * @return array<string, InsightDetector>
     */
    public static function instances(): array
    {
        $instances = [];

        foreach (self::detectors() as $class) {
            $detector = app($class);
            $instances[$detector->key()] = $detector;
        }

        return $instances;
    }

    public static function detectorFor(string $key): ?InsightDetector
    {
        return self::instances()[$key] ?? null;
    }

    /** Null for keys no longer in the catalogue (a retired detector's old rows). */
    public static function categoryFor(string $key): ?InsightCategory
    {
        return self::detectorFor($key)?->category();
    }

    /**
     * @return array{route: string, label: string}|null
     */
    public static function ctaFor(string $key, Company $company): ?array
    {
        return self::detectorFor($key)?->cta($company);
    }
}
