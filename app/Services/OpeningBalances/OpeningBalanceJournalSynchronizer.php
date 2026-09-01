<?php

namespace App\Services\OpeningBalances;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\ReconciliationLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceState;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\JournalPoster;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Maintains the single "opening balances" journal entry that realizes the
 * draft trial balance targets in the GL.
 *
 * For every target account except the AR/AP/Inventory control accounts and
 * Opening Balance Equity itself, the entry's line is computed by NETTING:
 *
 *   L(a) = target(a) − Σ signed posted lines on a dated <= as-of (excluding
 *          this entry's own lines)
 *
 * so the account's as-of GL balance lands exactly on the target regardless of
 * what else is already posted — scattered per-account opening JEs, opening
 * documents, outstanding cheques and deposits in transit are all absorbed.
 * For a bank account this makes the maintained line equal the statement-side
 * balance (book target + outstanding cheques − deposits in transit), which is
 * also why those lines are stamped cleared_at: the first reconciliation's
 * begin() auto-marks them while the outstanding items stay tickable.
 *
 * AR/AP are excluded on purpose: a plug line on a control account carries no
 * contact_id, so the aging report would dump it in "Unattributed". Their
 * targets are satisfied by opening documents instead, and the status panel
 * shows the variance. Inventory flows through the stock sub-ledger only.
 *
 * Targets change continuously, so the entry is REPOSTED IN PLACE (the
 * DepositPoster::repost idiom) rather than voided and recreated.
 */
class OpeningBalanceJournalSynchronizer
{
    protected const EXCLUDED_SUBTYPES = [
        AccountSubtype::AccountsReceivable,
        AccountSubtype::AccountsPayable,
        AccountSubtype::Inventory,
    ];

    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected BankReconciliationLockGuard $reconciliationLockGuard,
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
    ) {}

    /**
     * Bring the maintained entry in step with the targets. No-ops (without an
     * audit row) when the ledger already matches; creates, reposts in place,
     * or voids as needed. Throws PeriodLockedException / ReconciliationLockedException
     * when the books are locked — use applyQuietly() from UI mutation paths.
     */
    public function apply(OpeningBalanceState $state): ?JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($state) {
            $state->loadMissing('company');

            if ($state->isFinalized()) {
                throw new RuntimeException('Opening balances are finalized; un-finalize before editing.');
            }

            $company = $state->company;
            $asOf = $state->asOf();
            $entry = $this->maintainedEntry($state);
            $lines = $this->computedLines($state);
            $plug = -array_sum($lines);

            if ($lines === []) {
                if ($entry) {
                    $this->voidMaintainedEntry($state, $entry);
                }

                $state->forceFill(['journal_entry_id' => null, 'applied_at' => now(), 'apply_error' => null])->save();

                return null;
            }

            $obe = $this->openingBalanceAccounts->resolveOrFail((int) $company->id);

            if ($entry && $this->matchesEntry($entry, $lines, $plug, (int) $obe->id, $asOf)) {
                // Already in step — refresh the cleared stamp (idempotent) and go home.
                $this->stampBankLinesCleared($entry, $asOf);
                $state->forceFill(['journal_entry_id' => $entry->id, 'applied_at' => now(), 'apply_error' => null])->save();

                return $entry;
            }

            $entry = $entry
                ? $this->repostEntry($state, $entry, $lines, $plug, $obe, $asOf)
                : $this->createEntry($state, $lines, $plug, $obe, $asOf);

            $this->stampBankLinesCleared($entry, $asOf);

            $state->forceFill(['journal_entry_id' => $entry->id, 'applied_at' => now(), 'apply_error' => null])->save();

            return $entry;
        }));
    }

    /**
     * apply() for auto-apply-on-save paths: a period or reconciliation lock is
     * an expected, user-fixable condition — record it on the state (so the
     * workspace can show a banner with a Retry) instead of failing the save
     * that triggered it.
     */
    public function applyQuietly(OpeningBalanceState $state): ?JournalEntry
    {
        try {
            return $this->apply($state);
        } catch (PeriodLockedException|ReconciliationLockedException $e) {
            $state->forceFill(['apply_error' => $e->getMessage()])->save();

            return null;
        }
    }

    /**
     * The would-be entry lines, account_id => signed home cents (debit-positive),
     * excluding the OBE plug. Pure — no writes.
     *
     * @return array<int, int>
     */
    public function computedLines(OpeningBalanceState $state): array
    {
        $state->loadMissing('rows.account');

        $obe = $this->openingBalanceAccounts->resolve((int) $state->company_id);
        $excluded = array_map(fn (AccountSubtype $s) => $s->value, self::EXCLUDED_SUBTYPES);

        $targets = [];

        foreach ($state->rows as $row) {
            $account = $row->account;

            if (! $account || in_array($account->subtype->value, $excluded, true)) {
                continue;
            }

            if ($obe && (int) $account->id === (int) $obe->id) {
                continue;
            }

            $targets[(int) $account->id] = $row->signedCents();
        }

        if ($targets === []) {
            return [];
        }

        $posted = $this->postedSignedByAccount(array_keys($targets), $state);

        $lines = [];

        foreach ($targets as $accountId => $target) {
            $line = $target - ($posted[$accountId] ?? 0);

            if ($line !== 0) {
                $lines[$accountId] = $line;
            }
        }

        return $lines;
    }

    /** Whether the ledger no longer matches the targets (a pending apply). */
    public function isDirty(OpeningBalanceState $state): bool
    {
        $lines = $this->computedLines($state);
        $entry = $this->maintainedEntry($state);

        if (! $entry) {
            return $lines !== [];
        }

        $obe = $this->openingBalanceAccounts->resolve((int) $state->company_id);

        return ! $this->matchesEntry($entry, $lines, -array_sum($lines), $obe !== null ? (int) $obe->id : 0, $state->asOf());
    }

    protected function maintainedEntry(OpeningBalanceState $state): ?JournalEntry
    {
        if (! $state->journal_entry_id) {
            return null;
        }

        $entry = JournalEntry::withoutGlobalScopes()->with('lines')->find($state->journal_entry_id);

        // A voided pointer is a dead pointer — the next apply starts fresh.
        return ($entry === null || $entry->isVoided()) ? null : $entry;
    }

    /**
     * Signed posted activity per account, dated on or before the as-of date,
     * excluding the maintained entry's own lines. Same semantics as
     * ReportCalculator's balance-as-of, so the netting ties to the reports.
     *
     * @param  list<int>  $accountIds
     * @return array<int, int>
     */
    protected function postedSignedByAccount(array $accountIds, OpeningBalanceState $state): array
    {
        return DB::table('journal_lines')
            ->whereIn('account_id', $accountIds)
            ->where('is_posted', true)
            ->where('entry_date', '<=', $state->asOf()->toDateString())
            ->when($state->journal_entry_id, fn ($q) => $q->where('journal_entry_id', '!=', $state->journal_entry_id))
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit_cents - credit_cents) AS signed')
            ->pluck('signed', 'account_id')
            ->map(fn ($signed) => (int) $signed)
            ->all();
    }

    /**
     * @param  array<int, int>  $lines
     */
    protected function matchesEntry(JournalEntry $entry, array $lines, int $plug, int $obeAccountId, CarbonImmutable $asOf): bool
    {
        if (CarbonImmutable::parse($entry->entry_date)->toDateString() !== $asOf->toDateString()) {
            return false;
        }

        $current = [];

        foreach ($entry->lines as $line) {
            $current[(int) $line->account_id] = ($current[(int) $line->account_id] ?? 0)
                + (int) $line->debit_cents - (int) $line->credit_cents;
        }

        $expected = $lines;

        if ($plug !== 0) {
            $expected[$obeAccountId] = ($expected[$obeAccountId] ?? 0) + $plug;
        }

        $expected = array_filter($expected, fn (int $v) => $v !== 0);
        $current = array_filter($current, fn (int $v) => $v !== 0);

        ksort($expected);
        ksort($current);

        return $expected === $current;
    }

    /**
     * @param  array<int, int>  $lines
     */
    protected function createEntry(OpeningBalanceState $state, array $lines, int $plug, Account $obe, CarbonImmutable $asOf): JournalEntry
    {
        $entry = JournalEntry::withoutGlobalScopes()->create([
            'company_id' => $state->company_id,
            'entry_no' => $this->entryNumbers->next($state->company),
            'entry_date' => $asOf->toDateString(),
            'memo' => 'Opening balances — draft trial balance',
            'source_type' => OpeningBalanceState::class,
            'source_id' => $state->id,
        ]);

        $this->writeLines($entry, $lines, $plug, $obe);

        $entry->refresh();

        // post() enforces balance, the period lock, the reconciliation lock,
        // recomputes balances and records the JournalEntryPosted audit row.
        $this->journalPoster->post($entry);

        $entry = $entry->fresh(['lines']);

        $this->recordApplied($state, $entry, journalBefore: null);

        return $entry;
    }

    /**
     * In-place rebuild on the same journal entry — the DepositPoster::repost
     * idiom. JournalPoster::post() is not re-run, so the period and
     * reconciliation locks are enforced explicitly on both the original and
     * the new entry date.
     *
     * @param  array<int, int>  $lines
     */
    protected function repostEntry(OpeningBalanceState $state, JournalEntry $entry, array $lines, int $plug, Account $obe, CarbonImmutable $asOf): JournalEntry
    {
        $company = $state->company;
        $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);

        $originalDate = CarbonImmutable::parse($entry->entry_date);

        foreach ([$originalDate, $asOf] as $date) {
            if ($company->isLockedFor($date)) {
                throw PeriodLockedException::for($date, CarbonImmutable::parse($company->lock_date));
            }
        }

        $oldAccountIds = $entry->lines->pluck('account_id')->all();
        $newAccountIds = [...array_keys($lines), $obe->id];

        $this->reconciliationLockGuard->ensureNotReconciled((int) $company->id, $oldAccountIds, $originalDate);
        $this->reconciliationLockGuard->ensureNotReconciled((int) $company->id, $newAccountIds, $asOf);

        $entry->forceFill([
            'entry_date' => $asOf->toDateString(),
            'memo' => 'Opening balances — draft trial balance',
        ])->save();

        $entry->lines()->delete();

        $this->writeLines($entry, $lines, $plug, $obe);

        $entry->refresh();

        if (! $entry->isBalanced()) {
            throw UnbalancedJournalException::from($entry->totalDebitsCents(), $entry->totalCreditsCents());
        }

        $this->journalPoster->recomputeAccounts(array_unique([...$oldAccountIds, ...$entry->lines->pluck('account_id')->all()]));

        $entry = $entry->fresh(['lines']);

        $this->recordApplied($state, $entry, $journalBefore);

        return $entry;
    }

    /**
     * @param  array<int, int>  $lines
     */
    protected function writeLines(JournalEntry $entry, array $lines, int $plug, Account $obe): void
    {
        $accounts = Account::withoutGlobalScopes()
            ->whereIn('id', array_keys($lines))
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        // Deterministic order (by account code) so repeated applies over the
        // same targets produce byte-identical entries and audit snapshots.
        uksort($lines, fn (int $a, int $b) => strcmp(
            (string) ($accounts[$a]->code ?? $a),
            (string) ($accounts[$b]->code ?? $b),
        ));

        $order = 0;

        foreach ($lines as $accountId => $signed) {
            $account = $accounts[$accountId] ?? null;

            $entry->lines()->create([
                'account_id' => $accountId,
                'debit_cents' => max($signed, 0),
                'credit_cents' => max(-$signed, 0),
                'memo' => $account ? "Opening balance target: {$account->code} — {$account->name}" : 'Opening balance target',
                'line_order' => $order++,
            ]);
        }

        if ($plug !== 0) {
            $entry->lines()->create([
                'account_id' => $obe->id,
                'debit_cents' => max($plug, 0),
                'credit_cents' => max(-$plug, 0),
                'memo' => 'Opening Balance Equity (plug to balance)',
                'line_order' => $order,
            ]);
        }
    }

    /**
     * Stamp the entry's bank / credit-card lines cleared (with no
     * reconciliation id) so BankReconciliationService::begin() auto-marks them
     * as "legacy cleared" on the account's first reconciliation. Deliberately
     * NOT a synthetic completed reconciliation — that would engage the
     * reconciliation lock and permanently block reposting this entry.
     */
    protected function stampBankLinesCleared(JournalEntry $entry, CarbonImmutable $asOf): void
    {
        $bankish = Account::withoutGlobalScopes()
            ->whereIn('id', $entry->lines()->pluck('account_id'))
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->pluck('id');

        if ($bankish->isEmpty()) {
            return;
        }

        $entry->lines()
            ->whereIn('account_id', $bankish)
            ->whereNull('bank_reconciliation_id')
            ->update(['cleared_at' => $asOf]);
    }

    protected function voidMaintainedEntry(OpeningBalanceState $state, JournalEntry $entry): void
    {
        $asOf = $state->asOf();
        $voidDate = $state->company->isLockedFor($asOf) ? null : $asOf;

        $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);

        $this->journalPoster->void($entry, $voidDate, 'Opening balances cleared');

        $this->recordApplied($state, null, $journalBefore);
    }

    protected function recordApplied(OpeningBalanceState $state, ?JournalEntry $entry, ?array $journalBefore): void
    {
        $this->auditRecorder->record(
            (int) $state->company_id,
            AuditAction::OpeningBalanceApplied,
            $state,
            [
                'as_of_date' => $state->asOf()->toDateString(),
                'journal_entry_id' => $entry?->id,
                'journal_before' => $journalBefore,
                'journal_after' => $entry ? AccountingAuditRecorder::snapshotJournalEntry($entry) : null,
            ],
            $entry,
        );
    }
}
