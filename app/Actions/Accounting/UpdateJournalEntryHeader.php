<?php

namespace App\Actions\Accounting;

use App\Enums\AuditAction;
use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\BankReconciliation;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Reconciliation\BankReconciliationLockGuard;
use App\Services\Reconciliation\BankReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Edits only the header of a journal entry — date, number, memo — leaving its
 * lines untouched. This is the one edit path open to source-linked entries the
 * journal is allowed to touch ({@see JournalEntry::JOURNAL_EDITABLE_SOURCES}):
 * a bank-reconciliation service charge or interest adjustment keeps its
 * accounts and amounts, but its date can be corrected without undoing the
 * reconciliation. Manual entries may use it too.
 *
 * Because the lines are kept (not rebuilt), the bank line's cleared flag and
 * reconciliation link survive, so the reconciliation's marked set stays valid.
 * A date move re-runs the period-lock and reconciliation-lock checks on both
 * the old and the new date, and is recorded to the audit trail.
 *
 * Expected $data shape:
 *   entry_no:   ?string  (empty → unchanged)
 *   entry_date: string
 *   memo:       ?string
 */
final class UpdateJournalEntryHeader
{
    public function __construct(
        private readonly BankReconciliationLockGuard $reconciliationLockGuard,
        private readonly AccountingAuditRecorder $auditRecorder,
        private readonly BankReconciliationService $reconciliations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(JournalEntry $entry, array $data): JournalEntry
    {
        if (! $entry->isEditableInJournal()) {
            throw LinkedJournalEntryException::for($entry);
        }

        if ($entry->isVoided()) {
            throw new RuntimeException('A voided entry cannot be edited.');
        }

        return DB::transaction(function () use ($entry, $data): JournalEntry {
            $entry->loadMissing('lines', 'company');

            $company = $entry->company;
            $originalDate = CarbonImmutable::parse($entry->entry_date);
            $newDate = CarbonImmutable::parse($data['entry_date']);

            if ($entry->isPosted()) {
                foreach ([$originalDate, $newDate] as $date) {
                    if ($company->isLockedFor($date)) {
                        throw PeriodLockedException::for($date, CarbonImmutable::parse($company->lock_date));
                    }
                }

                // Guard both dates: leaving a reconciled period would alter the
                // reconciled balance just as much as entering one.
                $accountIds = $entry->lines->pluck('account_id')->all();
                $this->reconciliationLockGuard->ensureNotReconciled((int) $entry->company_id, $accountIds, $originalDate);
                $this->reconciliationLockGuard->ensureNotReconciled((int) $entry->company_id, $accountIds, $newDate);
            }

            $header = [
                'entry_date' => $newDate->toDateString(),
                'memo' => ($data['memo'] ?? null) ?: null,
            ];

            if (! empty($data['entry_no'])) {
                $header['entry_no'] = (string) $data['entry_no'];
            }

            // JournalEntry's saved hook pushes the new entry_date onto the lines.
            $entry->update($header);

            if ($entry->source_type === BankReconciliation::class) {
                $this->reconciliations->syncAdjustmentEntry($entry);
            }

            $fresh = $entry->fresh(['lines.account']);

            if ($fresh->isPosted()) {
                $this->auditRecorder->record(
                    (int) $fresh->company_id,
                    AuditAction::JournalEntryUpdated,
                    $fresh,
                    AccountingAuditRecorder::snapshotJournalEntry($fresh),
                    $fresh,
                );
            }

            return $fresh;
        });
    }
}
