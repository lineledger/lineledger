<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\VendorCredit;
use App\Services\Posting\BillPoster;
use App\Services\Posting\BillReconciler;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\VendorCreditPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Reconcile Vendor', 'is_vendor' => true]);
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function postedBill(Contact $vendor, Account $expense, string $no, int $cents): Bill
{
    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => $no,
        'bill_date' => CarbonImmutable::create(2026, 1, 10),
        'due_date' => CarbonImmutable::create(2026, 2, 10),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

/** Post a journal entry that debits AP for the vendor (clearing their balance) and credits an expense. */
function clearApByJournal(Account $ap, Account $expense, Contact $vendor, int $cents): void
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.uniqid(),
        'entry_date' => CarbonImmutable::create(2026, 1, 20),
    ]);
    $entry->lines()->create(['account_id' => $ap->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'contact_id' => $vendor->id, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $expense->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry->fresh('lines'));
}

it('closes a bill the ledger already settled, posting no new GL', function () {
    $bill = postedBill($this->vendor, $this->expense, 'OPEN-1', 10000);
    clearApByJournal($this->ap, $this->expense, $this->vendor, 10000);

    expect($bill->fresh()->balanceCents())->toBe(10000); // document still shows open

    $journalCountBefore = JournalEntry::count();

    $closed = app(BillReconciler::class)->reconcileBill($bill->fresh());

    $bill->refresh();

    expect($closed)->toBe(10000)
        ->and($bill->reconciled_cents)->toBe(10000)
        ->and($bill->balanceCents())->toBe(0)
        ->and($bill->status)->toBe(BillStatus::Paid)
        ->and(JournalEntry::count())->toBe($journalCountBefore); // no new entry posted
});

it('refuses to close a balance the company still owes in the ledger', function () {
    $bill = postedBill($this->vendor, $this->expense, 'OWED-1', 8000);

    $closed = app(BillReconciler::class)->reconcileBill($bill->fresh());

    $bill->refresh();

    expect($closed)->toBe(0)
        ->and($bill->reconciled_cents)->toBe(0)
        ->and($bill->balanceCents())->toBe(8000)
        ->and($bill->status)->toBe(BillStatus::Posted);
});

function postVendorCredit(Contact $vendor, Account $expense, string $no, int $cents): VendorCredit
{
    $credit = VendorCredit::create([
        'contact_id' => $vendor->id,
        'vendor_credit_no' => $no,
        'vendor_credit_date' => CarbonImmutable::create(2026, 1, 12)->toDateString(),
    ]);
    $credit->lines()->create([
        'account_id' => $expense->id, 'description' => 'return', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(VendorCreditPoster::class)->post($credit);

    return $credit->fresh();
}

/** The vendor's GL Accounts Payable balance (credit − debit), what AP Aging reports. */
function apGlBalance(Company $company, Contact $contact): int
{
    $apIds = Account::query()
        ->where('company_id', $company->id)
        ->where('subtype', AccountSubtype::AccountsPayable->value)
        ->pluck('id');

    return (int) DB::table('journal_lines as jl')
        ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
        ->where('je.company_id', $company->id)
        ->where('je.is_posted', true)
        ->whereIn('jl.account_id', $apIds)
        ->where('jl.contact_id', $contact->id)
        ->sum(DB::raw('jl.credit_cents - jl.debit_cents'));
}

it('applies a vendor credit to the bill via close settled balance, with no double-count', function () {
    $bill = postedBill($this->vendor, $this->expense, 'BILL-VC', 55500);
    postVendorCredit($this->vendor, $this->expense, 'VC-CR', 5000);

    $closed = app(BillReconciler::class)->reconcileBill($bill->fresh());

    $bill->refresh();

    expect($closed)->toBe(5000)
        ->and($bill->reconciled_cents)->toBe(5000)
        ->and($bill->balanceCents())->toBe(50500)
        ->and($bill->status)->toBe(BillStatus::Partial);

    // The vendors-list balance reads the GL, so the credit is counted once — no double-count.
    expect($this->vendor->fresh()->ap_balance_cents)
        ->toBe(50500)
        ->toBe(apGlBalance($this->company, $this->vendor));
});

it('releases stuck reconciliation when a vendor credit is voided', function () {
    $bill = postedBill($this->vendor, $this->expense, 'BILL-V', 55500);
    $credit = postVendorCredit($this->vendor, $this->expense, 'VC-V', 5000);

    // Apply the credit to the bill, then void the credit — the bill must re-open.
    app(BillReconciler::class)->reconcileBill($bill->fresh());
    expect($bill->fresh()->reconciled_cents)->toBe(5000);

    app(VendorCreditPoster::class)->void($credit->fresh());

    $bill->refresh();

    expect($bill->reconciled_cents)->toBe(0)
        ->and($bill->balanceCents())->toBe(55500)
        ->and($bill->status)->toBe(BillStatus::Posted)
        ->and($this->vendor->fresh()->ap_balance_cents)->toBe(55500);
});

it('ignores voided entries when measuring GL AP, matching AP Aging', function () {
    $bill = postedBill($this->vendor, $this->expense, 'WO-1', 10000);
    clearApByJournal($this->ap, $this->expense, $this->vendor, 10000);

    // A vendor credit, then voided: its JE + reversal net to zero and must not skew GL AP.
    $credit = postVendorCredit($this->vendor, $this->expense, 'VC-VOID', 3000);
    app(VendorCreditPoster::class)->void($credit->fresh());

    $closed = app(BillReconciler::class)->reconcileBill($bill->fresh());

    expect($closed)->toBe(10000)
        ->and($bill->fresh()->balanceCents())->toBe(0)
        ->and($bill->fresh()->status)->toBe(BillStatus::Paid);
});

it('bulk-reconciles only the ledger-settled portion across a vendor', function () {
    // Two open bills totalling 150.00; the ledger has settled 100.00 of it.
    postedBill($this->vendor, $this->expense, 'A', 10000);
    postedBill($this->vendor, $this->expense, 'B', 5000);
    clearApByJournal($this->ap, $this->expense, $this->vendor, 10000);

    $result = app(BillReconciler::class)->reconcileCompany($this->company);

    expect($result['cents'])->toBe(10000);

    // Oldest bill (A) fully closed; B remains fully open. Net open == GL (50.00).
    $a = Bill::where('bill_no', 'A')->first();
    $b = Bill::where('bill_no', 'B')->first();

    expect($a->status)->toBe(BillStatus::Paid)
        ->and($a->balanceCents())->toBe(0)
        ->and($b->status)->toBe(BillStatus::Posted)
        ->and($b->balanceCents())->toBe(5000);
});
