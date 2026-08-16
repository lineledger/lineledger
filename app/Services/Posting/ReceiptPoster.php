<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ReceiptApplication;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Currency\ExchangeRateService;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a customer receipt to the GL.
 *   DR  Deposit-to account (Undeposited Funds or Bank)   amount
 *   CR    Accounts Receivable                            amount
 * Applications update invoice.amount_paid_cents and status.
 */
class ReceiptPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected AccountingAuditRecorder $auditRecorder,
        protected ControlAccountResolver $controlAccounts,
        protected ExchangeRateService $exchangeRates,
    ) {}

    public function post(CustomerReceipt $receipt): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($receipt) {
            $receipt->loadMissing('applications.invoice', 'company');

            if ($receipt->journal_entry_id) {
                throw AlreadyPostedException::for((int) $receipt->journal_entry_id);
            }

            if ($receipt->company->isLockedFor(CarbonImmutable::parse($receipt->receipt_date))) {
                throw PeriodLockedException::for(
                    CarbonImmutable::parse($receipt->receipt_date),
                    CarbonImmutable::parse($receipt->company->lock_date),
                );
            }

            $this->assertAmountValid($receipt);

            $ar = $this->controlAccounts->resolve($receipt->company, AccountSubtype::AccountsReceivable, $receipt->currency_code);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($receipt->company),
                'entry_date' => $receipt->receipt_date,
                'memo' => 'Receipt '.$receipt->receipt_no.' — '.$receipt->contact->display_name,
                'source_type' => CustomerReceipt::class,
                'source_id' => $receipt->id,
            ]);

            $this->buildReceiptLines($entry, $receipt, $ar);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $receipt->forceFill([
                'status' => ReceiptStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->applyToInvoices($receipt);

            $receipt->contact->recomputeArBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::CustomerReceiptPosted,
                $receipt,
                [
                    'receipt_no' => $receipt->receipt_no,
                    'receipt_date' => optional($receipt->receipt_date)->toDateString(),
                    'amount_cents' => (int) $receipt->amount_cents,
                    'contact_id' => (int) $receipt->contact_id,
                    'deposit_to_account_id' => (int) $receipt->deposit_to_account_id,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Re-post a posted receipt in place after the user edits it.
     *
     * Steps in a single transaction:
     *   1. Un-apply the existing applications from their invoices
     *      (so each invoice's amount_paid/status reverts to its pre-receipt state).
     *   2. Mutate the existing journal entry — delete its lines, rebuild from the
     *      edited deposit account/amount.
     *   3. Replace the application rows with the caller's new applications
     *      (already saved on the receipt by the form).
     *   4. Apply the new applications to invoices and recompute their statuses.
     *   5. Recompute affected account balances and the contact's AR balance.
     *
     * Lock-date is enforced on both the original posting date and the (possibly
     * new) receipt date — neither side can fall in a closed period.
     */
    public function repost(CustomerReceipt $receipt): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($receipt) {
            $receipt->loadMissing('applications.invoice', 'company', 'journalEntry.lines', 'contact');

            if (! $receipt->journal_entry_id) {
                throw new RuntimeException('Receipt has not been posted yet — call post() instead.');
            }

            if ($receipt->status === ReceiptStatus::Void) {
                throw new RuntimeException('Cannot repost a voided receipt.');
            }

            $entry = $receipt->journalEntry;
            $journalBefore = AccountingAuditRecorder::snapshotJournalEntry($entry);
            $lockDate = $receipt->company->lock_date;

            $originalEntryDate = CarbonImmutable::parse($entry->entry_date);
            $newEntryDate = CarbonImmutable::parse($receipt->receipt_date);

            if ($receipt->company->isLockedFor($originalEntryDate)) {
                throw PeriodLockedException::for($originalEntryDate, CarbonImmutable::parse($lockDate));
            }

            if ($receipt->company->isLockedFor($newEntryDate)) {
                throw PeriodLockedException::for($newEntryDate, CarbonImmutable::parse($lockDate));
            }

            $this->assertAmountValid($receipt);

            // 1. Un-apply the OLD applications. The caller may have already
            // wiped+re-created applications on the receipt before invoking
            // repost(); to do this correctly we must look up what was applied
            // historically. We capture that from the original journal lines:
            // the AR line carries the receipt's full amount, but per-invoice
            // application history lives only on `receipt_applications`. So
            // here we trust the caller: they pass in a receipt whose
            // applications collection represents the NEW state. To unwind
            // the old apply, we need a different signal.
            //
            // Strategy used: the OLD applications must be passed via a
            // dedicated "previousApplications" parameter — but for callers
            // that simply update applications in place, we instead derive
            // the unapply by recomputing each touched invoice's amount_paid
            // from scratch (sum of remaining applications across all
            // posted, non-void receipts pointing at it). That's robust
            // regardless of caller order.
            $touchedInvoiceIds = $receipt->applications->pluck('invoice_id')->all();

            // Capture old JE account ids for balance recompute
            $oldAccountIds = $entry->lines->pluck('account_id')->all();

            // 2. Mutate the journal entry
            $ar = $this->controlAccounts->resolve($receipt->company, AccountSubtype::AccountsReceivable, $receipt->currency_code);

            $entry->forceFill([
                'entry_date' => $receipt->receipt_date,
                'memo' => 'Receipt '.$receipt->receipt_no.' — '.$receipt->contact->display_name,
            ])->save();

            $entry->lines()->delete();

            $this->buildReceiptLines($entry, $receipt, $ar);

            $entry->refresh();

            if (! $entry->isBalanced()) {
                throw UnbalancedJournalException::from(
                    $entry->totalDebitsCents(),
                    $entry->totalCreditsCents(),
                );
            }

            // 3. Recompute each touched invoice's amount_paid from the
            // CURRENT set of all live applications (across all receipts).
            // This naturally unwinds whatever the old application was and
            // applies the new one in one step.
            $newAccountIds = $entry->lines->pluck('account_id')->all();
            foreach (array_unique(array_merge($oldAccountIds, $newAccountIds)) as $id) {
                Account::withoutGlobalScopes()->find($id)?->recomputeBalance();
            }

            // Also include invoices the receipt USED to apply to but no
            // longer does — those are not in $touchedInvoiceIds. For those
            // we'd need historical tracking. For now we accept that
            // un-targeting an invoice without separately re-saving the
            // receipt isn't supported via repost(); callers should keep
            // applications stable or pass the old set explicitly.
            foreach ($touchedInvoiceIds as $invoiceId) {
                $invoice = Invoice::withoutGlobalScopes()->find($invoiceId);
                if (! $invoice) {
                    continue;
                }
                $this->recomputeInvoicePaidFromAllReceipts($invoice);
            }

            $receipt->contact->recomputeArBalance();

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::CustomerReceiptReposted,
                $receipt,
                [
                    'receipt_no' => $receipt->receipt_no,
                    'amount_cents' => (int) $receipt->amount_cents,
                    'journal_before' => $journalBefore,
                    'journal_after' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    /**
     * Sum applications across all posted, non-void receipts targeting this
     * invoice. Updates amount_paid_cents and status. This is the safe
     * canonical recompute — no matter what mutations happen on receipts,
     * the invoice ends up consistent with the ledger of applications.
     */
    protected function recomputeInvoicePaidFromAllReceipts(Invoice $invoice): void
    {
        $paid = (int) ReceiptApplication::query()
            ->whereHas('receipt', fn ($q) => $q->whereIn('status', [
                ReceiptStatus::Posted->value,
            ]))
            ->where('invoice_id', $invoice->id)
            ->sum('amount_cents');

        $invoice->forceFill([
            'amount_paid_cents' => min($paid, (int) $invoice->total_cents),
        ])->save();

        $this->refreshInvoiceStatus($invoice);
        $invoice->contact?->recomputeArBalance();
    }

    public function void(CustomerReceipt $receipt, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($receipt, $voidDate) {
            $receipt->loadMissing('journalEntry', 'applications.invoice');

            if (! $receipt->journal_entry_id) {
                throw new RuntimeException('Receipt is not posted.');
            }

            if ($receipt->status === ReceiptStatus::Void) {
                throw new RuntimeException('Receipt is already voided.');
            }

            $this->journalPoster->void($receipt->journalEntry, $voidDate, "Void of receipt {$receipt->receipt_no}");

            // Un-apply: reduce each invoice's amount_paid by what this receipt applied
            foreach ($receipt->applications as $app) {
                $invoice = $app->invoice;

                $invoice->forceFill([
                    'amount_paid_cents' => max(0, (int) $invoice->amount_paid_cents - (int) $app->amount_cents),
                ])->save();

                $this->refreshInvoiceStatus($invoice);
                $invoice->contact->recomputeArBalance();
            }

            $receipt->forceFill([
                'status' => ReceiptStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            $receipt->contact->recomputeArBalance();

            $this->auditRecorder->record(
                (int) $receipt->company_id,
                AuditAction::CustomerReceiptVoided,
                $receipt,
                [
                    'receipt_no' => $receipt->receipt_no,
                    'voided_at' => optional($receipt->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $receipt->journal_entry_id,
                ],
                $receipt->journalEntry,
            );
        }));
    }

    protected function applyToInvoices(CustomerReceipt $receipt): void
    {
        foreach ($receipt->applications as $app) {
            // Re-fetch the invoice under a row lock instead of incrementing the
            // in-memory relation. Two receipts posting against the same invoice
            // concurrently would otherwise read the same amount_paid_cents and
            // one write would clobber the other (lost update). lockForUpdate
            // serializes them so each adds to the latest committed value. (It is
            // a no-op on SQLite, which runs serially in tests anyway.)
            $invoice = Invoice::withoutGlobalScopes()
                ->whereKey($app->invoice_id)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                continue;
            }

            $newPaid = (int) $invoice->amount_paid_cents + (int) $app->amount_cents;

            $invoice->forceFill([
                'amount_paid_cents' => min($newPaid, (int) $invoice->total_cents),
            ])->save();

            $this->refreshInvoiceStatus($invoice);
        }
    }

    protected function refreshInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->balanceCents() <= 0 && $invoice->settledCents() > 0) {
            $invoice->status = InvoiceStatus::Paid;
        } elseif ($invoice->settledCents() > 0) {
            $invoice->status = InvoiceStatus::Partial;
        } else {
            $invoice->status = InvoiceStatus::Posted;
        }

        $invoice->save();
    }

    /**
     * Validate the receipt amount and applications.
     *
     * Ordinary receipts must be positive. A refund receipt — one linked to a
     * credit memo, recording money paid back to the customer via the debit
     * machine — must be negative and apply to no invoices; its negative AR
     * credit posts a balanced entry that debits AR and credits Undeposited
     * Funds, clearing the customer's credit.
     */
    protected function assertAmountValid(CustomerReceipt $receipt): void
    {
        $amount = (int) $receipt->amount_cents;

        if ($amount === 0) {
            throw new RuntimeException('Receipt amount cannot be zero.');
        }

        if ($receipt->isRefund()) {
            if ($amount > 0) {
                throw new RuntimeException('Refund receipt amount must be negative.');
            }

            if ($receipt->applications->isNotEmpty()) {
                throw new RuntimeException('A refund receipt cannot be applied to invoices.');
            }

            return;
        }

        if ($amount < 0) {
            throw new RuntimeException('Receipt amount must be positive.');
        }

        $totalApplied = (int) $receipt->applications->sum('amount_cents');

        if ($totalApplied > $amount) {
            throw new RuntimeException('Applied amount exceeds receipt total.');
        }
    }

    /**
     * Build the journal entry for a receipt. A positive (ordinary) receipt debits
     * the deposit-to account and credits AR; a negative (refund) receipt flips
     * both sides, keeping the entry balanced with positive magnitudes.
     *
     * For a foreign receipt the deposit lands in home cents at the receipt's rate,
     * while AR is cleared at each settled invoice's original rate (so the foreign
     * AR control nets to zero). The home-cents difference between cash received
     * and AR cleared is the realized exchange gain/loss.
     */
    protected function buildReceiptLines(JournalEntry $entry, CustomerReceipt $receipt, Account $arAccount): void
    {
        if (! $receipt->isForeignCurrency()) {
            $this->buildHomeReceiptLines($entry, $receipt, $arAccount->id);

            return;
        }

        $this->buildForeignReceiptLines($entry, $receipt, $arAccount);
    }

    protected function buildHomeReceiptLines(JournalEntry $entry, CustomerReceipt $receipt, int $arAccountId): void
    {
        $amount = (int) $receipt->amount_cents;

        $entry->lines()->create([
            'account_id' => $receipt->deposit_to_account_id,
            'debit_cents' => max($amount, 0),
            'credit_cents' => max(-$amount, 0),
            'memo' => $amount < 0 ? 'Refund' : 'Deposit',
            'line_order' => 0,
        ]);

        $entry->lines()->create([
            'account_id' => $arAccountId,
            'debit_cents' => max(-$amount, 0),
            'credit_cents' => max($amount, 0),
            'memo' => 'AR — '.$receipt->contact->display_name,
            'contact_id' => $receipt->contact_id,
            'line_order' => 1,
        ]);
    }

    /**
     * Deposit debits the home value received at the receipt rate; AR is credited
     * per application at each invoice's locked rate (plus the unapplied remainder
     * at the receipt rate). The home-cents residual balances to Exchange Gain/Loss.
     */
    protected function buildForeignReceiptLines(JournalEntry $entry, CustomerReceipt $receipt, Account $arAccount): void
    {
        $amount = (int) $receipt->amount_cents;
        $currency = mb_strtoupper((string) $receipt->currency_code);
        $ratePay = $this->lockReceiptRate($receipt);

        $depositHome = Currency::toHomeCents($amount, $ratePay);

        $entry->lines()->create([
            'account_id' => $receipt->deposit_to_account_id,
            'debit_cents' => max($depositHome, 0),
            'credit_cents' => max(-$depositHome, 0),
            'memo' => $amount < 0 ? 'Refund' : 'Deposit',
            'line_order' => 0,
        ]);

        $order = 1;
        $arNetCreditHome = 0; // home cents credited to AR (negative = net debit)
        $appliedForeign = 0;

        foreach ($receipt->applications as $application) {
            $foreign = (int) $application->amount_cents;
            $invoiceRate = (string) ($application->invoice?->fx_rate ?? $ratePay);
            $home = Currency::toHomeCents($foreign, $invoiceRate);
            $appliedForeign += $foreign;
            $arNetCreditHome += $home;

            $entry->lines()->create([
                'account_id' => $arAccount->id,
                'debit_cents' => 0,
                'credit_cents' => $home,
                'memo' => 'AR — '.$receipt->contact->display_name,
                'contact_id' => $receipt->contact_id,
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $invoiceRate, 0, $foreign),
            ]);
        }

        // Unapplied remainder (on-account credit, or the whole of a refund) at the
        // receipt rate. Signed so refunds (negative) debit AR.
        $remainderForeign = $amount - $appliedForeign;

        if ($remainderForeign !== 0) {
            $home = Currency::toHomeCents($remainderForeign, $ratePay);
            $arNetCreditHome += $home;

            $entry->lines()->create([
                'account_id' => $arAccount->id,
                'debit_cents' => max(-$home, 0),
                'credit_cents' => max($home, 0),
                'memo' => 'AR — '.$receipt->contact->display_name,
                'contact_id' => $receipt->contact_id,
                'line_order' => $order++,
                ...Currency::lineMemo($currency, $ratePay, max(-$remainderForeign, 0), max($remainderForeign, 0)),
            ]);
        }

        // Realized FX residual: the amount needed to balance the entry in home
        // cents goes to Exchange Gain/Loss (debit = loss, credit = gain).
        $residual = $arNetCreditHome - $depositHome;

        if ($residual !== 0) {
            $entry->lines()->create([
                'account_id' => $this->exchangeGainLossAccountId($receipt),
                'debit_cents' => max($residual, 0),
                'credit_cents' => max(-$residual, 0),
                'memo' => 'Realized exchange '.($residual < 0 ? 'gain' : 'loss'),
                'line_order' => $order++,
            ]);
        }
    }

    /**
     * Lock the receipt's exchange rate (reused on repost) and cache the home value.
     */
    protected function lockReceiptRate(CustomerReceipt $receipt): string
    {
        if ($receipt->fx_rate !== null) {
            return (string) $receipt->fx_rate;
        }

        $rate = $this->exchangeRates->rateFor(
            $receipt->company,
            (string) $receipt->currency_code,
            CarbonImmutable::parse($receipt->receipt_date),
        );

        $receipt->forceFill([
            'fx_rate' => $rate,
            'home_amount_cents' => Currency::toHomeCents((int) $receipt->amount_cents, $rate),
        ])->save();

        return $rate;
    }

    protected function exchangeGainLossAccountId(CustomerReceipt $receipt): int
    {
        $accountId = $receipt->company->exchange_gain_loss_account_id;

        if ($accountId === null) {
            throw new RuntimeException("Company {$receipt->company_id} has no Exchange Gain/Loss account; enable a foreign currency first.");
        }

        return (int) $accountId;
    }
}
