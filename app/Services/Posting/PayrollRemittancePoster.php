<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\RemittanceAgency;
use App\Enums\RemittanceStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\JournalEntry;
use App\Models\PayrollRemittance;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a recorded source-deduction remittance as one balanced journal entry on
 * the payment date — clearing the statutory payables the pay runs credited:
 *
 *   DR  CPP/EI/Income-Tax Payable   (CRA)   — or
 *   DR  QPP/QPIP/Quebec-Tax/QHSF/CNESST Payable   (Revenu Québec)
 *     CR  Bank                      total
 *
 * Mirrors {@see TaxReturnPaymentPoster}: status Paid→Void, JE link, already-posted
 * + period-lock guards, audit, and a reversing {@see void()}.
 */
class PayrollRemittancePoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected PayrollAccountResolver $accounts,
        protected AccountingAuditRecorder $auditRecorder,
    ) {}

    public function post(PayrollRemittance $remittance): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($remittance) {
            $remittance->loadMissing('company');

            if ($remittance->journal_entry_id) {
                throw AlreadyPostedException::for((int) $remittance->journal_entry_id);
            }

            $paymentDate = CarbonImmutable::parse($remittance->payment_date);

            if ($remittance->company->isLockedFor($paymentDate)) {
                throw PeriodLockedException::for($paymentDate, CarbonImmutable::parse($remittance->company->lock_date));
            }

            if ((int) $remittance->total_cents <= 0) {
                throw new RuntimeException('Remittance has zero amount; nothing to post.');
            }

            if (! $remittance->bank_account_id) {
                throw new RuntimeException('A bank account is required to record the remittance.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($remittance->company),
                'entry_date' => $remittance->payment_date,
                'memo' => $this->memoFor($remittance),
                'source_type' => PayrollRemittance::class,
                'source_id' => $remittance->id,
            ]);

            $order = 0;
            $breakdown = $remittance->breakdown ?? [];

            // DR each statutory payable the agency clears (skip zero amounts).
            foreach ($remittance->agency->payableLegs() as $leg) {
                $amount = (int) ($breakdown[$leg['key']] ?? 0);

                if ($amount === 0) {
                    continue;
                }

                $entry->lines()->create([
                    'account_id' => $this->accounts->resolve($remittance->company, $leg['account'])->id,
                    'debit_cents' => $amount,
                    'credit_cents' => 0,
                    'memo' => $leg['memo'],
                    'line_order' => $order++,
                ]);
            }

            // CR the bank for the total remitted.
            $entry->lines()->create([
                'account_id' => $remittance->bank_account_id,
                'debit_cents' => 0,
                'credit_cents' => (int) $remittance->total_cents,
                'memo' => 'Remittance payment',
                'line_order' => $order++,
            ]);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $remittance->forceFill([
                'status' => RemittanceStatus::Paid,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $remittance->company_id,
                AuditAction::PayrollRemittancePosted,
                $remittance,
                [
                    'agency' => $remittance->agency->value,
                    'period_start' => optional($remittance->period_start)->toDateString(),
                    'period_end' => optional($remittance->period_end)->toDateString(),
                    'due_date' => optional($remittance->due_date)->toDateString(),
                    'total_cents' => (int) $remittance->total_cents,
                    'breakdown' => $breakdown,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(PayrollRemittance $remittance, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($remittance, $voidDate) {
            $remittance->loadMissing('journalEntry');

            if (! $remittance->journal_entry_id) {
                throw new RuntimeException('Remittance is not posted.');
            }

            if ($remittance->status === RemittanceStatus::Void) {
                throw new RuntimeException('Remittance is already voided.');
            }

            $this->journalPoster->void(
                $remittance->journalEntry,
                $voidDate,
                "Void of payroll remittance #{$remittance->id}",
            );

            $remittance->forceFill([
                'status' => RemittanceStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $remittance->company_id,
                AuditAction::PayrollRemittanceVoided,
                $remittance,
                [
                    'agency' => $remittance->agency->value,
                    'period_start' => optional($remittance->period_start)->toDateString(),
                    'voided_at' => optional($remittance->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $remittance->journal_entry_id,
                ],
                $remittance->journalEntry,
            );
        }));
    }

    private function memoFor(PayrollRemittance $remittance): string
    {
        return sprintf(
            '%s remittance — %s to %s',
            $remittance->agency === RemittanceAgency::Cra ? 'CRA' : 'Revenu Québec',
            CarbonImmutable::parse($remittance->period_start)->toDateString(),
            CarbonImmutable::parse($remittance->period_end)->toDateString(),
        );
    }
}
