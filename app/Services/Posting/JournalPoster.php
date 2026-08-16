<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The single chokepoint for committing journal entries to the ledger.
 * Posting is atomic, balanced, and respects the company lock date.
 * After post, the entry is immutable; corrections happen via void().
 */
class JournalPoster
{
    public function __construct(
        protected AccountingAuditRecorder $auditRecorder,
        protected BankReconciliationLockGuard $reconciliationLockGuard,
    ) {}

    /**
     * Post an entry to the ledger.
     *
     * Pass $recompute = false during bulk imports to skip the per-entry balance
     * recomputation (the dominant cost when posting thousands of entries); call
     * recomputeAccounts() once with all affected account ids when the batch is done.
     */
    public function post(JournalEntry $entry, bool $recompute = true): JournalEntry
    {
        return DB::transaction(function () use ($entry, $recompute) {
            $entry->loadMissing('lines', 'company');

            if ($entry->isPosted()) {
                throw AlreadyPostedException::for((int) $entry->id);
            }

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from($entry->totalDebitsCents(), $entry->totalCreditsCents());
            }

            $entryDate = CarbonImmutable::parse($entry->entry_date);

            if ($entry->company->isLockedFor($entryDate)) {
                throw PeriodLockedException::for($entryDate, CarbonImmutable::parse($entry->company->lock_date));
            }

            $this->reconciliationLockGuard->ensureNotReconciled(
                (int) $entry->company_id,
                $entry->lines->pluck('account_id')->all(),
                $entryDate,
            );

            AuditMute::silence(fn () => $entry->forceFill([
                'is_posted' => true,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
            ])->save());

            // When this entry reverses another (an accrual-style draft reversal that
            // has now been posted), stamp the original so its "Reversed by" link
            // resolves. Voids set this themselves; guard against clobbering.
            if ($entry->reverses_entry_id !== null) {
                $original = JournalEntry::withoutGlobalScopes()
                    ->where('company_id', $entry->company_id)
                    ->find($entry->reverses_entry_id);

                if ($original && ! $original->isVoided() && $original->reversed_by_entry_id === null) {
                    AuditMute::silence(fn () => $original->forceFill(['reversed_by_entry_id' => $entry->id])->save());
                }
            }

            if ($recompute) {
                $this->recomputeAffectedAccounts($entry);
            }

            $entry = $entry->fresh(['lines.account']);

            $this->auditRecorder->record(
                (int) $entry->company_id,
                AuditAction::JournalEntryPosted,
                $entry,
                AccountingAuditRecorder::snapshotJournalEntry($entry),
                $entry,
            );

            return $entry;
        });
    }

    /**
     * Void a posted entry by writing a reversing entry dated today (or
     * the given date) that flips every line's debit/credit.
     */
    public function void(JournalEntry $entry, ?CarbonImmutable $voidDate = null, ?string $memo = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $voidDate, $memo) {
            $entry->loadMissing('lines', 'company');

            if (! $entry->isPosted()) {
                throw new \RuntimeException('Cannot void a draft entry. Delete it instead.');
            }

            if ($entry->isVoided()) {
                throw new \RuntimeException('Entry is already voided.');
            }

            $voidDate ??= $entry->company->currentDateTime();

            if ($entry->company->isLockedFor($voidDate)) {
                throw PeriodLockedException::for($voidDate, CarbonImmutable::parse($entry->company->lock_date));
            }

            // Guard on the ORIGINAL entry's date: a reconciled transaction sits on
            // or before the statement date, so voiding it would alter the reconciled
            // balance. The reversal itself posts at $voidDate (an open period).
            $this->reconciliationLockGuard->ensureNotReconciled(
                (int) $entry->company_id,
                $entry->lines->pluck('account_id')->all(),
                CarbonImmutable::parse($entry->entry_date),
            );

            $reversal = AuditMute::silence(fn () => JournalEntry::query()->create([
                'company_id' => $entry->company_id,
                'entry_no' => $this->nextEntryNo($entry),
                'entry_date' => $voidDate->toDateString(),
                'memo' => $memo ?? "Reversal of {$entry->entry_no}",
                'reverses_entry_id' => $entry->id,
            ]));

            AuditMute::silence(function () use ($entry, $reversal): void {
                foreach ($entry->lines as $i => $line) {
                    $reversal->lines()->create([
                        'account_id' => $line->account_id,
                        'debit_cents' => $line->credit_cents,
                        'credit_cents' => $line->debit_cents,
                        'memo' => $line->memo,
                        'contact_id' => $line->contact_id,
                        'tax_code_id' => $line->tax_code_id,
                        'line_order' => $i,
                        'class_id' => $line->class_id,
                        'location_id' => $line->location_id,
                        'fund_id' => $line->fund_id,
                    ]);
                }
            });

            $reversal->refresh();

            $this->post($reversal);

            AuditMute::silence(fn () => $entry->forceFill([
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
                'reversed_by_entry_id' => $reversal->id,
            ])->save());

            $original = $entry->fresh(['lines.account']);

            $this->auditRecorder->record(
                (int) $original->company_id,
                AuditAction::JournalEntryVoided,
                $original,
                [
                    'original' => AccountingAuditRecorder::snapshotJournalEntry($original),
                    'reversal_entry_id' => (int) $reversal->id,
                    'reversal_entry_no' => $reversal->entry_no,
                ],
                $original,
            );

            return $reversal->fresh(['lines.account']);
        });
    }

    protected function recomputeAffectedAccounts(JournalEntry $entry): void
    {
        $this->recomputeAccounts($entry->lines->pluck('account_id')->all());
    }

    /**
     * Recompute cached balances for the given account ids. Used after a bulk
     * post() loop that skipped per-entry recomputation.
     *
     * @param  list<int>  $accountIds
     */
    public function recomputeAccounts(array $accountIds): void
    {
        foreach (array_unique($accountIds) as $id) {
            Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
        }
    }

    protected function nextEntryNo(JournalEntry $original): string
    {
        return 'REV-'.$original->entry_no.'-'.now()->format('YmdHis');
    }
}
