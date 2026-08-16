<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->fixedAssetAccount = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Office Equipment')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('prefills the asset form from a Bill line that hits a fixed-asset account', function () {
    $vendor = Contact::create(['display_name' => 'Best Buy', 'is_vendor' => true]);

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-AST-1',
        'bill_date' => '2026-03-10',
        'due_date' => '2026-04-10',
        'memo' => 'Office laptop',
    ]);

    $line = $bill->lines()->create([
        'account_id' => $this->fixedAssetAccount->id,
        'description' => 'MacBook Pro 16"',
        'quantity' => '1',
        'unit_price_cents' => 350000,
        'line_subtotal_cents' => 350000,
        'line_tax_cents' => 0,
        'line_total_cents' => 350000,
        'line_order' => 0,
    ]);

    $component = Livewire::withQueryParams(['source_type' => 'bill_line', 'source_id' => $line->id])
        ->test('pages::assets.form', ['company' => $this->company]);

    $component
        ->assertSet('name', 'MacBook Pro 16"')
        ->assertSet('description', 'MacBook Pro 16"')
        ->assertSet('asset_account_id', $this->fixedAssetAccount->id)
        ->assertSet('acquired_date', '2026-03-10')
        ->assertSet('cost', '3500.00')
        ->assertSet('source_type', Bill::class)
        ->assertSet('source_id', $bill->id);

    $component->call('save')->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'MacBook Pro 16"')->firstOrFail();

    expect($asset->source_type)->toBe(Bill::class)
        ->and($asset->source_id)->toBe($bill->id)
        ->and($asset->cost_cents)->toBe(350000);
});

it('does not prefill from a Bill line that hits a non-fixed-asset account', function () {
    $vendor = Contact::create(['display_name' => 'Office Supply Co', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-AST-2',
        'bill_date' => '2026-03-10',
        'due_date' => '2026-04-10',
    ]);

    $line = $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Pens',
        'quantity' => '10',
        'unit_price_cents' => 500,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);

    $component = Livewire::withQueryParams(['source_type' => 'bill_line', 'source_id' => $line->id])
        ->test('pages::assets.form', ['company' => $this->company]);

    $component
        ->assertSet('name', '')
        ->assertSet('source_type', null)
        ->assertSet('source_id', null);
});

it('prefills the asset form from a cheque line that hits a fixed-asset account', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();

    $cheque = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => 'CHQ-AST-1',
        'cheque_date' => '2026-04-15',
        'payee_name' => 'Apple Store',
    ]);

    $line = $cheque->lines()->create([
        'account_id' => $this->fixedAssetAccount->id,
        'description' => 'iMac 27"',
        'amount_cents' => 420000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    $component = Livewire::withQueryParams(['source_type' => 'cheque_line', 'source_id' => $line->id])
        ->test('pages::assets.form', ['company' => $this->company]);

    $component
        ->assertSet('name', 'iMac 27"')
        ->assertSet('description', 'iMac 27"')
        ->assertSet('asset_account_id', $this->fixedAssetAccount->id)
        ->assertSet('acquired_date', '2026-04-15')
        ->assertSet('cost', '4200.00')
        ->assertSet('source_type', Cheque::class)
        ->assertSet('source_id', $cheque->id);

    $component->call('save')->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'iMac 27"')->firstOrFail();

    expect($asset->source_type)->toBe(Cheque::class)
        ->and($asset->source_id)->toBe($cheque->id)
        ->and($asset->cost_cents)->toBe(420000);
});

it('does not prefill from a cheque line that hits a non-fixed-asset account', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $cheque = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => 'CHQ-AST-2',
        'cheque_date' => '2026-04-15',
        'payee_name' => 'Office Supply Co',
    ]);

    $line = $cheque->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Printer paper',
        'amount_cents' => 4500,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    $component = Livewire::withQueryParams(['source_type' => 'cheque_line', 'source_id' => $line->id])
        ->test('pages::assets.form', ['company' => $this->company]);

    $component
        ->assertSet('name', '')
        ->assertSet('source_type', null)
        ->assertSet('source_id', null);
});

it('renders the create-asset button only for fixed-asset cheque lines on the cheque show page', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $cheque = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => 'CHQ-AST-3',
        'cheque_date' => '2026-04-15',
        'payee_name' => 'Mixed Vendor',
    ]);

    $cheque->lines()->create([
        'account_id' => $this->fixedAssetAccount->id,
        'description' => 'Standing desk',
        'amount_cents' => 90000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    $cheque->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Desk delivery fee',
        'amount_cents' => 5000,
        'tax_cents' => 0,
        'line_order' => 1,
    ]);

    // create() leaves the DB-default status unhydrated on the in-memory model.
    $cheque->refresh();

    $html = Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque])->html();

    // One button for the fixed-asset line, none for the expense line.
    expect(substr_count($html, 'data-test="create-asset-from-cheque-line"'))->toBe(1);
});

it('prefills the asset form from a Journal Entry line that hits a fixed-asset account', function () {
    $entry = JournalEntry::create([
        'entry_no' => 'JE-AST-1',
        'entry_date' => '2026-02-20',
        'memo' => 'Asset purchase',
        'is_posted' => true,
    ]);

    $line = $entry->lines()->create([
        'account_id' => $this->fixedAssetAccount->id,
        'debit_cents' => 250000,
        'credit_cents' => 0,
        'memo' => 'Dell monitor',
        'line_order' => 0,
    ]);

    $component = Livewire::withQueryParams(['source_type' => 'journal_line', 'source_id' => $line->id])
        ->test('pages::assets.form', ['company' => $this->company]);

    $component
        ->assertSet('name', 'Dell monitor')
        ->assertSet('asset_account_id', $this->fixedAssetAccount->id)
        ->assertSet('acquired_date', '2026-02-20')
        ->assertSet('cost', '2500.00')
        ->assertSet('source_type', JournalEntry::class)
        ->assertSet('source_id', $entry->id);

    $component->call('save')->assertHasNoErrors();

    $asset = Asset::query()->where('name', 'Dell monitor')->firstOrFail();

    expect($asset->source_type)->toBe(JournalEntry::class)
        ->and($asset->source_id)->toBe($entry->id);
});
