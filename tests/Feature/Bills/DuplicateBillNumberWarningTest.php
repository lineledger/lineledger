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
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->vendor = Contact::factory()->vendor()->create();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();

    // An existing bill carrying the supplier reference we will try to reuse.
    Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL0001',
        'vendor_reference' => 'INV-555',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @return array<string, mixed>
 */
function dupBillLine(): array
{
    return [
        'item_id' => null,
        'account_id' => test()->expense->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price' => '100.00',
        'discount_pct' => '',
        'tax_code_id' => null,
        'tax_override' => '',
        'class_id' => null,
        'location_id' => null,
        'subtotal' => 0,
        'auto_tax' => 0,
        'tax' => 0,
        'total' => 0,
    ];
}

it('warns and does not save when the supplier reference is a duplicate', function () {
    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('vendor_reference', 'INV-555')
        ->set('lines', [dupBillLine()])
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertSet('pendingSaveAction', 'draft');

    // Only the pre-existing bill remains — the duplicate was not persisted.
    expect(Bill::count())->toBe(1);
});

it('saves the duplicate after the user confirms', function () {
    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('vendor_reference', 'INV-555')
        ->set('lines', [dupBillLine()])
        ->call('saveDraft')
        ->call('confirmDuplicateBillNo')
        ->assertHasNoErrors();

    expect(Bill::where('vendor_reference', 'INV-555')->count())->toBe(2);
});

it('does not warn for a different supplier reference', function () {
    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('vendor_reference', 'INV-999')
        ->set('lines', [dupBillLine()])
        ->call('saveDraft')
        ->assertHasNoErrors()
        ->assertSet('pendingSaveAction', null);

    expect(Bill::count())->toBe(2);
});

it('does not warn when the company preference is off', function () {
    $this->company->update(['warn_duplicate_bill_no' => false]);

    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('vendor_reference', 'INV-555')
        ->set('lines', [dupBillLine()])
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Bill::count())->toBe(2);
});
