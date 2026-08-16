<?php

namespace App\Services\Reminders;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReminderLog;
use App\Models\ReminderTier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out which invoices are due for a payment reminder today, and at which
 * tier. For each eligible invoice it picks the single highest tier that has
 * fired (so a backlogged invoice gets one current notice, not a stack), and
 * skips anything already logged for that tier — the scheduled run is therefore
 * idempotent and never double-sends.
 *
 * Eligibility: an open invoice with a balance, reminders not disabled on the
 * invoice, the customer opted in to reminder emails, and a customer email on file.
 *
 * The Reminders worklist passes $includeOptedOut to surface customers who have
 * reminder emails switched off, so a user can still chase one by hand; the
 * scheduled run always uses the default and never sees them.
 */
class DueReminderResolver
{
    /**
     * @param  Collection<int, ReminderTier>|null  $tiers  Pre-loaded active tiers; resolved from the company when null.
     * @param  bool  $includeOptedOut  Include customers who have reminder emails turned off.
     * @return Collection<int, array{invoice: Invoice, tier: ReminderTier}>
     */
    public function due(Company $company, CarbonImmutable $today, ?Collection $tiers = null, bool $includeOptedOut = false): Collection
    {
        $tiers = ($tiers ?? ReminderTier::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('tier_order')
            ->get())
            ->sortByDesc('offset_days')   // most-overdue tier first
            ->values();

        if ($tiers->isEmpty()) {
            return collect();
        }

        $sent = InvoiceReminderLog::query()
            ->where('company_id', $company->id)
            ->get()
            ->groupBy('invoice_id');

        $candidates = Invoice::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->openWithBalance()
            ->where('reminders_enabled', true)
            ->whereHas('contact', fn ($q) => $q
                ->when(! $includeOptedOut, fn ($q) => $q->where('reminder_emails_enabled', true))
                ->whereNotNull('email')
                ->where('email', '!=', ''))
            ->with('contact')
            ->get();

        return $candidates
            ->map(function (Invoice $invoice) use ($tiers, $today, $sent): ?array {
                if (! $invoice->due_date) {
                    return null;
                }

                $dueDate = CarbonImmutable::parse($invoice->due_date);

                // The current tier is the highest whose fire date has arrived.
                $current = $tiers->first(
                    fn (ReminderTier $tier): bool => ! $today->lessThan($dueDate->addDays($tier->offset_days)),
                );

                if ($current === null) {
                    return null;
                }

                $alreadySent = ($sent[$invoice->id] ?? collect())
                    ->contains('reminder_tier_id', $current->id);

                return $alreadySent ? null : ['invoice' => $invoice, 'tier' => $current];
            })
            ->filter()
            ->values();
    }
}
