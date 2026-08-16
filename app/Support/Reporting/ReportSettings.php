<?php

namespace App\Support\Reporting;

/**
 * The canonical set of report-view settings: the URL-bound properties that
 * define a customized report view (dates, filters, sort, presentation).
 *
 * Shared by Memorizable (capture/apply through the live Livewire component)
 * and ReportRenderer (apply outside HTTP for email, schedules, print, and
 * bundles), so a memorized settings snapshot is portable between the two.
 */
final class ReportSettings
{
    /**
     * Only properties actually declared on a component are captured/applied,
     * so this list is the union across all reports. Never rename an existing
     * key — saved memorized_reports.settings reference them.
     *
     * @var list<string>
     */
    public const KEYS = [
        'startDate', 'endDate', 'preset', 'asOf', 'asOfPreset',
        'classId', 'locationId', 'fundId', 'contactId', 'accountId', 'accountType', 'perPage',
        'reportTitle', 'comparisonBasis', 'sortField', 'sortDir', 'excludeUnappliedCredits',
        'view', 'includeInactive', 'hiddenColumns', 'groupBy', 'sourceType',
        'negativeStyle', 'numberUnits', 'reportNotes', 'reportBasis',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function capture(object $component): array
    {
        $state = [];

        foreach (self::KEYS as $key) {
            if (property_exists($component, $key)) {
                $state[$key] = $component->{$key};
            }
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function apply(object $component, array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (in_array($key, self::KEYS, true) && property_exists($component, $key)) {
                $component->{$key} = $value;
            }
        }
    }
}
