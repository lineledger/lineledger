<?php

namespace App\Services\Deletion;

use App\Enums\DeletableDocumentType;
use App\Enums\DeletedTransactionState;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\ReconciliationLockedException;
use App\Exceptions\Posting\TaxPeriodFiledException;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CurrencyRevaluation;
use App\Models\DeletedTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningBalanceState;
use App\Models\User;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\JournalPoster;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use App\Services\Tax\TaxPeriodLockGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a transaction the way QuickBooks Desktop's Edit › Delete does: the
 * document, its lines and its journal entries (the void's reversing entry
 * too, for a voided one) leave the ledger and every report as if they had
 * never been entered, while the audit trail keeps a permanent, hash-chained
 * record of what existed, who deleted it, when and why.
 *
 * The single path for deleting a draft, posted or voided document. The models
 * refuse a delete from anywhere else ({@see self::running()},
 * App\Concerns\GuardsPostedDeletion).
 *
 * The rules, in order:
 *
 *  1. Every check a void makes, against every date the transaction touched:
 *     a delete takes the original out of its own period, where a void only
 *     adds a reversal dated today. A closed period raises PeriodLockedException
 *     so the period-lock override can let one delete through.
 *  2. Linked records block. Anything that points at the transaction (a
 *     payment applied to the invoice, a deposit holding the receipt, a filed
 *     return's snapshot, a fixed asset, later stock movements) refuses the
 *     delete with a link to it; nothing changes behind the user's back. What
 *     the transaction owns (its lines, a payment's own applications) goes
 *     with it.
 *  3. Clean up explicitly, never through SET NULL or CASCADE. The one
 *     exception is accounting_audit_logs.journal_entry_id: the database nulls
 *     it on the audit rows of the deleted entries (MySQL fires no trigger on a
 *     foreign-key action), audit:verify does not compare that column, and each
 *     row's hash_input keeps the id.
 *  4. Recompute every cache the rows fed, then write the tombstone and the
 *     audit row last, as the posters record audit last, so the company's
 *     audit-chain lock is held for as short a time as possible.
 *
 * Never call this inside DB::transaction(): it checks the edit lock first, and
 * EditLockManager must not run in a transaction.
 */
final class TransactionDeleter
{
    private static int $running = 0;

    public function __construct(
        private readonly EditLockManager $locks,
        private readonly AccountingAuditRecorder $audit,
        private readonly BankReconciliationLockGuard $reconciliationGuard,
        private readonly TaxPeriodLockGuard $taxGuard,
        private readonly JournalPoster $journalPoster,
        private readonly StockUnwinder $stock,
        private readonly DeletionCleanup $cleanup,
    ) {}

    /**
     * Whether a delete is in progress. The models' deletion guards let only
     * this path remove a posted or voided document.
     */
    public static function running(): bool
    {
        return self::$running > 0;
    }

    /**
     * What deleting the document would do, without changing anything.
     */
    public function preview(Model $document): DeletionPreview
    {
        $handler = DeletableDocuments::handlerFor($document);
        $company = $this->companyOf($document);
        $entries = $this->journalEntries($document);
        $state = $this->stateOf($document, $entries);

        $blockers = $this->blockers($document, $handler, $company, $entries, $state);
        $warnings = [...$this->warnings($document, $company, $entries, $state), ...$handler->warnings($document)];

        return new DeletionPreview($state, $blockers, $warnings);
    }

    /**
     * Delete the document, its lines and its journal entries.
     *
     * @throws DeletionBlockedException something still depends on it, or deleting is off
     * @throws PeriodLockedException it touches a closed period
     */
    public function delete(Model $document, ?string $reason = null, ?User $actor = null, string $via = 'ui'): DeletedTransaction
    {
        $handler = DeletableDocuments::handlerFor($document);
        $company = $this->companyOf($document);
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;
        $reason = $reason !== null && trim($reason) !== '' ? trim(mb_substr($reason, 0, 1000)) : null;

        // Outside the transaction: EditLockManager is compare-and-set, never
        // inside DB::transaction(). Checked here as well as by the page's
        // #[GuardsEditLock], because the period-lock override re-runs the
        // action directly and the API and console have no such hook.
        $this->locks->assertWritable($document, $actor);

        $work = new DeletionWork;

        try {
            $tombstone = DB::transaction(function () use ($document, $handler, $company, $reason, $actor, $via, $work): DeletedTransaction {
                $locked = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw new DeletionBlockedException([new DeletionBlocker(__('This :document has already been deleted.', ['document' => $handler->type()->noun()]))]);
                }

                $entries = $this->journalEntries($locked, lock: true);
                $state = $this->stateOf($locked, $entries);

                $this->ensureOpenPeriods($locked, $company, $entries, $state);

                $blockers = $this->blockers($locked, $handler, $company, $entries, $state);

                if ($blockers !== []) {
                    throw new DeletionBlockedException($blockers);
                }

                $payload = $this->payload($locked, $handler, $entries, $state, $reason);
                $entryIds = array_map('intval', $entries->modelKeys());
                $lines = $entries->flatMap(fn (JournalEntry $e) => $e->lines);
                $lineIds = $lines->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

                foreach ($lines as $line) {
                    $work->account((int) $line->account_id);
                    $work->contact($line->contact_id !== null ? (int) $line->contact_id : null);
                }

                $summary = $handler->summary($locked);

                self::$running++;

                try {
                    AuditMute::silence(function () use ($locked, $handler, $company, $entryIds, $lineIds, $work): void {
                        $companyId = (int) $company->id;

                        $this->cleanup->resetBankFeed($companyId, $entryIds, $lineIds);
                        $this->cleanup->unmarkInProgressReconciliations($companyId, $lineIds);
                        $this->cleanup->unexcludeFromDraftTaxReturns($companyId, $lineIds);
                        $this->cleanup->forgetConfirmedProposals($entryIds);
                        $this->stock->unwind($company, $entryIds, $work);
                        $this->cleanup->removeAttachments($locked, $work);
                        $this->cleanup->removeEditLocks($locked);

                        $handler->purge($locked, $work);

                        if ($entryIds !== []) {
                            JournalLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
                            JournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $entryIds)->delete();
                        }
                    });
                } finally {
                    self::$running--;
                }

                // Derived caches, silenced like the posters' own recomputes: the
                // deletion's audit row is the record of what changed.
                AuditMute::silence(function () use ($handler, $company, $work): void {
                    $this->journalPoster->recomputeAccounts(array_keys($work->accountIds));

                    foreach (Contact::withoutGlobalScopes()->whereIn('id', array_keys($work->contactIds))->get() as $contact) {
                        $contact->recomputeArBalance();
                        $contact->recomputeApBalance();
                    }

                    $handler->afterPurge($work, (int) $company->id);
                });

                $tombstone = DeletedTransaction::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'document_type' => $handler->type(),
                    'original_id' => (int) $locked->getKey(),
                    'document_table' => $locked->getTable(),
                    'document_number' => $summary['document_number'],
                    'bank_account_id' => null,
                    'max_entry_seq' => $this->maxEntrySequence($entries),
                    'document_date' => $summary['document_date'],
                    'contact_name' => $summary['contact_name'],
                    'total_cents' => $summary['total_cents'],
                    'currency_code' => $summary['currency_code'],
                    'state_at_deletion' => $state,
                    'reason' => $reason,
                    'deleted_by_user_id' => $actor?->id,
                    'deleted_via' => $via,
                    'snapshot' => $payload,
                ]);

                $this->audit->record((int) $company->id, $handler->type()->auditAction(), $tombstone, $payload);

                return $tombstone;
            });
        } catch (QueryException $e) {
            // A reference the checks missed hit a foreign key (SQLSTATE 23000).
            // Refuse in plain words rather than fail with a server error.
            if ((string) $e->getCode() === '23000' || str_starts_with((string) $e->getCode(), '23')) {
                report($e);

                throw new DeletionBlockedException([new DeletionBlocker(__('Another record still refers to this :document, so it can\'t be deleted.', ['document' => $handler->type()->noun()]))]);
            }

            throw $e;
        }

        foreach ($work->blobs as $blob) {
            Storage::disk($blob['disk'])->delete($blob['path']);
        }

        return $tombstone;
    }

    /**
     * The document's journal entry and, when it was voided, the reversing
     * entry the void wrote.
     *
     * @return Collection<int, JournalEntry>
     */
    private function journalEntries(Model $document, bool $lock = false): Collection
    {
        $entryId = $document->getAttribute('journal_entry_id');

        if ($entryId === null) {
            return new Collection;
        }

        $companyId = (int) $document->getAttribute('company_id');
        $entry = $this->entryQuery($companyId, $lock)->find($entryId);

        if ($entry === null) {
            return new Collection;
        }

        /** @var Collection<int, JournalEntry> $entries */
        $entries = new Collection([$entry]);

        if ($entry->isVoided() && $entry->reversed_by_entry_id !== null) {
            $reversal = $this->entryQuery($companyId, $lock)->find($entry->reversed_by_entry_id);

            if ($reversal !== null && (int) $reversal->reverses_entry_id === (int) $entry->id) {
                $entries->push($reversal);
            }
        }

        return $entries;
    }

    /**
     * @return Builder<JournalEntry>
     */
    private function entryQuery(int $companyId, bool $lock): Builder
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with('lines')
            ->when($lock, fn (Builder $q) => $q->lockForUpdate());
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function stateOf(Model $document, Collection $entries): DeletedTransactionState
    {
        if ($entries->isEmpty()) {
            return DeletedTransactionState::Draft;
        }

        return $document->getAttribute('voided_at') !== null || $entries->first()->isVoided()
            ? DeletedTransactionState::Voided
            : DeletedTransactionState::Posted;
    }

    /**
     * Every date the transaction put on the ledger: its own and, for a voided
     * one, the reversal's. A draft touched none.
     *
     * @param  Collection<int, JournalEntry>  $entries
     * @return list<CarbonImmutable>
     */
    private function ledgerDates(Model $document, Collection $entries): array
    {
        $dates = $entries->map(fn (JournalEntry $e): CarbonImmutable => CarbonImmutable::parse($e->entry_date))->all();

        $type = DeletableDocumentType::forModel($document);
        $documentDate = $type !== null ? $document->getAttribute($type->dateColumn()) : null;

        if ($entries->isNotEmpty() && $documentDate !== null) {
            $dates[] = CarbonImmutable::parse($documentDate);
        }

        return array_values($dates);
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     *
     * @throws PeriodLockedException
     */
    private function ensureOpenPeriods(Model $document, Company $company, Collection $entries, DeletedTransactionState $state): void
    {
        if ($state === DeletedTransactionState::Draft) {
            return;
        }

        foreach ($this->ledgerDates($document, $entries) as $date) {
            if ($company->isLockedFor($date)) {
                throw PeriodLockedException::for($date, CarbonImmutable::parse($company->lock_date));
            }
        }
    }

    /**
     * Every reason the delete can't go ahead. A closed period is not one of
     * them: it is offered for override at delete time instead.
     *
     * @param  Collection<int, JournalEntry>  $entries
     * @return list<DeletionBlocker>
     */
    private function blockers(Model $document, DeletionHandler $handler, Company $company, Collection $entries, DeletedTransactionState $state): array
    {
        $noun = $handler->type()->noun();
        $blockers = [];

        if ($state !== DeletedTransactionState::Draft && ! $company->allowsTransactionDeletion()) {
            $blockers[] = new DeletionBlocker(__('This organization only allows deleting drafts. A posted or voided transaction can be voided, or an owner can allow deleting under Organization settings.'));
        }

        if ($document->getAttribute('is_opening_balance')) {
            $blockers[] = new DeletionBlocker(
                __('This :document was entered in the Opening Balances workspace. Remove it there.', ['document' => $noun]),
                route('opening-balances.index', ['company' => $company->slug]),
            );
        }

        foreach ($entries as $entry) {
            $ownedByDocument = $entry->source_type === $document::class && (int) $entry->source_id === (int) $document->getKey();
            $isOwnReversal = $entry->reverses_entry_id !== null && $entries->contains('id', $entry->reverses_entry_id);

            if (! $ownedByDocument && ! $isOwnReversal) {
                $blockers[] = new DeletionBlocker(__('Journal entry :no is not this :document\'s own, so it can\'t be deleted with it.', ['no' => $entry->entry_no, 'document' => $noun]));
            }
        }

        if ($state !== DeletedTransactionState::Draft) {
            $lines = $entries->flatMap(fn (JournalEntry $e) => $e->lines);
            $entryIds = array_map('intval', $entries->modelKeys());
            $lineIds = $lines->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

            try {
                $this->reconciliationGuard->ensureLinesNotReconciled((int) $company->id, $lines);
            } catch (ReconciliationLockedException $e) {
                $blockers[] = new DeletionBlocker($e->getMessage());
            }

            $taxCodeIds = array_values(array_unique([...$handler->taxCodeIds($document), ...$lines->pluck('tax_code_id')->filter()->map(fn ($id): int => (int) $id)->all()]));

            foreach ($this->ledgerDates($document, $entries) as $date) {
                try {
                    $this->taxGuard->ensureNotFiled((int) $company->id, $taxCodeIds, $date);
                } catch (TaxPeriodFiledException $e) {
                    $blockers[] = new DeletionBlocker($e->getMessage());

                    break;
                }
            }

            $blockers = [
                ...$blockers,
                ...$this->cleanup->filedTaxReturnBlockers((int) $company->id, $entryIds, $lineIds, $company->slug),
                ...$this->stock->blockers($company, $entryIds, $noun),
            ];
        }

        return [
            ...$blockers,
            ...$this->cleanup->assetBlockers($document, array_map('intval', $entries->modelKeys()), $company->slug),
            ...$handler->blockers($document),
        ];
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     * @return list<string>
     */
    private function warnings(Model $document, Company $company, Collection $entries, DeletedTransactionState $state): array
    {
        if ($state === DeletedTransactionState::Draft) {
            return [];
        }

        $warnings = [];
        $type = DeletableDocumentType::forModel($document);
        $rawDate = $type !== null ? $document->getAttribute($type->dateColumn()) : null;
        $date = $rawDate !== null ? CarbonImmutable::parse($rawDate) : null;

        foreach ($this->ledgerDates($document, $entries) as $ledgerDate) {
            if ($company->isInClosedPeriod($ledgerDate)) {
                $warnings[] = __('It is dated in a closed period (the books are closed through :date). Deleting it changes closed figures, so you will be asked to confirm.', ['date' => CarbonImmutable::parse($company->lock_date)->toDateString()]);

                break;
            }
        }

        if ($date !== null && $date->lessThan($company->currentDateTime()->startOfMonth())) {
            $warnings[] = __('It is dated :date. Reports for that period that were already printed or sent will change.', ['date' => $date->toDateString()]);
        }

        if ($date !== null && $document->getAttribute('currency_code') !== null && ! $company->isHomeCurrency((string) $document->getAttribute('currency_code'))
            && CurrencyRevaluation::withoutGlobalScopes()->where('company_id', $company->id)->whereDate('as_of_date', '>=', $date->toDateString())->exists()) {
            $warnings[] = __('A currency revaluation dated on or after it already counted its foreign balance.');
        }

        $openingAsOf = OpeningBalanceState::withoutGlobalScopes()->where('company_id', $company->id)->value('as_of_date');

        if ($date !== null && $openingAsOf !== null && $date->lessThanOrEqualTo(CarbonImmutable::parse($openingAsOf))) {
            $warnings[] = __('It is dated on or before your opening-balance date, so Opening Balance Equity may need a second look afterwards.');
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     * @return array<string, mixed>
     */
    private function payload(Model $document, DeletionHandler $handler, Collection $entries, DeletedTransactionState $state, ?string $reason): array
    {
        $summary = $handler->summary($document);

        return [
            'kind' => $state->value,
            'reason' => $reason,
            'document_type' => $handler->type()->value,
            'document_class' => $document::class,
            'document_id' => (int) $document->getKey(),
            'document_number' => $summary['document_number'],
            'document' => $handler->snapshot($document),
            'journal_entries' => $entries->map(fn (JournalEntry $e): array => DeletionSnapshot::journalEntry($e))->values()->all(),
        ];
    }

    /**
     * The highest JE-nnnnnn number among the deleted entries, which
     * EntryNumberGenerator then never hands out again.
     *
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function maxEntrySequence(Collection $entries): ?int
    {
        $sequences = $entries
            ->map(fn (JournalEntry $e): ?int => preg_match('/^JE-(\d+)$/', (string) $e->entry_no, $m) ? (int) $m[1] : null)
            ->filter();

        return $sequences->isEmpty() ? null : (int) $sequences->max();
    }

    private function companyOf(Model $document): Company
    {
        return Company::query()->findOrFail($document->getAttribute('company_id'));
    }
}
