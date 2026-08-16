<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes invoice document balances that the general ledger has *already* settled
 * outside the receipt system — e.g. a write-off or rounding adjustment booked as a
 * journal entry against the AR control account (common after a full-history import).
 *
 * It posts NO journal entry: the GL is already correct, only the invoice document
 * lags. The amount closed is recorded in {@see Invoice::$reconciled_cents}, distinct
 * from amount_paid_cents (which the receipt poster owns).
 *
 * Hard safety rule: a customer's open-invoice balances are never reconciled below
 * their GL AR balance. We only ever close the "phantom" balance — the difference
 * between what the invoice documents show open and what the ledger actually carries —
 * so a customer who genuinely still owes money is never marked paid.
 */
class InvoiceReconciler
{
    public function __construct(protected AccountingAuditRecorder $auditRecorder) {}

    /**
     * Close as much of this invoice's balance as the ledger has already settled for
     * its customer. Returns the cents reconciled (0 if nothing was eligible).
     */
    public function reconcileInvoice(Invoice $invoice): int
    {
        if ($invoice->contact_id === null || ! $invoice->status->isOpen()) {
            return 0;
        }

        $company = $invoice->company;
        $asOf = $company->currentDateTime();

        $available = $this->availableToReconcile($company, (int) $invoice->contact_id, $asOf);
        $amount = min($invoice->balanceCents(), $available);

        if ($amount <= 0) {
            return 0;
        }

        $this->applyReconciliation($invoice, $amount);
        $invoice->contact?->recomputeArBalance();

        return $amount;
    }

    /**
     * Reconcile every customer's ledger-settled balance across their open invoices,
     * oldest first. One-pass cleanup for a migrated book.
     *
     * @return array{invoices: int, cents: int}
     */
    public function reconcileCompany(Company $company): array
    {
        $asOf = $company->currentDateTime();
        $arAccountIds = $this->arAccountIds($company);

        if ($arAccountIds === []) {
            return ['invoices' => 0, 'cents' => 0];
        }

        $openByContact = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->whereNotNull('contact_id')
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->groupBy('contact_id');

        $invoiceCount = 0;
        $totalCents = 0;

        foreach ($openByContact as $contactId => $invoices) {
            $foreign = $this->contactIsForeign($company, (int) $contactId);
            $openDoc = (int) $invoices->sum(fn (Invoice $i) => $i->balanceCents());
            $gl = $this->glArForContact($company, $arAccountIds, (int) $contactId, $asOf, $foreign);
            $available = max(0, $openDoc - $gl);

            $reconciledHere = false;

            foreach ($invoices as $invoice) {
                if ($available <= 0) {
                    break;
                }

                $amount = min($invoice->balanceCents(), $available);

                if ($amount <= 0) {
                    continue;
                }

                $this->applyReconciliation($invoice, $amount);

                $available -= $amount;
                $totalCents += $amount;
                $invoiceCount++;
                $reconciledHere = true;
            }

            if ($reconciledHere) {
                $invoices->first()->contact?->recomputeArBalance();
            }
        }

        return ['invoices' => $invoiceCount, 'cents' => $totalCents];
    }

    /**
     * Release reconciled balance that the ledger no longer supports — e.g. after the
     * credit memo (or write-off JE) that settled an invoice is voided, raising the
     * customer's GL AR back above their open-document balance. Re-opens invoices so
     * their net balance never sits below GL AR. Returns the cents released.
     *
     * The mirror of {@see reconcileInvoice}: that closes the phantom (docs > GL), this
     * releases over-reconciliation (GL > net docs). Together they keep the invoice
     * document balance pinned to the ledger after any AR-changing event.
     */
    public function releaseExcessReconciliation(Company $company, int $contactId): int
    {
        $arAccountIds = $this->arAccountIds($company);

        if ($arAccountIds === []) {
            return 0;
        }

        $asOf = $company->currentDateTime();
        $foreign = $this->contactIsForeign($company, $contactId);
        $gl = $this->glArForContact($company, $arAccountIds, $contactId, $asOf, $foreign);

        // Invoices carrying reconciliation (newest first, so the most recently closed
        // balance re-opens first), plus the gross owing across the customer's book.
        $invoices = Invoice::query()
            ->where('contact_id', $contactId)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value, InvoiceStatus::Paid->value])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        // Gross document owing ignores reconciliation (total − cash paid).
        $grossOpen = (int) $invoices->sum(fn (Invoice $i) => (int) $i->total_cents - (int) $i->amount_paid_cents);
        $currentReconciled = (int) $invoices->sum(fn (Invoice $i) => (int) $i->reconciled_cents);

        $allowed = max(0, $grossOpen - $gl);
        $excess = $currentReconciled - $allowed;

        if ($excess <= 0) {
            return 0;
        }

        $released = 0;

        foreach ($invoices as $invoice) {
            if ($excess <= 0) {
                break;
            }

            $reconciled = (int) $invoice->reconciled_cents;

            if ($reconciled <= 0) {
                continue;
            }

            $release = min($reconciled, $excess);

            $this->applyReconciliation($invoice, -$release);

            $excess -= $release;
            $released += $release;
        }

        if ($released > 0) {
            Contact::find($contactId)?->recomputeArBalance();
        }

        return $released;
    }

    /**
     * The portion of a customer's open-invoice document balance that the ledger has
     * already settled — i.e. open-document AR minus GL AR, floored at zero.
     */
    public function availableToReconcile(Company $company, int $contactId, CarbonImmutable $asOf): int
    {
        $arAccountIds = $this->arAccountIds($company);

        if ($arAccountIds === []) {
            return 0;
        }

        $openDoc = (int) Invoice::query()
            ->where('contact_id', $contactId)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->get()
            ->sum(fn (Invoice $i) => $i->balanceCents());

        $gl = $this->glArForContact($company, $arAccountIds, $contactId, $asOf, $this->contactIsForeign($company, $contactId));

        return max(0, $openDoc - $gl);
    }

    /**
     * Apply a reconciliation delta (positive closes the phantom, negative releases an
     * over-reconciliation) and re-derive the invoice status from what is settled.
     */
    private function applyReconciliation(Invoice $invoice, int $amount): void
    {
        AuditMute::silence(function () use ($invoice, $amount): void {
            $invoice->reconciled_cents = max(0, (int) $invoice->reconciled_cents + $amount);

            if ($invoice->balanceCents() <= 0) {
                $invoice->status = InvoiceStatus::Paid;
            } elseif ($invoice->settledCents() > 0) {
                $invoice->status = InvoiceStatus::Partial;
            } else {
                // Nothing settled — the invoice is fully open again.
                $invoice->status = InvoiceStatus::Posted;
            }

            $invoice->save();
        });

        $this->auditRecorder->record(
            (int) $invoice->company_id,
            AuditAction::InvoiceReconciled,
            $invoice,
            [
                'invoice_no' => $invoice->invoice_no,
                'reconciled_cents' => $amount,
                'reconciled_total_cents' => (int) $invoice->reconciled_cents,
                'released' => $amount < 0,
                'new_status' => $invoice->status->value,
            ],
        );
    }

    /**
     * Customer's GL AR balance (debit − credit) as of the date, computed the same way
     * as the AR Aging report so the two always agree. For a foreign customer the sum
     * is taken over the foreign memo columns, so it stays in the document currency and
     * matches the foreign open-document balance it is compared against.
     *
     * @param  array<int, int>  $arAccountIds
     */
    private function glArForContact(Company $company, array $arAccountIds, int $contactId, CarbonImmutable $asOf, bool $foreign = false): int
    {
        $expression = $foreign
            ? 'jl.foreign_debit_cents - jl.foreign_credit_cents'
            : 'jl.debit_cents - jl.credit_cents';

        // Match the authoritative AR Aging report: include voided entries. Voiding posts
        // a reversing entry (which stays posted) and flags the original voided_at, so the
        // original + reversal net to zero. Excluding the voided original here while its
        // reversal remains would skew GL AR by the voided amount.
        return (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $arAccountIds)
            ->where('jl.contact_id', $contactId)
            ->where('je.entry_date', '<=', $asOf)
            ->sum(DB::raw($expression));
    }

    /**
     * Whether the contact transacts in a foreign currency, so the reconciler
     * compares like-for-like (foreign document balance vs foreign GL balance).
     */
    private function contactIsForeign(Company $company, int $contactId): bool
    {
        $code = Contact::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($contactId)->value('currency_code');

        return $code !== null && ! $company->isHomeCurrency($code);
    }

    /**
     * @return array<int, int>
     */
    private function arAccountIds(Company $company): array
    {
        return Account::query()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::AccountsReceivable->value)
            ->pluck('id')
            ->all();
    }
}
