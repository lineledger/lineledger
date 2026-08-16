<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes bill document balances that the general ledger has *already* settled
 * outside the bill-payment system — e.g. a write-off or rounding adjustment booked
 * as a journal entry against the AP control account (common after a full-history
 * import).
 *
 * It posts NO journal entry: the GL is already correct, only the bill document
 * lags. The amount closed is recorded in {@see Bill::$reconciled_cents}, distinct
 * from amount_paid_cents (which the bill-payment poster owns).
 *
 * Hard safety rule: a vendor's open-bill balances are never reconciled below their
 * GL AP balance. We only ever close the "phantom" balance — the difference between
 * what the bill documents show open and what the ledger actually carries — so a
 * vendor the company genuinely still owes is never marked paid.
 *
 * Mirror of {@see InvoiceReconciler}. AP is a liability (credit-normal), so the GL
 * balance is summed as credit − debit (the opposite sign from AR). Only vendor bills
 * hit the AP control account; reimbursements use a separate control account and are
 * excluded.
 */
class BillReconciler
{
    public function __construct(protected AccountingAuditRecorder $auditRecorder) {}

    /**
     * Close as much of this bill's balance as the ledger has already settled for its
     * vendor. Returns the cents reconciled (0 if nothing was eligible).
     */
    public function reconcileBill(Bill $bill): int
    {
        if ($bill->contact_id === null || ! $bill->status->isOpen() || $bill->bill_type !== BillType::Vendor) {
            return 0;
        }

        $company = $bill->company;
        $asOf = $company->currentDateTime();

        $available = $this->availableToReconcile($company, (int) $bill->contact_id, $asOf);
        $amount = min($bill->balanceCents(), $available);

        if ($amount <= 0) {
            return 0;
        }

        $this->applyReconciliation($bill, $amount);
        $bill->contact?->recomputeApBalance();

        return $amount;
    }

    /**
     * Reconcile every vendor's ledger-settled balance across their open bills, oldest
     * first. One-pass cleanup for a migrated book.
     *
     * @return array{bills: int, cents: int}
     */
    public function reconcileCompany(Company $company): array
    {
        $asOf = $company->currentDateTime();
        $apAccountIds = $this->apAccountIds($company);

        if ($apAccountIds === []) {
            return ['bills' => 0, 'cents' => 0];
        }

        $openByContact = Bill::query()
            ->vendor()
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->whereNotNull('contact_id')
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get()
            ->groupBy('contact_id');

        $billCount = 0;
        $totalCents = 0;

        foreach ($openByContact as $contactId => $bills) {
            $foreign = $this->contactIsForeign($company, (int) $contactId);
            $openDoc = (int) $bills->sum(fn (Bill $b) => $b->balanceCents());
            $gl = $this->glApForContact($company, $apAccountIds, (int) $contactId, $asOf, $foreign);
            $available = max(0, $openDoc - $gl);

            $reconciledHere = false;

            foreach ($bills as $bill) {
                if ($available <= 0) {
                    break;
                }

                $amount = min($bill->balanceCents(), $available);

                if ($amount <= 0) {
                    continue;
                }

                $this->applyReconciliation($bill, $amount);

                $available -= $amount;
                $totalCents += $amount;
                $billCount++;
                $reconciledHere = true;
            }

            if ($reconciledHere) {
                $bills->first()->contact?->recomputeApBalance();
            }
        }

        return ['bills' => $billCount, 'cents' => $totalCents];
    }

    /**
     * The portion of a vendor's open-bill document balance that the ledger has already
     * settled — i.e. open-document AP minus GL AP, floored at zero.
     */
    public function availableToReconcile(Company $company, int $contactId, CarbonImmutable $asOf): int
    {
        $apAccountIds = $this->apAccountIds($company);

        if ($apAccountIds === []) {
            return 0;
        }

        $openDoc = (int) Bill::query()
            ->vendor()
            ->where('contact_id', $contactId)
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
            ->get()
            ->sum(fn (Bill $b) => $b->balanceCents());

        $gl = $this->glApForContact($company, $apAccountIds, $contactId, $asOf, $this->contactIsForeign($company, $contactId));

        return max(0, $openDoc - $gl);
    }

    /**
     * Release reconciled balance the ledger no longer supports — e.g. after the vendor
     * credit (or write-off JE) that settled a bill is voided, raising the vendor's GL AP
     * back above their open-document balance. Re-opens bills so their net balance never
     * sits below GL AP. Returns the cents released. Mirror of
     * {@see InvoiceReconciler::releaseExcessReconciliation}.
     */
    public function releaseExcessReconciliation(Company $company, int $contactId): int
    {
        $apAccountIds = $this->apAccountIds($company);

        if ($apAccountIds === []) {
            return 0;
        }

        $asOf = $company->currentDateTime();
        $foreign = $this->contactIsForeign($company, $contactId);
        $gl = $this->glApForContact($company, $apAccountIds, $contactId, $asOf, $foreign);

        $bills = Bill::query()
            ->vendor()
            ->where('contact_id', $contactId)
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value, BillStatus::Paid->value])
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->get();

        $grossOpen = (int) $bills->sum(fn (Bill $b) => (int) $b->total_cents - (int) $b->amount_paid_cents);
        $currentReconciled = (int) $bills->sum(fn (Bill $b) => (int) $b->reconciled_cents);

        $allowed = max(0, $grossOpen - $gl);
        $excess = $currentReconciled - $allowed;

        if ($excess <= 0) {
            return 0;
        }

        $released = 0;

        foreach ($bills as $bill) {
            if ($excess <= 0) {
                break;
            }

            $reconciled = (int) $bill->reconciled_cents;

            if ($reconciled <= 0) {
                continue;
            }

            $release = min($reconciled, $excess);

            $this->applyReconciliation($bill, -$release);

            $excess -= $release;
            $released += $release;
        }

        if ($released > 0) {
            Contact::find($contactId)?->recomputeApBalance();
        }

        return $released;
    }

    /**
     * Apply a reconciliation delta (positive closes the phantom, negative releases an
     * over-reconciliation) and re-derive the bill status from what is settled.
     */
    private function applyReconciliation(Bill $bill, int $amount): void
    {
        AuditMute::silence(function () use ($bill, $amount): void {
            $bill->reconciled_cents = max(0, (int) $bill->reconciled_cents + $amount);

            if ($bill->balanceCents() <= 0) {
                $bill->status = BillStatus::Paid;
            } elseif ((int) $bill->amount_paid_cents + (int) $bill->reconciled_cents > 0) {
                $bill->status = BillStatus::Partial;
            } else {
                // Nothing settled — the bill is fully open again.
                $bill->status = BillStatus::Posted;
            }

            $bill->save();
        });

        $this->auditRecorder->record(
            (int) $bill->company_id,
            AuditAction::BillReconciled,
            $bill,
            [
                'bill_no' => $bill->bill_no,
                'reconciled_cents' => $amount,
                'reconciled_total_cents' => (int) $bill->reconciled_cents,
                'released' => $amount < 0,
                'new_status' => $bill->status->value,
            ],
        );
    }

    /**
     * Vendor's GL AP balance (credit − debit) as of the date, computed the same way as
     * the AP Aging report so the two always agree. For a foreign vendor the sum is taken
     * over the foreign memo columns, so it stays in the document currency and matches the
     * foreign open-document balance it is compared against.
     *
     * @param  array<int, int>  $apAccountIds
     */
    private function glApForContact(Company $company, array $apAccountIds, int $contactId, CarbonImmutable $asOf, bool $foreign = false): int
    {
        $expression = $foreign
            ? 'jl.foreign_credit_cents - jl.foreign_debit_cents'
            : 'jl.credit_cents - jl.debit_cents';

        // Match the authoritative AP Aging report: include voided entries. Voiding posts
        // a reversing entry (which stays posted) and flags the original voided_at, so the
        // original + reversal net to zero. Excluding the voided original here while its
        // reversal remains would skew GL AP by the voided amount.
        return (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $apAccountIds)
            ->where('jl.contact_id', $contactId)
            ->where('je.entry_date', '<=', $asOf)
            ->sum(DB::raw($expression));
    }

    /**
     * Whether the contact transacts in a foreign currency, so the reconciler compares
     * like-for-like (foreign document balance vs foreign GL balance).
     */
    private function contactIsForeign(Company $company, int $contactId): bool
    {
        $code = Contact::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($contactId)->value('currency_code');

        return $code !== null && ! $company->isHomeCurrency($code);
    }

    /**
     * @return array<int, int>
     */
    private function apAccountIds(Company $company): array
    {
        return Account::query()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::AccountsPayable->value)
            ->pluck('id')
            ->all();
    }
}
