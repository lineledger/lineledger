<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\InvoiceReconciler;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Reconcile Co', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function postedInvoice(Contact $customer, Account $income, string $no, int $cents): Invoice
{
    $inv = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $no,
        'invoice_date' => CarbonImmutable::create(2026, 1, 10),
        'due_date' => CarbonImmutable::create(2026, 2, 10),
    ]);
    $inv->lines()->create([
        'account_id' => $income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($inv);

    return $inv->fresh();
}

/** Post a journal entry that credits AR for the customer (clearing their balance) and debits an expense. */
function clearArByJournal(Account $ar, Account $expense, Contact $customer, int $cents): void
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.uniqid(),
        'entry_date' => CarbonImmutable::create(2026, 1, 20),
    ]);
    $entry->lines()->create(['account_id' => $expense->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $ar->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'contact_id' => $customer->id, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry->fresh('lines'));
}

it('closes an invoice the ledger already settled, posting no new GL', function () {
    $invoice = postedInvoice($this->customer, $this->income, 'OPEN-1', 10000);
    clearArByJournal($this->ar, $this->expense, $this->customer, 10000);

    expect($invoice->fresh()->balanceCents())->toBe(10000); // document still shows open

    $journalCountBefore = JournalEntry::count();

    $closed = app(InvoiceReconciler::class)->reconcileInvoice($invoice->fresh());

    $invoice->refresh();

    expect($closed)->toBe(10000)
        ->and($invoice->reconciled_cents)->toBe(10000)
        ->and($invoice->balanceCents())->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and(JournalEntry::count())->toBe($journalCountBefore); // no new entry posted
});

it('refuses to close a balance the customer still owes in the ledger', function () {
    $invoice = postedInvoice($this->customer, $this->income, 'OWED-1', 8000);

    $closed = app(InvoiceReconciler::class)->reconcileInvoice($invoice->fresh());

    $invoice->refresh();

    expect($closed)->toBe(0)
        ->and($invoice->reconciled_cents)->toBe(0)
        ->and($invoice->balanceCents())->toBe(8000)
        ->and($invoice->status)->toBe(InvoiceStatus::Posted);
});

function postCreditMemo(Contact $customer, Account $income, string $no, int $cents): CreditMemo
{
    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => $no,
        'credit_memo_date' => CarbonImmutable::create(2026, 1, 12),
    ]);
    $memo->lines()->create([
        'account_id' => $income->id, 'description' => 'credit', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    return $memo->fresh();
}

/** The customer's GL Accounts Receivable balance (debit − credit), what AR Aging reports. */
function arGlBalance(Company $company, Contact $contact): int
{
    $arIds = Account::query()
        ->where('company_id', $company->id)
        ->where('subtype', AccountSubtype::AccountsReceivable->value)
        ->pluck('id');

    return (int) DB::table('journal_lines as jl')
        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
        ->where('je.company_id', $company->id)
        ->where('je.is_posted', true)
        ->whereIn('jl.account_id', $arIds)
        ->where('jl.contact_id', $contact->id)
        ->sum(DB::raw('jl.debit_cents - jl.credit_cents'));
}

it('applies a credit memo to the invoice via close settled balance, with no double-count', function () {
    $invoice = postedInvoice($this->customer, $this->income, 'INV-CR', 55500);
    postCreditMemo($this->customer, $this->income, 'CM-CR', 5000);

    // "Close settled balance" applies the $50 on-account credit to the open invoice.
    $closed = app(InvoiceReconciler::class)->reconcileInvoice($invoice->fresh());

    $invoice->refresh();

    expect($closed)->toBe(5000)
        ->and($invoice->reconciled_cents)->toBe(5000)
        ->and($invoice->balanceCents())->toBe(50500)
        ->and($invoice->status)->toBe(InvoiceStatus::Partial);

    // The customers-list balance reads the GL, so the credit is counted once — no double-count.
    expect($this->customer->fresh()->ar_balance_cents)
        ->toBe(50500)
        ->toBe(arGlBalance($this->company, $this->customer));
});

it('applies an unapplied customer payment to the invoice via close settled balance', function () {
    $invoice = postedInvoice($this->customer, $this->income, 'INV-OP', 100000);

    // $1,000 receipt, only $800 applied → $200 unapplied sits on account.
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id, 'receipt_no' => 'OP-1',
        'receipt_date' => CarbonImmutable::create(2026, 1, 12), 'amount_cents' => 100000,
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 80000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $closed = app(InvoiceReconciler::class)->reconcileInvoice($invoice->fresh());

    $invoice->refresh();

    expect($closed)->toBe(20000)
        ->and($invoice->balanceCents())->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($this->customer->fresh()->ar_balance_cents)->toBe(0);
});

it('releases stuck reconciliation when a credit memo is voided', function () {
    $invoice = postedInvoice($this->customer, $this->income, 'INV-V', 55500);
    $memo = postCreditMemo($this->customer, $this->income, 'CM-V', 5000);

    // Legacy stuck state, then void the credit memo — the release re-opens the invoice.
    $invoice->forceFill(['reconciled_cents' => 5000, 'status' => InvoiceStatus::Partial->value])->saveQuietly();

    app(CreditMemoPoster::class)->void($memo->fresh());

    $invoice->refresh();

    expect($invoice->reconciled_cents)->toBe(0)
        ->and($invoice->balanceCents())->toBe(55500)
        ->and($invoice->status)->toBe(InvoiceStatus::Posted)
        ->and($this->customer->fresh()->ar_balance_cents)->toBe(55500);
});

it('ignores voided entries when measuring GL AR, matching AR Aging', function () {
    // Invoice fully settled by a write-off JE → its whole balance is ledger-settled.
    $invoice = postedInvoice($this->customer, $this->income, 'WO-1', 10000);
    clearArByJournal($this->ar, $this->expense, $this->customer, 10000);

    // A credit memo, then voided: its JE + reversal net to zero on the GL and must not
    // skew the reconciler's GL AR (the old code dropped the voided original but kept the
    // reversal, under-counting what was reconcilable).
    $memo = postCreditMemo($this->customer, $this->income, 'CM-VOID', 3000);
    app(CreditMemoPoster::class)->void($memo->fresh());

    $closed = app(InvoiceReconciler::class)->reconcileInvoice($invoice->fresh());

    expect($closed)->toBe(10000)
        ->and($invoice->fresh()->balanceCents())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('bulk-reconciles only the ledger-settled portion across a customer', function () {
    // Two open invoices totalling 150.00; the ledger has settled 100.00 of it.
    postedInvoice($this->customer, $this->income, 'A', 10000);
    postedInvoice($this->customer, $this->income, 'B', 5000);
    clearArByJournal($this->ar, $this->expense, $this->customer, 10000);

    $result = app(InvoiceReconciler::class)->reconcileCompany($this->company);

    expect($result['cents'])->toBe(10000);

    // Oldest invoice (A) fully closed; B remains fully open. Net open == GL (50.00).
    $a = Invoice::where('invoice_no', 'A')->first();
    $b = Invoice::where('invoice_no', 'B')->first();

    expect($a->status)->toBe(InvoiceStatus::Paid)
        ->and($a->balanceCents())->toBe(0)
        ->and($b->status)->toBe(InvoiceStatus::Posted)
        ->and($b->balanceCents())->toBe(5000);
});
