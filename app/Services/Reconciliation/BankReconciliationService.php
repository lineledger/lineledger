<?php

namespace App\Services\Reconciliation;

use App\Enums\BankReconciliationStatus;
use App\Exceptions\Posting\ReconciliationOutOfBalanceException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Coordinates the lifecycle of a bank reconciliation session.
 *
 *  - begin():    Create an in-progress rec; post optional service-charge / interest
 *                journal entries on the statement date; auto-mark any lines that
 *                were already cleared via the legacy bank register.
 *  - complete(): Validate the maths balance, persist cleared_at + rec id on every
 *                marked line, and mark the rec completed.
 *  - undo():     Reverse the service-charge / interest entries, un-clear every
 *                linked line, and delete the rec row. Only the latest completed
 *                rec for an account may be undone.
 */
class BankReconciliationService
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
    ) {}

    /**
     * @param  array{cents:int,date:CarbonInterface,account_id:int}|null  $serviceCharge
     * @param  array{cents:int,date:CarbonInterface,account_id:int}|null  $interestEarned
     */
    public function begin(
        Account $account,
        CarbonInterface $statementDate,
        int $endingBalanceCents,
        ?array $serviceCharge = null,
        ?array $interestEarned = null,
    ): BankReconciliation {
        return DB::transaction(function () use ($account, $statementDate, $endingBalanceCents, $serviceCharge, $interestEarned) {
            $existing = BankReconciliation::query()
                ->forAccount($account->id)
                ->inProgress()
                ->first();

            if ($existing) {
                throw new RuntimeException("Account {$account->code} already has an in-progress reconciliation (#{$existing->id}). Finish or cancel it first.");
            }

            $beginningBalanceCents = $this->lastEndingBalanceCents($account);

            /** @var BankReconciliation $rec */
            $rec = BankReconciliation::query()->create([
                'company_id' => $account->company_id,
                'account_id' => $account->id,
                'statement_date' => $statementDate->toDateString(),
                'beginning_balance_cents' => $beginningBalanceCents,
                'ending_balance_cents' => $endingBalanceCents,
                'status' => BankReconciliationStatus::InProgress->value,
                'marked_line_ids' => [],
            ]);

            $autoMarkedIds = [];

            if ($serviceCharge && ($serviceCharge['cents'] ?? 0) > 0) {
                [$entry, $bankLineId] = $this->postServiceChargeEntry($rec, $account, $serviceCharge);

                $rec->forceFill([
                    'service_charge_cents' => $serviceCharge['cents'],
                    'service_charge_date' => Carbon::parse($serviceCharge['date'])->toDateString(),
                    'service_charge_account_id' => $serviceCharge['account_id'],
                    'service_charge_entry_id' => $entry->id,
                ])->save();

                $autoMarkedIds[] = $bankLineId;
            }

            if ($interestEarned && ($interestEarned['cents'] ?? 0) > 0) {
                [$entry, $bankLineId] = $this->postInterestEntry($rec, $account, $interestEarned);

                $rec->forceFill([
                    'interest_earned_cents' => $interestEarned['cents'],
                    'interest_earned_date' => Carbon::parse($interestEarned['date'])->toDateString(),
                    'interest_earned_account_id' => $interestEarned['account_id'],
                    'interest_earned_entry_id' => $entry->id,
                ])->save();

                $autoMarkedIds[] = $bankLineId;
            }

            $legacyClearedIds = JournalLine::query()
                ->where('account_id', $account->id)
                ->whereNotNull('cleared_at')
                ->whereNull('bank_reconciliation_id')
                ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true)->whereNull('voided_at'))
                ->pluck('id')
                ->all();

            $marked = array_values(array_unique(array_map('intval', [...$autoMarkedIds, ...$legacyClearedIds])));

            $rec->forceFill(['marked_line_ids' => $marked])->save();

            return $rec->fresh();
        });
    }

    public function complete(BankReconciliation $rec, ?User $user = null): BankReconciliation
    {
        return DB::transaction(function () use ($rec, $user) {
            $rec->refresh();

            if (! $rec->isInProgress()) {
                throw new RuntimeException("Reconciliation #{$rec->id} is not in progress.");
            }

            $difference = $this->differenceCents($rec);

            if ($difference !== 0) {
                throw ReconciliationOutOfBalanceException::from($difference);
            }

            $ids = $rec->markedLineIds();

            if (! empty($ids)) {
                JournalLine::query()
                    ->where('account_id', $rec->account_id)
                    ->whereIn('id', $ids)
                    ->whereNull('cleared_at')
                    ->update(['cleared_at' => now()]);

                JournalLine::query()
                    ->where('account_id', $rec->account_id)
                    ->whereIn('id', $ids)
                    ->update(['bank_reconciliation_id' => $rec->id]);
            }

            $rec->forceFill([
                'status' => BankReconciliationStatus::Completed->value,
                'completed_at' => now(),
                'completed_by_user_id' => $user?->id,
            ])->save();

            return $rec->fresh();
        });
    }

    /**
     * Edit the starting figures of an in-progress reconciliation in place,
     * preserving the lines the user has already marked. Service-charge / interest
     * changes are applied by reversing any existing aux entry and re-posting the
     * new one, so the GL and the rec's marked set stay consistent. Reversals of
     * these aux entries are hidden from the reconcile screen (see the reconcile
     * view's availableLines() query) so editing never leaves phantom lines behind.
     *
     * @param  array{cents:int,date:CarbonInterface,account_id:int}|null  $serviceCharge
     * @param  array{cents:int,date:CarbonInterface,account_id:int}|null  $interestEarned
     */
    public function updateDetails(
        BankReconciliation $rec,
        CarbonInterface $statementDate,
        int $endingBalanceCents,
        int $beginningBalanceCents,
        ?array $serviceCharge = null,
        ?array $interestEarned = null,
    ): BankReconciliation {
        // Reversing the existing service-charge / interest entries voids
        // transactions dated on this rec's statement date, which the lock guard
        // would otherwise block — bypass it for the duration of the edit.
        return ReconciliationLockBypass::silence(fn () => DB::transaction(function () use ($rec, $statementDate, $endingBalanceCents, $beginningBalanceCents, $serviceCharge, $interestEarned) {
            $rec->refresh();

            if (! $rec->isInProgress()) {
                throw new RuntimeException("Reconciliation #{$rec->id} is not in progress.");
            }

            /** @var Account $account */
            $account = $rec->account()->firstOrFail();

            $rec->forceFill([
                'statement_date' => $statementDate->toDateString(),
                'beginning_balance_cents' => $beginningBalanceCents,
                'ending_balance_cents' => $endingBalanceCents,
            ])->save();

            $marked = $rec->markedLineIds();

            $this->resetServiceCharge($rec, $account, $serviceCharge, $marked);
            $this->resetInterest($rec, $account, $interestEarned, $marked);

            $rec->forceFill(['marked_line_ids' => array_values(array_unique(array_map('intval', $marked)))])->save();

            return $rec->fresh();
        }));
    }

    public function undo(BankReconciliation $rec): void
    {
        // Reversing the service-charge / interest entries voids transactions dated
        // on this still-completed reconciliation's statement date, so bypass the
        // reconciliation lock for the duration of the undo.
        ReconciliationLockBypass::silence(fn () => DB::transaction(function () use ($rec) {
            $rec->refresh();
            $rec->loadMissing('serviceChargeEntry', 'interestEarnedEntry');

            if (! $rec->isCompleted()) {
                throw new RuntimeException("Reconciliation #{$rec->id} is not completed.");
            }

            $latest = BankReconciliation::query()
                ->forAccount($rec->account_id)
                ->completed()
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->first();

            if (! $latest || $latest->id !== $rec->id) {
                throw new RuntimeException('Only the most recent reconciliation for this account can be undone.');
            }

            if ($rec->serviceChargeEntry && ! $rec->serviceChargeEntry->isVoided()) {
                $this->journalPoster->void(
                    $rec->serviceChargeEntry,
                    null,
                    "Undo reconciliation #{$rec->id} — service charge reversal",
                );
            }

            if ($rec->interestEarnedEntry && ! $rec->interestEarnedEntry->isVoided()) {
                $this->journalPoster->void(
                    $rec->interestEarnedEntry,
                    null,
                    "Undo reconciliation #{$rec->id} — interest earned reversal",
                );
            }

            JournalLine::query()
                ->where('bank_reconciliation_id', $rec->id)
                ->update([
                    'cleared_at' => null,
                    'bank_reconciliation_id' => null,
                ]);

            $rec->delete();
        }));
    }

    public function toggleMark(BankReconciliation $rec, int $lineId): BankReconciliation
    {
        return DB::transaction(function () use ($rec, $lineId) {
            $rec->refresh();

            if (! $rec->isInProgress()) {
                throw new RuntimeException("Reconciliation #{$rec->id} is not in progress.");
            }

            $line = JournalLine::query()
                ->where('account_id', $rec->account_id)
                ->findOrFail($lineId);

            $ids = $rec->markedLineIds();

            if (in_array($line->id, $ids, true)) {
                $ids = array_values(array_diff($ids, [$line->id]));
            } else {
                $ids[] = (int) $line->id;
            }

            $rec->forceFill(['marked_line_ids' => $ids])->save();

            return $rec->fresh();
        });
    }

    /**
     * Bulk companion to {@see toggleMark()}: merge a set of journal-line ids into the
     * rec's marked set in one shot. Used by the bank-statement importer to pre-tick
     * every auto-matched line before handing the user the reconcile screen. Only ids
     * that genuinely belong to this account's ledger are accepted.
     *
     * @param  list<int>  $lineIds
     */
    public function markLines(BankReconciliation $rec, array $lineIds): BankReconciliation
    {
        return DB::transaction(function () use ($rec, $lineIds) {
            $rec->refresh();

            if (! $rec->isInProgress()) {
                throw new RuntimeException("Reconciliation #{$rec->id} is not in progress.");
            }

            $lineIds = array_values(array_unique(array_map('intval', $lineIds)));

            if ($lineIds === []) {
                return $rec->fresh();
            }

            $valid = JournalLine::query()
                ->where('account_id', $rec->account_id)
                ->whereIn('id', $lineIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $merged = array_values(array_unique([...$rec->markedLineIds(), ...$valid]));

            $rec->forceFill(['marked_line_ids' => $merged])->save();

            return $rec->fresh();
        });
    }

    public function cancel(BankReconciliation $rec): void
    {
        // An in-progress rec never trips the lock (the guard only matches completed
        // recs), but bypass defensively in case its service-charge / interest
        // entries fall inside another account's reconciled period.
        ReconciliationLockBypass::silence(fn () => DB::transaction(function () use ($rec) {
            $rec->refresh();
            $rec->loadMissing('serviceChargeEntry', 'interestEarnedEntry');

            if (! $rec->isInProgress()) {
                throw new RuntimeException("Reconciliation #{$rec->id} is not in progress.");
            }

            if ($rec->serviceChargeEntry && ! $rec->serviceChargeEntry->isVoided()) {
                $this->journalPoster->void(
                    $rec->serviceChargeEntry,
                    null,
                    "Cancel reconciliation #{$rec->id} — service charge reversal",
                );
            }

            if ($rec->interestEarnedEntry && ! $rec->interestEarnedEntry->isVoided()) {
                $this->journalPoster->void(
                    $rec->interestEarnedEntry,
                    null,
                    "Cancel reconciliation #{$rec->id} — interest earned reversal",
                );
            }

            JournalLine::query()
                ->where('bank_reconciliation_id', $rec->id)
                ->update([
                    'cleared_at' => null,
                    'bank_reconciliation_id' => null,
                ]);

            $rec->delete();
        }));
    }

    public function differenceCents(BankReconciliation $rec): int
    {
        return $rec->ending_balance_cents - $this->clearedBalanceCents($rec);
    }

    public function clearedBalanceCents(BankReconciliation $rec): int
    {
        $ids = $rec->markedLineIds();

        if (empty($ids)) {
            return $rec->beginning_balance_cents;
        }

        $sum = (int) JournalLine::query()
            ->where('account_id', $rec->account_id)
            ->whereIn('id', $ids)
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS s')
            ->value('s');

        return $rec->beginning_balance_cents + $sum;
    }

    protected function lastEndingBalanceCents(Account $account): int
    {
        $previous = BankReconciliation::query()
            ->forAccount($account->id)
            ->completed()
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        return $previous?->ending_balance_cents ?? 0;
    }

    /**
     * Reverse any existing service-charge entry, then post a fresh one when the
     * edited form still carries a charge. Keeps the rec's marked set pointed at
     * the live bank line.
     *
     * @param  array{cents:int,date:CarbonInterface,account_id:int}|null  $sc
     * @param  list<int>  $marked
     */
    protected function resetServiceCharge(BankReconciliation $rec, Account $account, ?array $sc, array &$marked): void
    {
        if ($rec->service_charge_entry_id) {
            $existing = $rec->serviceChargeEntry()->first();

            if ($existing) {
                $bankLineIds = $existing->lines()->where('account_id', $account->id)->pluck('id')->all();
                $marked = array_values(array_diff($marked, array_map('intval', $bankLineIds)));

                if (! $existing->isVoided()) {
                    $this->journalPoster->void($existing, null, "Edit reconciliation #{$rec->id} — service charge replaced");
                }
            }

            $rec->forceFill([
                'service_charge_cents' => 0,
                'service_charge_date' => null,
                'service_charge_account_id' => null,
                'service_charge_entry_id' => null,
            ])->save();
        }

        if ($sc && (int) ($sc['cents'] ?? 0) > 0) {
            [$entry, $bankLineId] = $this->postServiceChargeEntry($rec, $account, $sc);

            $rec->forceFill([
                'service_charge_cents' => (int) $sc['cents'],
                'service_charge_date' => Carbon::parse($sc['date'])->toDateString(),
                'service_charge_account_id' => $sc['account_id'],
                'service_charge_entry_id' => $entry->id,
            ])->save();

            $marked[] = (int) $bankLineId;
        }
    }

    /**
     * Reverse any existing interest entry, then post a fresh one when the edited
     * form still carries interest.
     *
     * @param  array{cents:int,date:CarbonInterface,account_id:int}|null  $int
     * @param  list<int>  $marked
     */
    protected function resetInterest(BankReconciliation $rec, Account $account, ?array $int, array &$marked): void
    {
        if ($rec->interest_earned_entry_id) {
            $existing = $rec->interestEarnedEntry()->first();

            if ($existing) {
                $bankLineIds = $existing->lines()->where('account_id', $account->id)->pluck('id')->all();
                $marked = array_values(array_diff($marked, array_map('intval', $bankLineIds)));

                if (! $existing->isVoided()) {
                    $this->journalPoster->void($existing, null, "Edit reconciliation #{$rec->id} — interest replaced");
                }
            }

            $rec->forceFill([
                'interest_earned_cents' => 0,
                'interest_earned_date' => null,
                'interest_earned_account_id' => null,
                'interest_earned_entry_id' => null,
            ])->save();
        }

        if ($int && (int) ($int['cents'] ?? 0) > 0) {
            [$entry, $bankLineId] = $this->postInterestEntry($rec, $account, $int);

            $rec->forceFill([
                'interest_earned_cents' => (int) $int['cents'],
                'interest_earned_date' => Carbon::parse($int['date'])->toDateString(),
                'interest_earned_account_id' => $int['account_id'],
                'interest_earned_entry_id' => $entry->id,
            ])->save();

            $marked[] = (int) $bankLineId;
        }
    }

    /**
     * Service charge: DR expense account, CR bank.
     *
     * @param  array{cents:int,date:CarbonInterface,account_id:int}  $sc
     * @return array{0:JournalEntry,1:int} entry and the bank-side line id
     */
    protected function postServiceChargeEntry(BankReconciliation $rec, Account $account, array $sc): array
    {
        $date = Carbon::parse($sc['date']);

        $entry = JournalEntry::query()->create([
            'company_id' => $account->company_id,
            'entry_no' => $this->entryNumbers->next($account->company),
            'entry_date' => $date->toDateString(),
            'memo' => "Bank service charge — reconciliation #{$rec->id}",
            'source_type' => BankReconciliation::class,
            'source_id' => $rec->id,
        ]);

        $entry->lines()->create([
            'account_id' => $sc['account_id'],
            'debit_cents' => (int) $sc['cents'],
            'credit_cents' => 0,
            'memo' => 'Bank service charge',
            'line_order' => 0,
        ]);

        $bankLine = $entry->lines()->create([
            'account_id' => $account->id,
            'debit_cents' => 0,
            'credit_cents' => (int) $sc['cents'],
            'memo' => 'Bank service charge',
            'line_order' => 1,
            'cleared_at' => now(),
            'bank_reconciliation_id' => $rec->id,
        ]);

        $entry->refresh();
        $this->journalPoster->post($entry);

        return [$entry->fresh(), (int) $bankLine->id];
    }

    /**
     * Interest earned: DR bank, CR income account.
     *
     * @param  array{cents:int,date:CarbonInterface,account_id:int}  $int
     * @return array{0:JournalEntry,1:int}
     */
    protected function postInterestEntry(BankReconciliation $rec, Account $account, array $int): array
    {
        $date = Carbon::parse($int['date']);

        $entry = JournalEntry::query()->create([
            'company_id' => $account->company_id,
            'entry_no' => $this->entryNumbers->next($account->company),
            'entry_date' => $date->toDateString(),
            'memo' => "Interest earned — reconciliation #{$rec->id}",
            'source_type' => BankReconciliation::class,
            'source_id' => $rec->id,
        ]);

        $bankLine = $entry->lines()->create([
            'account_id' => $account->id,
            'debit_cents' => (int) $int['cents'],
            'credit_cents' => 0,
            'memo' => 'Interest earned',
            'line_order' => 0,
            'cleared_at' => now(),
            'bank_reconciliation_id' => $rec->id,
        ]);

        $entry->lines()->create([
            'account_id' => $int['account_id'],
            'debit_cents' => 0,
            'credit_cents' => (int) $int['cents'],
            'memo' => 'Interest earned',
            'line_order' => 1,
        ]);

        $entry->refresh();
        $this->journalPoster->post($entry);

        return [$entry->fresh(), (int) $bankLine->id];
    }
}
