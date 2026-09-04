<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\BillPaymentStatus;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\BillPaymentApplication;
use App\Models\JournalEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a bill payment to the GL.
 *   DR  Accounts Payable / Reimbursements Payable    amount
 *   CR    Bank account (paid_from_account_id)         amount
 * Applications update bill.amount_paid_cents and status (posted → partial → paid).
 */
class BillPaymentPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected ControlAccountResolver $controlAccounts,
        protected ExchangeRateService $exchangeRates,
    ) {}

    public function post(BillPayment $payment): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($payment) {
            $payment->loadMissing('applications.bill', 'contact', 'company');

            if ($payment->journal_entry_id) {
                throw AlreadyPostedException::for((int) $payment->journal_entry_id);
            }

            if ($payment->company->isLockedFor(CarbonImmutable::parse($payment->payment_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($payment->payment_date),
                    CarbonImmutable::parse($payment->company->lock_date),
                );
            }

            if ((int) $payment->amount_cents <= 0) {
                throw new RuntimeException('Payment amount must be positive.');
            }

            $totalApplied = (int) $payment->applications->sum('amount_cents');

            if ($totalApplied > (int) $payment->amount_cents) {
                throw new RuntimeException('Applied amount exceeds payment total.');
            }

            $control = $this->controlAccount($payment);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($payment->company),
                'entry_date' => $payment->payment_date,
                'memo' => 'Payment '.$payment->payment_no.' — '.$payment->contact->display_name,
                'source_type' => BillPayment::class,
                'source_id' => $payment->id,
            ]);

            $this->buildPaymentLines($entry, $payment, $control);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $payment->forceFill([
                'status' => BillPaymentStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->applyToBills($payment);

            $payment->contact->recomputeApBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $payment->company_id,
                AuditAction::BillPaymentPosted,
                $payment,
                [
                    'payment_no' => $payment->payment_no,
                    'payment_date' => optional($payment->payment_date)->toDateString(),
                    'amount_cents' => (int) $payment->amount_cents,
                    'contact_id' => (int) $payment->contact_id,
                    'paid_from_account_id' => (int) $payment->paid_from_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Re-post a posted bill payment in place after the user edits it.
     * GL is updated atomically; bill amount_paid/status is recomputed from
     * all live applications across receipts so it stays in sync no matter
     * what changes in this payment's applications.
     */
    public function repost(BillPayment $payment): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($payment) {
            $payment->loadMissing('applications.bill', 'contact', 'company', 'journalEntry.lines');

            if (! $payment->journal_entry_id) {
                throw new RuntimeException('Payment has not been posted yet — call post() instead.');
            }

            if ($payment->status === BillPaymentStatus::Void) {
                throw new RuntimeException('Cannot repost a voided payment.');
            }

            $entry = $payment->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $payment->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($payment->payment_date);

            if ($payment->company->isLockedFor($originalEntryDate)) {
                throw PeriodLockedException::for($originalEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ($payment->company->isLockedFor($newEntryDate)) {
                throw PeriodLockedException::for($newEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ((int) $payment->amount_cents <= 0) {
                throw new RuntimeException('Payment amount must be positive.');
            }

            $totalApplied = (int) $payment->applications->sum('amount_cents');

            if ($totalApplied > (int) $payment->amount_cents) {
                throw new RuntimeException('Applied amount exceeds payment total.');
            }

            $touchedBillIds = $payment->applications->pluck('bill_id')->all();
            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            $control = $this->controlAccount($payment);

            $entry->forceFill([
                'entry_date' => $payment->payment_date,
                'memo' => 'Payment '.$payment->payment_no.' — '.$payment->contact->display_name,
            ])->save();

            $entry->lines()->delete();

            $this->buildPaymentLines($entry, $payment, $control);

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from(
                    $entry->totalDebitsCents(),
                    $entry->totalCreditsCents(),
                );
            }

            $newAccountIds = $entry->lines->pluck('account_id')->all();
            foreach (array_unique(array_merge($oldAccountIds, $newAccountIds)) as $id) {
                Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
            }

            // Recompute each touched bill's amount_paid from the canonical
            // ledger of live applications across ALL posted payments.
            foreach ($touchedBillIds as $billId) {
                $bill = Bill::withoutGlobalScopes()->find($billId);
                if (! $bill) {
                    continue;
                }
                $this->recomputeBillPaidFromAllPayments($bill);
            }

            $payment->contact->recomputeApBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $payment->company_id,
                AuditAction::BillPaymentReposted,
                $payment,
                [
                    'payment_no' => $payment->payment_no,
                    'amount_cents' => (int) $payment->amount_cents,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    protected function recomputeBillPaidFromAllPayments(Bill $bill): void
    {
        $paid = (int) BillPaymentApplication::query()
            ->whereHas('payment', fn ($q) => $q->where('status', BillPaymentStatus::Posted->value))
            ->where('bill_id', $bill->id)
            ->sum('amount_cents');

        $bill->forceFill([
            'amount_paid_cents' => min($paid, (int) $bill->total_cents),
        ])->save();

        $this->refreshBillStatus($bill);
        $bill->contact?->recomputeApBalance();
    }

    public function void(BillPayment $payment, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($payment, $voidDate) {
            $payment->loadMissing('journalEntry', 'applications.bill');

            if (! $payment->journal_entry_id) {
                throw new RuntimeException('Payment is not posted.');
            }

            if ($payment->status === BillPaymentStatus::Void) {
                throw new RuntimeException('Payment is already voided.');
            }

            $this->journalPoster->void($payment->journalEntry, $voidDate, "Void of payment {$payment->payment_no}");

            foreach ($payment->applications as $app) {
                $bill = $app->bill;

                $bill->forceFill([
                    'amount_paid_cents' => max(0, (int) $bill->amount_paid_cents - (int) $app->amount_cents),
                ])->save();

                $this->refreshBillStatus($bill);
                $bill->contact->recomputeApBalance();
            }

            $payment->forceFill([
                'status' => BillPaymentStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $payment->contact->recomputeApBalance();

            $this->auditRecorder->record(
                (int) $payment->company_id,
                AuditAction::BillPaymentVoided,
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

    /**
     * Build the payment's journal lines: debit the AP control, credit the bank.
     * For a foreign payment the AP is cleared at each bill's locked rate while the
     * bank is credited the home value at the payment rate; the home-cents residual
     * is the realized exchange gain/loss.
     */
    protected function buildPaymentLines(JournalEntry $entry, BillPayment $payment, Account $control): void
    {
        if (! $payment->isForeignCurrency()) {
            $entry->lines()->create([
                'account_id' => $control->id,
                'debit_cents' => $payment->amount_cents,
                'credit_cents' => 0,
                'memo' => $payment->payment_type->label().' — '.$payment->contact->display_name,
                'contact_id' => $payment->contact_id,
                'line_order' => 0,
            ]);

            $entry->lines()->create([
                'account_id' => $payment->paid_from_account_id,
                'debit_cents' => 0,
                'credit_cents' => $payment->amount_cents,
                'memo' => 'Payment',
                'line_order' => 1,
            ]);

            return;
        }

        $amount = (int) $payment->amount_cents;
        $currency = mb_strtoupper((string) $payment->currency_code);
        $ratePay = $this->lockPaymentRate($payment);

        $bankHome = Currency::toHomeCents($amount, $ratePay);
        $order = 0;
        $apNetDebitHome = 0;
        $appliedForeign = 0;

        foreach ($payment->applications as $application) {
            $foreign = (int) $application->amount_cents;
            $billRate = (string) ($application->bill?->fx_rate ?? $ratePay);
            $home = Currency::toHomeCents($foreign, $billRate);
            $appliedForeign += $foreign;
            $apNetDebitHome += $home;

            $entry->lines()->create([
                'account_id' => $control->id,
                'debit_cents' => $home,
                'credit_cents' => 0,
                'memo' => $payment->payment_type->label().' — '.$payment->contact->display_name,
                'contact_id' => $payment->contact_id,
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $billRate, $foreign, 0),
            ]);
        }

        $remainderForeign = $amount - $appliedForeign;

        if ($remainderForeign !== 0) {
            $home = Currency::toHomeCents($remainderForeign, $ratePay);
            $apNetDebitHome += $home;

            $entry->lines()->create([
                'account_id' => $control->id,
                'debit_cents' => max($home, 0),
                'credit_cents' => max(-$home, 0),
                'memo' => $payment->payment_type->label().' — '.$payment->contact->display_name,
                'contact_id' => $payment->contact_id,
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $ratePay, max($remainderForeign, 0), max(-$remainderForeign, 0)),
            ]);
        }

        $entry->lines()->create([
            'account_id' => $payment->paid_from_account_id,
            'debit_cents' => 0,
            'credit_cents' => $bankHome,
            'memo' => 'Payment',
            'line_order' => $order++,
        ]);

        // Realized FX residual balances the entry in home cents.
        $residual = $bankHome - $apNetDebitHome;

        if ($residual !== 0) {
            $entry->lines()->create([
                'account_id' => $this->exchangeGainLossAccountId($payment),
                'debit_cents' => max($residual, 0),
                'credit_cents' => max(-$residual, 0),
                'memo' => 'Realized exchange '.($residual > 0 ? 'loss' : 'gain'),
                'line_order' => $order++,
            ]);
        }
    }

    protected function lockPaymentRate(BillPayment $payment): string
    {
        if ($payment->fx_rate !== null) {
            return (string) $payment->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $payment->company,
            (string) $payment->currency_code,
            CarbonImmutable::parse($payment->payment_date),
        );

        $payment->forceFill([
            'fx_rate' => $rate,
            'home_amount_cents' => Currency::toHomeCents((int) $payment->amount_cents, $rate),
        ])->save();

        return $rate;
    }

    protected function exchangeGainLossAccountId(BillPayment $payment): int
    {
        $accountId = $payment->company->exchange_gain_loss_account_id;

        if ($accountId === null) {
            throw new RuntimeException("Company {$payment->company_id} has no Exchange Gain/Loss account; enable a foreign currency first.");
        }

        return (int) $accountId;
    }

    protected function applyToBills(BillPayment $payment): void
    {
        foreach ($payment->applications as $app) {
            // Re-fetch the bill under a row lock instead of incrementing the
            // in-memory relation. Two payments posting against the same bill
            // concurrently would otherwise read the same amount_paid_cents and
            // one write would clobber the other (lost update). lockForUpdate
            // serializes them so each adds to the latest committed value. (It is
            // a no-op on SQLite, which runs serially in tests anyway.)
            $bill = Bill::withoutGlobalScopes()
                ->whereKey($app->bill_id)
                ->lockForUpdate()
                ->first();

            if ($bill === null) {
                continue;
            }

            $newPaid = (int) $bill->amount_paid_cents + (int) $app->amount_cents;

            $bill->forceFill([
                'amount_paid_cents' => min($newPaid, (int) $bill->total_cents),
            ])->save();

            $this->refreshBillStatus($bill);
        }
    }

    protected function refreshBillStatus(Bill $bill): void
    {
        if ($bill->balanceCents() <= 0) {
            $bill->status = BillStatus::Paid;
        } elseif ($bill->settledCents() > 0) {
            $bill->status = BillStatus::Partial;
        } else {
            $bill->status = BillStatus::Posted;
        }

        $bill->save();
    }

    protected function controlAccount(BillPayment $payment): Account
    {
        if ($payment->payment_type === BillType::Vendor) {
            return $this->controlAccounts->resolve($payment->company, AccountSubtype::AccountsPayable, $payment->currency_code);
        }

        $account = Account::withoutGlobalScopes()
            ->where('company_id', $payment->company_id)
            ->employeeReimbursementsPayable()
            ->first();

        if (! $account) {
            throw new RuntimeException("Missing system control account for payment type [{$payment->payment_type->value}].");
        }

        return $account;
    }
}
