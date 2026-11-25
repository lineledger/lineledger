<?php

namespace App\Services\Posting;

use App\Contracts\CompanyAccess;
use App\Enums\AuditAction;
use App\Exceptions\Access\CompanyReadOnlyException;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\PostingValidationException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The single chokepoint for committing journal entries to the ledger.
 * Posting is atomic, balanced, respects the company lock date, and keeps every
 * line on the entry's own organization's accounts.
 * After post, the entry is immutable; corrections happen via void().
 *
 * A read-only organization ({@see CompanyAccess}) can't post, void or repost:
 * its members' permissions already refuse every write, so this is the
 * backstop. The one exception is {@see self::allowingReadOnly()}, which the
 * Stripe webhook uses to record a card payment that has already settled.
 */
class JournalPoster
{
    /** Depth of allowingReadOnly() calls in progress. */
    private static int $readOnlyAllowed = 0;

    public function __construct(
        protected AccountingAuditRecorder $auditRecorder,
        protected BankReconciliationLockGuard $reconciliationLockGuard,
    ) {}

    /**
     * Run $callback with the read-only refusal lifted, for money that has
     * already moved: a customer's card payment Stripe settled, recorded by
     * the webhook. Nothing else should need it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function allowingReadOnly(callable $callback): mixed
    {
        self::$readOnlyAllowed++;

        try {
            return $callback();
        } finally {
            self::$readOnlyAllowed--;
        }
    }

    /**
     * Refuse to change the books of a read-only organization.
     *
     * @throws CompanyReadOnlyException
     */
    public function ensureCompanyWritable(JournalEntry $entry): void
    {
        if (self::$readOnlyAllowed > 0) {
            return;
        }

        $company = $entry->relationLoaded('company') ? $entry->company : Company::query()->find($entry->company_id);

        if ($company instanceof Company && app(CompanyAccess::class)->isReadOnly($company)) {
            throw new CompanyReadOnlyException;
        }
    }

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

            $this->ensureCompanyWritable($entry);

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

            $this->ensureLineAccountsInCompany($entry);

            // No reconciliation check here: a new entry has no lines a completed
            // reconciliation has claimed, so a back-dated posting changes no
            // reconciliation — it shows up as uncleared on the account's next one.

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
     * the given date) that flips every line's debit/credit — and its foreign-
     * currency memo, so a voided foreign document leaves no foreign units
     * behind for the period-end revaluation or the AR/AP reconcilers to find.
     */
    public function void(JournalEntry $entry, ?CarbonImmutable $voidDate = null, ?string $memo = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $voidDate, $memo) {
            $entry->loadMissing('lines', 'company');

            $this->ensureCompanyWritable($entry);

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

            // Reversing a line a completed reconciliation has ticked would alter
            // its reconciled balance. An entry whose bank line was never cleared
            // (e.g. a stale outstanding cheque) may be voided freely: the original
            // and its reversal net out on the next reconciliation.
            $this->reconciliationLockGuard->ensureLinesNotReconciled((int) $entry->company_id, $entry->lines);

            $reversal = AuditMute::silence(fn () => JournalEntry::query()->create([
                'company_id' => $entry->company_id,
                'entry_no' => $this->nextEntryNo($entry),
                'entry_date' => $voidDate->toDateString(),
                'memo' => $memo ?? "Reversal of {$entry->entry_no}",
                'reverses_entry_id' => $entry->id,
            ]));

            AuditMute::silence(function () use ($entry, $reversal): void {
                foreach ($entry->lines as $i => $line) {
                    $reversal->lines()->create($line->reversingAttributes($i));
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
     * Guard an in-place overwrite of a posted manual entry's lines (the journal
     * form's "Save changes" and the API's PUT). The rewrite deletes every line,
     * so it is refused when the entry sits in, or would move into, a locked
     * period, or when a completed reconciliation has ticked one of its lines.
     */
    public function ensurePostedEntryEditable(JournalEntry $entry, CarbonImmutable $newDate): void
    {
        $entry->loadMissing('lines', 'company');

        $this->ensureCompanyWritable($entry);

        foreach ([CarbonImmutable::parse($entry->entry_date), $newDate] as $date) {
            if ($entry->company->isLockedFor($date)) {
                throw PeriodLockedException::for($date, CarbonImmutable::parse($entry->company->lock_date));
            }
        }

        $this->reconciliationLockGuard->ensureLinesNotReconciled((int) $entry->company_id, $entry->lines);
    }

    /**
     * Refuse an entry with a line on another organization's account. A journal
     * line carries no company_id — it belongs to an organization only through
     * its entry — so nothing else stops a line from moving another
     * organization's balances and reports. post() runs this for every entry;
     * whatever rewrites a posted entry's lines in place (a document's repost(),
     * the opening-balance entry, an edit to a manual entry) calls it once the
     * lines are rebuilt, inside its transaction.
     *
     * It reads the saved lines rather than the loaded relation, so a line added
     * after the entry was loaded is checked too. Every in-place rewrite calls
     * it, so it is also where a repost in a read-only organization is refused.
     *
     * @throws PostingValidationException
     * @throws CompanyReadOnlyException
     */
    public function ensureLineAccountsInCompany(JournalEntry $entry): void
    {
        $this->ensureCompanyWritable($entry);

        $foreign = JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->whereNotExists(fn (QueryBuilder $accounts) => $accounts->select('accounts.id')
                ->from('accounts')
                ->whereColumn('accounts.id', 'journal_lines.account_id')
                ->where('accounts.company_id', $entry->company_id))
            ->exists();

        if ($foreign) {
            throw new PostingValidationException(__('A line uses an account that belongs to another organization.'));
        }
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
