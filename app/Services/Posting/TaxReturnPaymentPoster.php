<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\TaxReturnPaymentDirection;
use App\Enums\TaxReturnPaymentStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\TaxReturnPayment;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a remittance payment against a filed tax return.
 *
 * Outgoing (company → agency):
 *   DR  Tax Payable (agency)        net_amount
 *   DR  Penalty Expense             penalty
 *   DR  Interest Expense            interest
 *   DR  Commission Expense          commission
 *   CR    Bank                      total
 *
 * Incoming (agency → company refund):
 *   DR  Bank                        total (net + interest received)
 *   CR    Tax Payable (agency)      net_amount
 *   CR    Interest Income           interest
 */
class TaxReturnPaymentPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
    ) {}

    public function post(TaxReturnPayment $payment): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($payment) {
            $payment->loadMissing('taxReturn.taxAgency.payableAccount', 'company');

            if ($payment->journal_entry_id) {
                throw AlreadyPostedException::for((int) $payment->journal_entry_id);
            }

            if ($payment->company->isLockedFor(CarbonImmutable::parse($payment->payment_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($payment->payment_date),
                    CarbonImmutable::parse($payment->company->lock_date),
                );
            }

            $this->validate($payment);
            $payment->recalculateTotal();

            $payable = $payment->taxReturn->taxAgency->payableAccount;

            if (! $payable) {
                throw new RuntimeException('Tax agency has no payable account configured.');
            }

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($payment->company),
                'entry_date' => $payment->payment_date,
                'memo' => $this->memoFor($payment),
                'source_type' => TaxReturnPayment::class,
                'source_id' => $payment->id,
            ]);

            $order = 0;

            if ($payment->direction === TaxReturnPaymentDirection::Outgoing) {
                $entry->lines()->create([
                    'account_id' => $payable->id,
                    'debit_cents' => (int) $payment->net_amount_cents,
                    'credit_cents' => 0,
                    'memo' => 'Tax remittance — '.$payment->taxReturn->tax_return_no,
                    'line_order' => $order++,
                ]);

                if ((int) $payment->penalty_cents > 0) {
                    $entry->lines()->create([
                        'account_id' => $payment->penalty_account_id,
                        'debit_cents' => (int) $payment->penalty_cents,
                        'credit_cents' => 0,
                        'memo' => 'Penalty',
                        'line_order' => $order++,
                    ]);
                }

                if ((int) $payment->interest_cents > 0) {
                    $entry->lines()->create([
                        'account_id' => $payment->interest_account_id,
                        'debit_cents' => (int) $payment->interest_cents,
                        'credit_cents' => 0,
                        'memo' => 'Interest',
                        'line_order' => $order++,
                    ]);
                }

                if ((int) $payment->commission_cents > 0) {
                    $entry->lines()->create([
                        'account_id' => $payment->commission_account_id,
                        'debit_cents' => (int) $payment->commission_cents,
                        'credit_cents' => 0,
                        'memo' => 'Commission',
                        'line_order' => $order++,
                    ]);
                }

                $entry->lines()->create([
                    'account_id' => $payment->bank_account_id,
                    'debit_cents' => 0,
                    'credit_cents' => (int) $payment->total_cents,
                    'memo' => 'Tax payment',
                    'line_order' => $order++,
                ]);
            } else {
                // Incoming refund
                $entry->lines()->create([
                    'account_id' => $payment->bank_account_id,
                    'debit_cents' => (int) $payment->total_cents,
                    'credit_cents' => 0,
                    'memo' => 'Tax refund',
                    'line_order' => $order++,
                ]);

                $entry->lines()->create([
                    'account_id' => $payable->id,
                    'debit_cents' => 0,
                    'credit_cents' => (int) $payment->net_amount_cents,
                    'memo' => 'Tax refund — '.$payment->taxReturn->tax_return_no,
                    'line_order' => $order++,
                ]);

                if ((int) $payment->interest_cents > 0) {
                    $entry->lines()->create([
                        'account_id' => $payment->interest_account_id,
                        'debit_cents' => 0,
                        'credit_cents' => (int) $payment->interest_cents,
                        'memo' => 'Interest received',
                        'line_order' => $order++,
                    ]);
                }
            }

            $entry->refresh();
            $this->journalPoster->post($entry);

            $payment->forceFill([
                'status' => TaxReturnPaymentStatus::Posted,
                'total_cents' => (int) $payment->total_cents,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $payment->company_id,
                AuditAction::TaxReturnPaymentPosted,
                $payment,
                [
                    'payment_no' => $payment->payment_no,
                    'payment_date' => optional($payment->payment_date)->toDateString(),
                    'direction' => $payment->direction->value,
                    'net_amount_cents' => (int) $payment->net_amount_cents,
                    'penalty_cents' => (int) $payment->penalty_cents,
                    'interest_cents' => (int) $payment->interest_cents,
                    'commission_cents' => (int) $payment->commission_cents,
                    'total_cents' => (int) $payment->total_cents,
                    'tax_return_id' => (int) $payment->tax_return_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(TaxReturnPayment $payment, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($payment, $voidDate) {
            $payment->loadMissing('journalEntry');

            if (! $payment->journal_entry_id) {
                throw new RuntimeException('Tax return payment is not posted.');
            }

            if ($payment->status === TaxReturnPaymentStatus::Void) {
                throw new RuntimeException('Tax return payment is already voided.');
            }

            $this->journalPoster->void(
                $payment->journalEntry,
                $voidDate,
                "Void of tax return payment {$payment->payment_no}",
            );

            $payment->forceFill([
                'status' => TaxReturnPaymentStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $this->auditRecorder->record(
                (int) $payment->company_id,
                AuditAction::TaxReturnPaymentVoided,
                $payment,
                [
                    'payment_no' => $payment->payment_no,
                    'voided_at' => optional($payment->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $payment->journal_entry_id,
                ],
                $payment->journalEntry,
            );
        }));
    }

    protected function validate(TaxReturnPayment $payment): void
    {
        if ((int) $payment->net_amount_cents < 0) {
            throw new RuntimeException('Net amount must be zero or positive.');
        }

        if ((int) $payment->net_amount_cents === 0
            && (int) $payment->penalty_cents === 0
            && (int) $payment->interest_cents === 0
            && (int) $payment->commission_cents === 0) {
            throw new RuntimeException('Payment has zero amount; nothing to post.');
        }

        if ($payment->direction === TaxReturnPaymentDirection::Outgoing) {
            if ((int) $payment->penalty_cents > 0 && ! $payment->penalty_account_id) {
                throw new RuntimeException('Penalty account is required when penalty amount is set.');
            }

            if ((int) $payment->interest_cents > 0 && ! $payment->interest_account_id) {
                throw new RuntimeException('Interest account is required when interest amount is set.');
            }

            if ((int) $payment->commission_cents > 0 && ! $payment->commission_account_id) {
                throw new RuntimeException('Commission account is required when commission amount is set.');
            }
        } else {
            if ((int) $payment->penalty_cents > 0 || (int) $payment->commission_cents > 0) {
                throw new RuntimeException('Penalty and commission are only valid on outgoing payments.');
            }

            if ((int) $payment->interest_cents > 0 && ! $payment->interest_account_id) {
                throw new RuntimeException('Interest income account is required when interest amount is set.');
            }
        }

        $bank = Account::withoutGlobalScopes()->find($payment->bank_account_id);

        if (! $bank) {
            throw new RuntimeException('Bank account not found.');
        }
    }

    protected function memoFor(TaxReturnPayment $payment): string
    {
        $direction = $payment->direction === TaxReturnPaymentDirection::Outgoing ? 'Payment' : 'Refund';
        $agency = $payment->taxReturn->taxAgency->name;

        return "Tax {$direction} — {$agency} — {$payment->taxReturn->tax_return_no}";
    }
}
