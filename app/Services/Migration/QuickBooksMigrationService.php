<?php

namespace App\Services\Migration;

use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Audit\AuditMute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates the QuickBooks → Lineledger conversion. Owns the
 * DataMigrationRun lifecycle: start / resume / advance / finalize.
 * Individual CSV imports live in the Importers/ namespace and are
 * invoked directly by the wizard component — this service is the
 * glue around them.
 *
 * Two mutually-exclusive modes per company: opening_balance (balances as of a
 * conversion date, then lock) and full_history (replay the entire GL).
 */
class QuickBooksMigrationService
{
    /**
     * Find the in-progress run for the company, or start a new one.
     *
     * When a mode is supplied that differs from an existing in-progress run, the
     * switch is only honoured if the run has no committed steps yet — otherwise
     * the existing run is returned untouched (the two modes can't be mixed).
     */
    public function startOrResume(Company $company, ?CarbonImmutable $conversionDate = null, ?DataMigrationMode $mode = null): DataMigrationRun
    {
        $existing = DataMigrationRun::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();

        if ($existing && $existing->status === DataMigrationStatus::InProgress) {
            if ($mode !== null && $mode !== $existing->modeEnum() && $this->isFresh($existing)) {
                $existing->forceFill([
                    'mode' => $mode,
                    'current_step' => 1,
                    'step_results' => [],
                ])->save();

                return $existing->fresh();
            }

            return $existing;
        }

        $mode ??= DataMigrationMode::OpeningBalance;
        $conversionDate ??= $this->defaultConversionDate($company);

        $attributes = [
            'status' => DataMigrationStatus::InProgress,
            'mode' => $mode,
            'conversion_date' => $conversionDate,
            'current_step' => 1,
            'step_results' => [],
            'started_at' => now(),
            'completed_at' => null,
        ];

        if ($existing) {
            $existing->forceFill($attributes)->save();

            return $existing->fresh();
        }

        return DataMigrationRun::withoutGlobalScopes()->create(array_merge($attributes, [
            'company_id' => $company->id,
            'open_invoices_use_original_date' => true,
            'open_bills_use_original_date' => true,
            'auto_create_accounts' => false,
            'link_contact_names' => true,
        ]));
    }

    public function advance(DataMigrationRun $run): DataMigrationRun
    {
        $next = min((int) $run->current_step + 1, $run->lastStep());
        $run->forceFill(['current_step' => $next])->save();

        return $run->fresh();
    }

    public function jumpTo(DataMigrationRun $run, int $step): DataMigrationRun
    {
        $clamped = max(1, min($step, $run->lastStep()));
        $run->forceFill(['current_step' => $clamped])->save();

        return $run->fresh();
    }

    /**
     * Finalize the migration and mark the run completed.
     *
     * Opening-balance runs lock the company at the conversion date. Full-history
     * runs leave the books open by default; pass $lockBooks to freeze everything
     * on or before the history start date.
     */
    public function finalize(DataMigrationRun $run, bool $lockBooks = false): DataMigrationRun
    {
        if ($run->modeEnum() === DataMigrationMode::OpeningBalance) {
            $run->company->forceFill(['lock_date' => $run->conversion_date])->save();
        } elseif ($lockBooks && $run->history_start_date !== null) {
            $run->company->forceFill(['lock_date' => $run->history_start_date])->save();
        }

        $run->forceFill([
            'status' => DataMigrationStatus::Completed,
            'completed_at' => now(),
        ])->save();

        return $run->fresh();
    }

    public function abandon(DataMigrationRun $run): DataMigrationRun
    {
        // Full-history replays insert real GL entries; abandoning should clean them up.
        if ($run->modeEnum() === DataMigrationMode::FullHistory) {
            $this->rollbackFullHistory($run);
        }

        $run->forceFill([
            'status' => DataMigrationStatus::Abandoned,
            'completed_at' => now(),
        ])->save();

        return $run->fresh();
    }

    /**
     * Hard-delete every journal entry the full-history replay created and recompute
     * affected account balances. Guarded: only while the run is in progress and the
     * company is unlocked.
     *
     * @return int the number of entries removed
     */
    public function rollbackFullHistory(DataMigrationRun $run): int
    {
        if ($run->status !== DataMigrationStatus::InProgress) {
            throw new RuntimeException('Can only roll back a full-history import while the migration is in progress.');
        }

        if ($run->company->lock_date !== null) {
            throw new RuntimeException('Cannot roll back: the company is locked.');
        }

        $companyId = (int) $run->company->id;

        // source_external_id is set on every entry this importer creates — whether it
        // stayed a plain journal entry or was linked to a reconstructed document.
        // A full-history import can post hundreds of thousands of entries, so everything
        // here uses SUBQUERIES rather than arrays of ids — a whereIn() over 65k+ ids
        // exceeds MySQL's prepared-statement placeholder limit.
        $importedEntries = fn ($query) => $query->select('id')->from('journal_entries')
            ->where('company_id', $companyId)->whereNotNull('source_external_id');

        $count = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)->whereNotNull('source_external_id')->count();

        if ($count === 0) {
            return 0;
        }

        $accountIds = JournalLine::query()
            ->whereIn('journal_entry_id', $importedEntries)
            ->distinct()
            ->pluck('account_id');

        AuditMute::silence(function () use ($importedEntries, $companyId): void {
            DB::transaction(function () use ($importedEntries, $companyId): void {
                $this->deleteReconstructedDocuments($companyId, $importedEntries);
                JournalLine::query()->whereIn('journal_entry_id', $importedEntries)->delete();
                DB::table('journal_entries')->where('company_id', $companyId)->whereNotNull('source_external_id')->delete();
            });
        });

        foreach ($accountIds as $id) {
            Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
        }

        // Clear the recorded GL step so the wizard lets the user re-import.
        $results = $run->step_results ?? [];
        unset($results['general_ledger']);
        $run->forceFill(['step_results' => $results])->save();

        return $count;
    }

    /**
     * Delete documents reconstructed by the full-history import (and their lines and
     * payment applications), identified by their link to the import's journal entries.
     * Uses subqueries throughout to stay under the placeholder limit at scale.
     *
     * @param  \Closure  $importedEntries  subquery yielding the import's journal_entries ids
     */
    protected function deleteReconstructedDocuments(int $companyId, \Closure $importedEntries): void
    {
        // [document table, line table, line FK, application table, application FK]
        $map = [
            ['invoices', 'invoice_lines', 'invoice_id', 'receipt_applications', 'invoice_id'],
            ['credit_memos', 'credit_memo_lines', 'credit_memo_id', null, null],
            ['customer_receipts', null, null, 'receipt_applications', 'customer_receipt_id'],
            ['bills', 'bill_lines', 'bill_id', 'bill_payment_applications', 'bill_id'],
            ['bill_payments', null, null, 'bill_payment_applications', 'bill_payment_id'],
            ['deposits', 'deposit_lines', 'deposit_id', null, null],
            ['cheques', 'cheque_lines', 'cheque_id', null, null],
        ];

        foreach ($map as [$docTable, $lineTable, $lineFk, $appTable, $appFk]) {
            $docIds = fn ($query) => $query->select('id')->from($docTable)
                ->where('company_id', $companyId)->whereIn('journal_entry_id', $importedEntries);

            if ($lineTable !== null) {
                DB::table($lineTable)->whereIn($lineFk, $docIds)->delete();
            }

            if ($appTable !== null) {
                DB::table($appTable)->whereIn($appFk, $docIds)->delete();
            }

            // DB::table()->delete() is a hard delete (bypasses soft-delete), clearing the slate.
            DB::table($docTable)->where('company_id', $companyId)->whereIn('journal_entry_id', $importedEntries)->delete();
        }
    }

    /**
     * A run is "fresh" (mode-switchable) when nothing past setup has been committed.
     */
    protected function isFresh(DataMigrationRun $run): bool
    {
        $results = $run->step_results ?? [];

        foreach ($results as $key => $payload) {
            if ($key !== 'setup' && isset($payload['committed_at'])) {
                return false;
            }
        }

        return true;
    }

    protected function defaultConversionDate(Company $company): CarbonImmutable
    {
        // Most users convert at fiscal year-end. Default to the most recent
        // fiscal year-end on or before today (or today, whichever is earlier).
        $fyStartMonth = (int) ($company->fiscal_year_start_month ?? 1);
        $today = $company->currentDateTime();

        // Year-end is the last day of the month before fiscal start.
        $endMonth = $fyStartMonth === 1 ? 12 : ($fyStartMonth - 1);
        $year = $today->year;
        $candidate = CarbonImmutable::create($year, $endMonth, 1)?->endOfMonth();

        if ($candidate === null || $candidate->greaterThan($today)) {
            $candidate = CarbonImmutable::create($year - 1, $endMonth, 1)?->endOfMonth() ?? $today;
        }

        return $candidate;
    }
}
