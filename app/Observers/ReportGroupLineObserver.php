<?php

namespace App\Observers;

use App\Models\ReportGroupLine;

class ReportGroupLineObserver
{
    /**
     * Keep section assignments consistent when a combined line is re-typed or
     * re-routed. A section is anchored to a subtype/type (balance sheet), bucket
     * (income statement) or activity (cash flow); if the line's type/subtype or
     * cash-flow activity override changes so it no longer belongs to its section's
     * anchor, drop the assignment. The report would route it to "Unassigned" at
     * render time anyway — this keeps the stored data clean. saveQuietly avoids
     * re-triggering observers.
     */
    public function updated(ReportGroupLine $line): void
    {
        if ($line->report_group_section_id === null) {
            return;
        }

        if (! $line->wasChanged(['type', 'subtype', 'cash_flow_activity'])) {
            return;
        }

        $section = $line->section()->first();

        if ($section !== null && ! $section->accepts($line)) {
            $line->forceFill(['report_group_section_id' => null])->saveQuietly();
        }
    }
}
