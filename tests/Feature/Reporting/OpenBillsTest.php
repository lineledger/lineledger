<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\VendorCredit;
use App\Services\Posting\BillPoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\VendorCreditPoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Open Bills Vendor', 'is_vendor' => true, 'is_active' => true]);
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function openBillsTestBill(Contact $vendor, Account $expense, string $no, int $cents): Bill
{
    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => $no,
        'bill_date' => CarbonImmutable::now()->subDays(10),
        'due_date' => CarbonImmutable::now()->subDays(5),
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

it('lists open vendor bills with a balance owing', function () {
    openBillsTestBill($this->vendor, $this->expense, 'OB-1', 7500);

    $report = Livewire::test('pages::reports.open-bills', ['company' => $this->company])
        ->instance()
        ->report();

    expect($report['totals']['count'])->toBe(1)
        ->and($report['totals']['balance'])->toBe(7500)
        ->and($report['rows'][0]['bill_no'])->toBe('OB-1');
});

it('surfaces a vendor credit and nets the open balance to GL AP', function () {
    openBillsTestBill($this->vendor, $this->expense, 'OB-CR', 10000);

    // A $30 posted vendor credit (DR AP) → GL AP for the vendor is $70.
    $credit = VendorCredit::create([
        'contact_id' => $this->vendor->id,
        'vendor_credit_no' => 'VC-OB',
        'vendor_credit_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
    ]);
    $credit->lines()->create([
        'account_id' => $this->expense->id, 'description' => 'return', 'quantity' => '1',
        'unit_price_cents' => 3000, 'line_subtotal_cents' => 3000,
        'line_tax_cents' => 0, 'line_total_cents' => 3000, 'line_order' => 0,
    ]);
    app(VendorCreditPoster::class)->post($credit);

    $report = Livewire::test('pages::reports.open-bills', ['company' => $this->company])
        ->instance()
        ->report();

    $creditRow = collect($report['rows'])->firstWhere('type', 'credit');

    expect($creditRow)->not->toBeNull()
        ->and($creditRow['contact_id'])->toBe($this->vendor->id)
        ->and($creditRow['balance'])->toBe(-3000)
        ->and($report['totals']['gross_balance'])->toBe(10000)
        ->and($report['totals']['credits'])->toBe(3000)
        ->and($report['totals']['balance'])->toBe(7000); // net = GL AP
});

it('bulk-closes ledger-settled bills and drops them from the list', function () {
    $bill = openBillsTestBill($this->vendor, $this->expense, 'OB-SETTLED', 6000);

    // The ledger already settled this bill via a GJ that debits AP for the vendor.
    $entry = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-SETTLE',
        'entry_date' => CarbonImmutable::now()->subDays(3),
    ]);
    $entry->lines()->create(['account_id' => $this->ap->id, 'contact_id' => $this->vendor->id, 'debit_cents' => 6000, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->expense->id, 'debit_cents' => 0, 'credit_cents' => 6000, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    $component = Livewire::test('pages::reports.open-bills', ['company' => $this->company]);

    expect($component->instance()->report()['totals']['count'])->toBe(1);

    $component->call('reconcileSettled');

    $bill->refresh();

    expect($bill->status)->toBe(BillStatus::Paid)
        ->and($bill->reconciled_cents)->toBe(6000)
        ->and($component->instance()->report()['totals']['count'])->toBe(0);
});
