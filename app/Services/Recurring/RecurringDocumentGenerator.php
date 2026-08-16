<?php

namespace App\Services\Recurring;

use App\Actions\Purchasing\SaveBill;
use App\Actions\Sales\SaveInvoice;
use App\Actions\Sales\SendInvoiceToCustomer;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurringAutomationMode;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\RecurringDocument;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Turns recurring schedules into invoices/bills by feeding the existing
 * SaveInvoice / SaveBill actions. By default each occurrence is a Draft a human
 * reviews and posts. An invoice schedule may instead opt to post automatically,
 * or post and email the customer — see {@see RecurringAutomationMode}. Posting
 * that would land in a locked period degrades back to a draft rather than failing
 * the run, and on a multi-occurrence catch-up only the most recent invoice is
 * emailed. Bill schedules always draft.
 *
 * Runs safely outside an HTTP request: the SaveX actions read app('current_company'),
 * which is not bound in a job/command, so generation binds it for the duration of
 * each action call. A row lock plus a re-read of next_run_date inside the transaction
 * make generation idempotent and safe against concurrent workers.
 */
class RecurringDocumentGenerator
{
    /**
     * Hard cap on catch-up occurrences generated in a single run, guarding
     * against a pathological backdated start_date flooding the queue.
     */
    protected const MAX_CATCHUP = 60;

    public function __construct(
        protected NextRunDateCalculator $calculator,
    ) {}

    /**
     * Generate every occurrence due on or before $today (catching up if the
     * scheduler missed days), advancing the schedule and applying its end rule.
     *
     * @return Collection<int, Invoice|Bill>
     */
    public function generateDue(RecurringDocument $document, CarbonImmutable $today): Collection
    {
        $created = collect();

        DB::transaction(function () use ($document, $today, $created): void {
            $doc = $this->lockFresh($document);
            $company = Company::query()->findOrFail($doc->company_id);

            $reason = $this->blockingReason($doc);
            if ($reason !== null) {
                $this->pause($doc, $reason);

                return;
            }

            $iterations = 0;

            while ($doc->is_active
                && $doc->next_run_date !== null
                && $doc->next_run_date->lessThanOrEqualTo($today)) {
                if (++$iterations > self::MAX_CATCHUP) {
                    Log::warning('Recurring schedule hit catch-up cap.', [
                        'recurring_document_id' => $doc->id,
                        'company_id' => $doc->company_id,
                    ]);
                    break;
                }

                $created->push($this->generateStep(
                    $doc,
                    $company,
                    CarbonImmutable::parse($doc->next_run_date->toDateString()),
                ));

                if (! $doc->is_active) {
                    break;
                }
            }

            // Post all caught-up occurrences but email only the most recent, so a
            // backdated schedule doesn't blast the customer with a stack of invoices.
            $this->queueAutoEmail($doc, $company, $created->last());
        });

        return $created;
    }

    /**
     * Generate a single occurrence immediately (manual "Generate now"), using the
     * scheduled next_run_date, or the company's today when none is set. Throws if
     * the schedule is inactive or references something that no longer exists.
     */
    public function generateOne(RecurringDocument $document): Invoice|Bill
    {
        return DB::transaction(function () use ($document): Invoice|Bill {
            $doc = $this->lockFresh($document);
            $company = Company::query()->findOrFail($doc->company_id);

            if (! $doc->is_active) {
                throw new RuntimeException('This recurring schedule is paused or ended.');
            }

            $reason = $this->blockingReason($doc);
            if ($reason !== null) {
                throw new RuntimeException($reason);
            }

            $date = $doc->next_run_date !== null
                ? CarbonImmutable::parse($doc->next_run_date->toDateString())
                : $company->currentDateTime()->startOfDay();

            $created = $this->generateStep($doc, $company, $date);

            $this->queueAutoEmail($doc, $company, $created);

            return $created;
        });
    }

    /**
     * Create one draft for $date and advance the schedule one hop.
     */
    protected function generateStep(RecurringDocument $doc, Company $company, CarbonImmutable $date): Invoice|Bill
    {
        $created = $this->withCompany($company, fn (): Invoice|Bill => $this->createDraftFor($doc, $date));

        if ($created instanceof Invoice && $this->automationMode($doc)->postsAutomatically()) {
            $this->autoPost($company, $created);
        }

        $doc->occurrences_generated = (int) $doc->occurrences_generated + 1;
        $doc->last_generated_at = now();
        $doc->next_run_date = $this->calculator->next($doc, $date)->toDateString();

        $this->applyEndRule($doc);
        $doc->save();

        return $created;
    }

    protected function createDraftFor(RecurringDocument $doc, CarbonImmutable $date): Invoice|Bill
    {
        $lines = $doc->lines->map(fn ($line): array => [
            'item_id' => $line->item_id,
            'account_id' => $line->account_id,
            'description' => $line->description,
            'service_date' => $line->service_date?->toDateString(),
            'quantity' => $line->quantity,
            'unit_price_cents' => (int) $line->unit_price_cents,
            'line_discount_cents' => (int) $line->line_discount_cents,
            'line_discount_pct' => $line->line_discount_pct,
            'tax_code_id' => $line->tax_code_id,
            'secondary_tax_code_id' => $line->secondary_tax_code_id,
            'class_id' => $line->class_id,
            'location_id' => $line->location_id,
        ])->all();

        if ($doc->isInvoice()) {
            $invoice = app(SaveInvoice::class)->handle([
                'contact_id' => $doc->contact_id,
                'invoice_no' => null,
                'invoice_date' => $date->toDateString(),
                'due_date' => null,
                'terms_id' => $doc->terms_id,
                'memo' => $doc->memo,
                'lines' => $lines,
            ]);

            // Link the draft to the member when this schedule is a membership
            // auto-renewal, so it surfaces in the member's dues history and reports.
            $stamp = ['recurring_document_id' => $doc->id];
            $memberId = Member::query()->where('recurring_document_id', $doc->id)->value('id');
            if ($memberId !== null) {
                $stamp['member_id'] = $memberId;
            }

            $invoice->forceFill($stamp)->save();

            return $invoice;
        }

        $bill = app(SaveBill::class)->handle([
            'contact_id' => $doc->contact_id,
            'bill_type' => $doc->bill_type?->value,
            'bill_no' => null,
            'vendor_reference' => $doc->vendor_reference,
            'bill_date' => $date->toDateString(),
            'due_date' => null,
            'terms_id' => $doc->terms_id,
            'memo' => $doc->memo,
            'lines' => $lines,
        ]);

        $bill->forceFill(['recurring_document_id' => $doc->id])->save();

        return $bill;
    }

    protected function automationMode(RecurringDocument $doc): RecurringAutomationMode
    {
        return $doc->automation_mode ?? RecurringAutomationMode::Draft;
    }

    /**
     * Post a freshly generated invoice to the books. A locked posting period
     * leaves it as a draft (logged) instead of aborting the whole run.
     */
    protected function autoPost(Company $company, Invoice $invoice): void
    {
        try {
            $this->withCompany($company, fn () => app(InvoicePoster::class)->post($invoice));
        } catch (PeriodLockedException) {
            Log::warning('Recurring auto-post skipped: posting period is locked; left as a draft.', [
                'invoice_id' => $invoice->id,
                'company_id' => $company->id,
            ]);
        }
    }

    /**
     * After the transaction commits, email a posted invoice to its customer when
     * the schedule is set to post-and-email. Skips drafts (auto-post degraded in a
     * locked period) and contacts with no email — the invoice stays posted, unsent.
     */
    protected function queueAutoEmail(RecurringDocument $doc, Company $company, Invoice|Bill|null $created): void
    {
        if (! $created instanceof Invoice || ! $this->automationMode($doc)->emailsAutomatically()) {
            return;
        }

        // A posted invoice has a journal entry; a draft (auto-post degraded in a
        // locked period) does not — and we never email a draft.
        if ($created->journal_entry_id === null) {
            return;
        }

        $email = $created->contact?->email;
        if ($email === null || $email === '') {
            return;
        }

        $message = (string) ($company->invoiceSettingsOrNew()->email_default_message
            ?? __('Please find your invoice attached. You can view and pay it online using the button below.'));

        DB::afterCommit(function () use ($company, $created, $email, $message): void {
            $this->withCompany($company, fn () => app(SendInvoiceToCustomer::class)->handle($company, $created, [$email], $message));
        });
    }

    protected function applyEndRule(RecurringDocument $doc): void
    {
        switch ($doc->end_type) {
            case RecurrenceEndType::OnDate:
                if ($doc->end_date !== null
                    && $doc->next_run_date !== null
                    && $doc->next_run_date->greaterThan($doc->end_date)) {
                    $doc->is_active = false;
                    $doc->next_run_date = null;
                }
                break;

            case RecurrenceEndType::AfterOccurrences:
                if ($doc->max_occurrences !== null
                    && (int) $doc->occurrences_generated >= (int) $doc->max_occurrences) {
                    $doc->is_active = false;
                    $doc->next_run_date = null;
                }
                break;

            case RecurrenceEndType::Never:
            default:
                break;
        }
    }

    /**
     * Re-read the schedule under a row lock so concurrent workers cannot both
     * generate the same occurrence, with its line template eager-loaded.
     */
    protected function lockFresh(RecurringDocument $document): RecurringDocument
    {
        return RecurringDocument::query()
            ->withoutGlobalScopes()
            ->whereKey($document->getKey())
            ->lockForUpdate()
            ->with('lines')
            ->firstOrFail();
    }

    /**
     * Returns a human reason the schedule cannot generate (dead contact/account),
     * or null when generation is safe.
     */
    protected function blockingReason(RecurringDocument $doc): ?string
    {
        // Use default scopes so a soft-deleted (trashed) contact/account reads as
        // gone. The company scope is inert in console context and otherwise only
        // matches the schedule's own tenant, so it never hides a live reference.
        $contact = Contact::query()->find($doc->contact_id);
        if ($contact === null) {
            return 'The linked contact no longer exists.';
        }

        $accountIds = $doc->lines->pluck('account_id')->filter()->unique();
        $foundIds = Account::query()->whereIn('id', $accountIds)->pluck('id');

        if ($foundIds->count() !== $accountIds->count()) {
            return 'A line account no longer exists.';
        }

        return null;
    }

    protected function pause(RecurringDocument $doc, string $reason): void
    {
        $doc->is_active = false;
        $doc->paused_reason = $reason;
        $doc->save();
    }

    /**
     * Bind $company as the current tenant for the closure, then restore whatever
     * (if anything) was bound before — so the reused SaveX actions and the global
     * company scope behave correctly inside a job.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function withCompany(Company $company, Closure $callback): mixed
    {
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }
}
