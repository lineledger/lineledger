<?php

namespace App\Observers;

use App\Models\Account;
use App\Support\Gifi\GifiCatalog;

class AccountObserver
{
    /**
     * Seed a sensible GIFI line from the account's subtype when none was supplied,
     * so every account (chart seeding, manual creation, imports) lands on the GIFI
     * Statement out of the box. Fully editable afterward, including clearing it.
     */
    public function creating(Account $account): void
    {
        if ($account->gifi_code === null && $account->subtype !== null) {
            $account->gifi_code = GifiCatalog::defaultForSubtype($account->subtype);
        }
    }

    /**
     * Keep report-section assignments consistent when an account is re-typed.
     * A section is anchored to a subtype (balance sheet) or bucket (income
     * statement); if the account's type/subtype changes so it no longer belongs
     * to its section's anchor, drop the assignment. The report would otherwise
     * route it to "Unassigned" at render time anyway — this keeps the stored
     * data clean. saveQuietly avoids re-triggering observers.
     */
    public function updated(Account $account): void
    {
        if ($account->report_section_id === null) {
            return;
        }

        if (! $account->wasChanged(['type', 'subtype'])) {
            return;
        }

        $section = $account->reportSection()->first();

        if ($section !== null && ! $section->accepts($account)) {
            $account->forceFill(['report_section_id' => null])->saveQuietly();
        }
    }
}
