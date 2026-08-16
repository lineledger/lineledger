<?php

namespace App\Actions\Payroll;

use App\Models\PayrollSlipFiling;

/**
 * Unlocks (un-finalizes) a year-end slip filing so the company can amend it:
 * deletes the {@see PayrollSlipFiling} (its snapshot lines cascade), returning
 * the year to live-computed "draft" and pulling the slips off the employee
 * portal until the year is finalized again.
 */
final class UnlockSlipFiling
{
    public function handle(PayrollSlipFiling $filing): void
    {
        $filing->delete();
    }
}
