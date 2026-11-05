<?php

namespace App\Services;

use App\Enums\Activity;
use App\Enums\BillType;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\TaxReturn;
use App\Models\User;
use App\Support\Access\PayrollDetail;
use App\Support\Access\RouteGate;
use App\Support\Contacts\ContactLinkResolver;
use App\Support\GlobalSearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class GlobalSearch
{
    private const int LIMIT_PER_GROUP = 5;

    /**
     * The searcher whose section access filters the results; null runs unfiltered
     * (a system context such as a test calling the service directly).
     */
    private ?User $viewer = null;

    /** @var array<string, bool> section value => reachable, for the current search */
    private array $reachable = [];

    /**
     * Run the search across every supported model and return results grouped by type.
     * Queries are auto-scoped to the current company by CompanyScope.
     *
     * Each group is returned only when the searcher can open the section its
     * results link into — the same sections EnsureSectionAccess checks for those
     * pages — so search never shows a record whose page would refuse them.
     *
     * @return array<string, Collection<int, GlobalSearchResult>>
     */
    public function search(string $term, ?User $viewer = null): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $user = Auth::user();
        $this->viewer = $viewer ?? ($user instanceof User ? $user : null);
        $this->reachable = [];

        $like = '%'.$this->escapeLike($term).'%';

        // Each group shows only when the searcher could open the page its
        // results link to (RouteGate: the same checks the request makes).
        $groups = [
            'invoices' => [['invoices.show'], fn () => $this->invoices($like)],
            'credit_memos' => [['credit-memos.show'], fn () => $this->creditMemos($like)],
            'bills' => [['bills.show', 'reimbursements.show'], fn () => $this->bills($like)],
            'bill_payments' => [['bill-payments.show'], fn () => $this->billPayments($like)],
            'receipts' => [['receipts.show'], fn () => $this->receipts($like)],
            'cheques' => [['cheques.show'], fn () => $this->cheques($like)],
            'deposits' => [['deposits.show'], fn () => $this->deposits($like)],
            'journal_entries' => [['journal.show'], fn () => $this->journalEntries($like)],
            'contacts' => [[Section::Customers, Section::Vendors, Section::Employees, Section::Lists], fn () => $this->contacts($like)],
            'accounts' => [['accounts.index', 'reports.general-ledger'], fn () => $this->accounts($like)],
            'items' => [['lists.items'], fn () => $this->items($like)],
            'tax_returns' => [['tax-returns.show'], fn () => $this->taxReturns($like)],
        ];

        $results = [];

        foreach ($groups as $key => [$gates, $query]) {
            $open = $gates[0] instanceof Section
                ? $this->reachesAny(...$gates)
                : collect($gates)->contains(fn (string $route): bool => $this->reachesRoute($route));

            if ($open) {
                $results[$key] = $query();
            }
        }

        return $results;
    }

    /**
     * Whether the searcher can open any of the given sections here: the section
     * is enabled for the company (site-admin kill switch) and the searcher holds
     * it — the same pair EnsureSectionEnabled and EnsureSectionAccess check.
     */
    private function reachesAny(Section ...$sections): bool
    {
        foreach ($sections as $section) {
            if ($this->reaches($section)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the searcher could open the route here ({@see RouteGate}).
     */
    private function reachesRoute(string $routeName): bool
    {
        if ($this->viewer === null) {
            return true;
        }

        return $this->reachable['route:'.$routeName] ??= RouteGate::reaches($routeName, $this->company(), $this->viewer);
    }

    private function reaches(Section $section): bool
    {
        if ($this->viewer === null) {
            return true;
        }

        $company = $this->company();

        return $this->reachable[$section->value] ??= $company->sectionEnabled($section)
            && $this->viewer->canAccessSection($company, $section);
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
        // Vendor bills open under Vendors, reimbursements under Employees: show
        // only the kinds whose page the searcher can open.
        $types = array_values(array_filter([
            $this->reachesRoute('bills.show') ? BillType::Vendor : null,
            $this->reachesRoute('reimbursements.show') ? BillType::Reimbursement : null,
        ]));

        return Bill::query()
            ->with('contact:id,display_name')
            ->whereIn('bill_type', $types)
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
        // Payroll entries (and their voids) carry employee names in their
        // memos: they show only to a searcher who can open pay runs
        // (PayrollDetail).
        $hidesPayroll = $this->viewer !== null && ! $this->viewer->permissions($this->company())->seesPayrollDetail();

        return JournalEntry::query()
            ->where(fn (Builder $q) => $q
                ->where('entry_no', 'like', $like)
                ->orWhere('memo', 'like', $like)
            )
            ->when($hidesPayroll, fn (Builder $q) => PayrollDetail::withoutPayroll($q))
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
        // Role label and destination come from the one resolver every contact
        // link shares (statement for customers/vendors, employee editor, or
        // the all-time Transactions report for other names). A contact is
        // listed when the searcher can open a section that owns one of its
        // roles; its link falls back to that section's list when the viewer
        // can't reach the contact's usual page.
        $links = app(ContactLinkResolver::class);
        $company = $this->company();

        $roles = array_filter([
            'is_customer' => $this->reaches(Section::Customers) ? 'customers.index' : null,
            'is_vendor' => $this->reaches(Section::Vendors) ? 'vendors.index' : null,
            'is_employee' => $this->reaches(Section::Employees) ? 'employees.index' : null,
            'is_other_name' => $this->reaches(Section::Lists) ? 'lists.other-names' : null,
        ]);

        return Contact::query()
            ->when(count($roles) < 4, fn (Builder $query) => $query->where(function (Builder $q) use ($roles): void {
                foreach (array_keys($roles) as $flag) {
                    $q->orWhere($flag, true);
                }
            }))
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
                meta: $links->roleLabel($c),
                amountCents: null,
                url: $this->contactUrl($c, $company, $links, $roles),
            ));
    }

    /**
     * The contact's usual page when the searcher can open it (or when running
     * unfiltered), else the list page of the first role they can reach.
     *
     * @param  array<string, string>  $roles  role flag => list route the searcher can reach
     */
    private function contactUrl(Contact $contact, Company $company, ContactLinkResolver $links, array $roles): string
    {
        if ($this->viewer === null) {
            return $links->urlFor($contact, $company);
        }

        $url = $links->urlForViewer($contact, $company, $this->viewer);

        if ($url !== null) {
            return $url;
        }

        foreach ($roles as $flag => $route) {
            if ($contact->{$flag}) {
                return route($route);
            }
        }

        return $links->urlFor($contact, $company);
    }

    /**
     * The tenant every query above is scoped to — the same binding CompanyScope
     * reads, made explicit here because contact URLs name the company slug.
     */
    private function company(): Company
    {
        return app('current_company');
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    private function accounts(string $like): Collection
    {
        $toLedger = $this->reachesRoute('reports.general-ledger');
        $withBalance = $this->viewer === null || $this->viewer->permissions($this->company())->canViewBalances(Activity::Accounts);

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
                amountCents: $withBalance ? (int) $a->balance_cents : null,
                url: $toLedger
                    ? route('reports.general-ledger', ['account' => $a->id])
                    : route('accounts.index'),
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

    /**
     * Heading for a results group (the keys returned by search()).
     */
    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'invoices' => __('Invoices'),
            'credit_memos' => __('Credit Memos'),
            'bills' => __('Bills'),
            'bill_payments' => __('Bill Payments'),
            'receipts' => __('Receipts'),
            'cheques' => __('Cheques'),
            'deposits' => __('Deposits'),
            'journal_entries' => __('Journal Entries'),
            'contacts' => __('Contacts'),
            'accounts' => __('Accounts'),
            'items' => __('Items'),
            'tax_returns' => __('Tax Returns'),
            default => ucwords(str_replace('_', ' ', $group)),
        };
    }
}
