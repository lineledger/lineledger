<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\PayrollAccount;
use App\Enums\PayrollChequeStatus;
use App\Enums\PayRunStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\JournalEntry;
use App\Models\PayrollCheque;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts (and voids) an individual payroll cheque — the cash disbursement leg of
 * a posted pay run:
 *   DR  Net Pay Clearing
 *     CR  Bank
 *
 * Each cheque produces one bank journal line, so bank reconciliation treats a
 * payroll cheque exactly like any other cheque.
 */
class PayrollChequePoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected PayrollAccountResolver $accounts,
        protected AccountingAuditRecorder $auditRecorder,
    ) {}

    public function post(PayrollCheque $cheque): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($cheque) {
            $cheque->loadMissing('company', 'payRun');

            if ($cheque->journal_entry_id) {
                throw AlreadyPostedException::for((int) $cheque->journal_entry_id);
            }

            if ($cheque->amount_cents <= 0) {
                throw new RuntimeException('Cannot post a cheque with a non-positive amount.');
            }

            $chequeDate = CarbonImmutable::parse($cheque->cheque_date);

            if ($cheque->company->isLockedFor($chequeDate)) {
                throw PeriodLockedException::for($chequeDate, CarbonImmutable::parse($cheque->company->lock_date));
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($cheque->company),
                'entry_date' => $cheque->cheque_date,
                'memo' => 'Payroll cheque '.$cheque->cheque_no.' — '.$cheque->payee_name,
                'source_type' => PayrollCheque::class,
                'source_id' => $cheque->id,
            ]);

            $clearing = $this->accounts->resolve($cheque->company, PayrollAccount::NetPayClearing);

            $entry->lines()->create([
                'account_id' => $clearing->id,
                'debit_cents' => (int) $cheque->amount_cents,
                'credit_cents' => 0,
                'memo' => 'Net pay',
                'contact_id' => $cheque->payee_contact_id,
                'line_order' => 0,
            ]);

            $entry->lines()->create([
                'account_id' => $cheque->bank_account_id,
                'debit_cents' => 0,
                'credit_cents' => (int) $cheque->amount_cents,
                'memo' => 'Payroll cheque '.$cheque->cheque_no,
                'contact_id' => $cheque->payee_contact_id,
                'line_order' => 1,
            ]);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $cheque->forceFill([
                'status' => PayrollChequeStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->recomputeRunStatus($cheque);

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $cheque->company_id,
                AuditAction::PayrollChequePosted,
                $cheque,
                [
                    'cheque_no' => $cheque->cheque_no,
                    'amount_cents' => (int) $cheque->amount_cents,
                    'bank_account_id' => (int) $cheque->bank_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(PayrollCheque $cheque, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($cheque, $voidDate) {
            $cheque->loadMissing('journalEntry', 'payRun');

            if (! $cheque->journal_entry_id) {
                throw new RuntimeException('Cheque is not posted.');
            }

            if ($cheque->status === PayrollChequeStatus::Void) {
                throw new RuntimeException('Cheque is already voided.');
            }

            $this->journalPoster->void($cheque->journalEntry, $voidDate, "Void of payroll cheque {$cheque->cheque_no}");

            $cheque->forceFill([
                'status' => PayrollChequeStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            // A posted run reverts from Paid to Posted while a cheque is outstanding.
            if ($cheque->payRun->status === PayRunStatus::Paid) {
                $cheque->payRun->forceFill(['status' => PayRunStatus::Posted])->save();
            }

            $this->auditRecorder->record(
                (int) $cheque->company_id,
                AuditAction::PayrollChequeVoided,
                $cheque,
                [
                    'cheque_no' => $cheque->cheque_no,
                    'voided_at' => optional($cheque->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $cheque->journal_entry_id,
                ],
                $cheque->journalEntry,
            );
        }));
    }

    /**
     * Mark the run Paid once every positive-net employee has a posted cheque.
     */
    protected function recomputeRunStatus(PayrollCheque $cheque): void
    {
        $run = $cheque->payRun;
        $run->loadMissing('lines', 'cheques');

        $owed = $run->lines->filter(fn ($line) => $line->net_cents > 0)->count();
        $paid = $run->cheques->where('status', PayrollChequeStatus::Posted)->count();

        if ($run->status === PayRunStatus::Posted && $owed > 0 && $paid >= $owed) {
            $run->forceFill(['status' => PayRunStatus::Paid])->save();
        }
    }
}
