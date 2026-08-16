<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\BillPoster;
use App\Services\Posting\TaxCalculator;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Acme Supplies', 'is_vendor' => true]);
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeSourceBill(): Bill
{
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $bill = Bill::create([
        'contact_id' => test()->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-SRC-1',
        'bill_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(25)->toDateString(),
        'memo' => 'April Rent',
    ]);

    $totals = app(TaxCalculator::class)->line('1', 1000000, $gst);

    $bill->lines()->create([
        'account_id' => test()->expenseAccount->id,
        'description' => 'Rent',
        'quantity' => '1',
        'unit_price_cents' => 1000000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh('lines');
}

it('prefills the new bill form from a source bill', function () {
    $source = makeSourceBill();

    $component = Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::bills.form', ['company' => $this->company]);

    $component
        ->assertSet('contact_id', $this->vendor->id)
        ->assertSet('memo', 'April Rent')
        ->assertSet('bill_date', $this->company->currentDateTime()->toDateString())
        ->assertCount('lines', 1)
        ->assertSet('lines.0.account_id', $this->expenseAccount->id)
        ->assertSet('lines.0.description', 'Rent')
        ->assertSet('lines.0.quantity', '1')
        ->assertSet('lines.0.unit_price', '10000.00');

    // bill_no should be a freshly generated number, not the source's number
    expect($component->get('bill_no'))->not->toBe('BILL-SRC-1');
});

it('saves the duplicated bill as a new draft without affecting the source', function () {
    $source = makeSourceBill();
    $sourceAmountPaid = $source->amount_paid_cents;

    Livewire::withQueryParams(['from' => $source->id])
        ->test('pages::bills.form', ['company' => $this->company])
        ->call('saveDraft');

    $bills = Bill::query()->orderBy('id')->get();

    expect($bills)->toHaveCount(2);

    $duplicate = $bills->last();

    expect($duplicate->id)->not->toBe($source->id)
        ->and($duplicate->contact_id)->toBe($this->vendor->id)
        ->and($duplicate->bill_no)->not->toBe($source->bill_no)
        ->and($duplicate->journal_entry_id)->toBeNull()
        ->and($duplicate->amount_paid_cents)->toBe(0)
        ->and($duplicate->lines)->toHaveCount(1);

    // Source bill must be untouched
    $source->refresh();
    expect($source->amount_paid_cents)->toBe($sourceAmountPaid)
        ->and($source->bill_no)->toBe('BILL-SRC-1');
});

it('ignores from= when the source bill belongs to a different company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $otherVendor = Contact::create(['display_name' => 'Other Vendor', 'is_vendor' => true]);
    $otherExpense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $otherBill = Bill::create([
        'contact_id' => $otherVendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-OTHER-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    $otherBill->lines()->create([
        'account_id' => $otherExpense->id,
        'description' => 'Other co line',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);

    app()->instance('current_company', $this->company);

    Livewire::withQueryParams(['from' => $otherBill->id])
        ->test('pages::bills.form', ['company' => $this->company])
        ->assertSet('contact_id', null)
        ->assertSet('memo', '')
        ->assertSet('lines.0.description', '');
});
