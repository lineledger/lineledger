<?php

namespace App\Services;

use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\TaxReturn;
use App\Support\GlobalSearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GlobalSearch
{
    private const int LIMIT_PER_GROUP = 5;

    /**
     * Run the search across every supported model and return results grouped by type.
     * Queries are auto-scoped to the current company by CompanyScope.
     *
     * @return array<string, Collection<int, GlobalSearchResult>>
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.$this->escapeLike($term).'%';

        return [
            'invoices' => $this->invoices($like),
            'credit_memos' => $this->creditMemos($like),
            'bills' => $this->bills($like),
            'bill_payments' => $this->billPayments($like),
            'receipts' => $this->receipts($like),
            'cheques' => $this->cheques($like),
            'deposits' => $this->deposits($like),
            'journal_entries' => $this->journalEntries($like),
            'contacts' => $this->contacts($like),
            'accounts' => $this->accounts($like),
            'items' => $this->items($like),
            'tax_returns' => $this->taxReturns($like),
        ];
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function taxReturns(string $like): Collection
    {
        return TaxReturn::query()
            ->with('taxAgency:id,name')
            ->where(fn (Builder $q) => $q
                ->where('tax_return_no', 'like', $like)
                ->orWhere('filing_reference', 'like', $like)
                ->orWhereHas('taxAgency', fn (Builder $a) => $a->where('name', 'like', $like))
            )
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (TaxReturn $r) => new GlobalSearchResult(
                type: 'tax_return',
                label: $r->tax_return_no,
                secondary: $r->taxAgency?->name,
                meta: $r->status->value,
                amountCents: (int) $r->net_cents,
                url: route('tax-returns.show', ['tax_return' => $r->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function creditMemos(string $like): Collection
    {
        return CreditMemo::query()
            ->with('contact:id,display_name')
            ->where(fn (Builder $q) => $q
                ->where('credit_memo_no', 'like', $like)
                ->orWhere('memo', 'like', $like)
                ->orWhereHas('contact', fn (Builder $c) => $c->where('display_name', 'like', $like))
            )
            ->orderByDesc('credit_memo_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (CreditMemo $m) => new GlobalSearchResult(
                type: 'credit_memo',
                label: $m->credit_memo_no,
                secondary: $m->contact?->display_name,
                meta: $m->status->value,
                amountCents: (int) $m->total_cents,
                url: route('credit-memos.show', ['credit_memo' => $m->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function invoices(string $like): Collection
    {
        return Invoice::query()
            ->with('contact:id,display_name')
            ->where(fn (Builder $q) => $q
                ->where('invoice_no', 'like', $like)
                ->orWhere('memo', 'like', $like)
                ->orWhereHas('contact', fn (Builder $c) => $c->where('display_name', 'like', $like))
            )
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Invoice $i) => new GlobalSearchResult(
                type: 'invoice',
                label: $i->invoice_no,
                secondary: $i->contact?->display_name,
                meta: $i->status->value,
                amountCents: (int) $i->total_cents,
                url: route('invoices.show', ['invoice' => $i->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function bills(string $like): Collection
    {
        return Bill::query()
            ->with('contact:id,display_name')
            ->where(fn (Builder $q) => $q
                ->where('bill_no', 'like', $like)
                ->orWhere('vendor_reference', 'like', $like)
                ->orWhere('memo', 'like', $like)
                ->orWhereHas('contact', fn (Builder $c) => $c->where('display_name', 'like', $like))
            )
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Bill $b) => new GlobalSearchResult(
                type: 'bill',
                label: $b->bill_no,
                secondary: $b->contact?->display_name,
                meta: $b->status->value,
                amountCents: (int) $b->total_cents,
                url: $b->bill_type === BillType::Reimbursement
                    ? route('reimbursements.show', ['bill' => $b->id])
                    : route('bills.show', ['bill' => $b->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function billPayments(string $like): Collection
    {
        return BillPayment::query()
            ->with('contact:id,display_name')
            ->where(fn (Builder $q) => $q
                ->where('payment_no', 'like', $like)
                ->orWhere('reference', 'like', $like)
                ->orWhere('memo', 'like', $like)
                ->orWhereHas('contact', fn (Builder $c) => $c->where('display_name', 'like', $like))
            )
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (BillPayment $p) => new GlobalSearchResult(
                type: 'bill_payment',
                label: $p->payment_no,
                secondary: $p->contact?->display_name,
                meta: $p->status->value,
                amountCents: (int) $p->amount_cents,
                url: route('bill-payments.show', ['payment' => $p->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function receipts(string $like): Collection
    {
        return CustomerReceipt::query()
            ->with('contact:id,display_name')
            ->where(fn (Builder $q) => $q
                ->where('receipt_no', 'like', $like)
                ->orWhere('reference', 'like', $like)
                ->orWhere('memo', 'like', $like)
                ->orWhereHas('contact', fn (Builder $c) => $c->where('display_name', 'like', $like))
            )
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (CustomerReceipt $r) => new GlobalSearchResult(
                type: 'receipt',
                label: $r->receipt_no,
                secondary: $r->contact?->display_name,
                meta: $r->status->value,
                amountCents: (int) $r->amount_cents,
                url: route('receipts.show', ['receipt' => $r->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function cheques(string $like): Collection
    {
        return Cheque::query()
            ->where(fn (Builder $q) => $q
                ->where('cheque_no', 'like', $like)
                ->orWhere('payee_name', 'like', $like)
                ->orWhere('memo', 'like', $like)
            )
            ->orderByDesc('cheque_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Cheque $c) => new GlobalSearchResult(
                type: 'cheque',
                label: $c->cheque_no,
                secondary: $c->payee_name,
                meta: $c->status->value,
                amountCents: (int) $c->amount_cents,
                url: route('cheques.show', ['cheque' => $c->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function deposits(string $like): Collection
    {
        return Deposit::query()
            ->where(fn (Builder $q) => $q
                ->where('deposit_no', 'like', $like)
                ->orWhere('memo', 'like', $like)
            )
            ->orderByDesc('deposit_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Deposit $d) => new GlobalSearchResult(
                type: 'deposit',
                label: $d->deposit_no,
                secondary: $d->memo,
                meta: $d->status->value,
                amountCents: (int) $d->amount_cents,
                url: route('deposits.show', ['deposit' => $d->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function journalEntries(string $like): Collection
    {
        return JournalEntry::query()
            ->where(fn (Builder $q) => $q
                ->where('entry_no', 'like', $like)
                ->orWhere('memo', 'like', $like)
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (JournalEntry $e) => new GlobalSearchResult(
                type: 'journal_entry',
                label: $e->entry_no,
                secondary: $e->memo,
                meta: $e->isPosted() ? 'posted' : 'draft',
                amountCents: null,
                url: route('journal.show', ['entry' => $e->id]),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function contacts(string $like): Collection
    {
        return Contact::query()
            ->where(fn (Builder $q) => $q
                ->where('display_name', 'like', $like)
                ->orWhere('company_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
            )
            ->orderBy('display_name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Contact $c) => new GlobalSearchResult(
                type: 'contact',
                label: $c->display_name,
                secondary: $c->company_name ?: $c->email,
                meta: $this->contactRole($c),
                amountCents: null,
                url: $this->contactUrl($c),
            ));
    }

    private function contactRole(Contact $c): string
    {
        $roles = [];

        if ($c->is_customer) {
            $roles[] = 'customer';
        }

        if ($c->is_vendor) {
            $roles[] = 'vendor';
        }

        if ($c->is_employee) {
            $roles[] = 'employee';
        }

        return implode(', ', $roles) ?: 'contact';
    }

    private function contactUrl(Contact $c): string
    {
        if ($c->is_customer) {
            return route('reports.contact-statement', ['contact' => $c->id, 'kind' => 'ar']);
        }

        if ($c->is_vendor) {
            return route('reports.contact-statement', ['contact' => $c->id, 'kind' => 'ap']);
        }

        return route('employees.index');
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function accounts(string $like): Collection
    {
        return Account::query()
            ->where(fn (Builder $q) => $q
                ->where('code', 'like', $like)
                ->orWhere('name', 'like', $like)
            )
            ->orderBy('code')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Account $a) => new GlobalSearchResult(
                type: 'account',
                label: $a->code.' — '.$a->name,
                secondary: $a->type->value,
                meta: null,
                amountCents: (int) $a->balance_cents,
                url: route('accounts.index'),
            ));
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function items(string $like): Collection
    {
        return Item::query()
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
            )
            ->orderBy('name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Item $i) => new GlobalSearchResult(
                type: 'item',
                label: $i->name,
                secondary: $i->sku,
                meta: null,
                amountCents: (int) $i->default_price_cents,
                url: route('lists.items'),
            ));
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
