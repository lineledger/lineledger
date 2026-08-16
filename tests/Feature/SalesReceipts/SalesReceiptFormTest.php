<?php

use App\Actions\Sales\SaveSalesReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\SalesReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SalesReceipt;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\SalesReceiptPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->uf = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $this->contact = Contact::factory()->customer()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('creates and posts a sales receipt from the form', function () {
    Livewire::test('pages::sales-receipts.form', ['company' => $this->company])
        ->set('contact_id', $this->contact->id)
        ->set('deposit_to_account_id', $this->uf->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.description', 'Consulting')
        ->set('lines.0.quantity', '2')
        ->set('lines.0.unit_price', '50.00')
        ->set('lines.0.tax_code_id', $this->gst->id)
        ->call('post')
        ->assertHasNoErrors();

    $receipt = SalesReceipt::query()->firstOrFail();

    expect($receipt->status)->toBe(SalesReceiptStatus::Posted)
        ->and($receipt->subtotal_cents)->toBe(10000)
        ->and($receipt->journal_entry_id)->not->toBeNull();
});

it('saves a draft without posting', function () {
    Livewire::test('pages::sales-receipts.form', ['company' => $this->company])
        ->set('contact_id', $this->contact->id)
        ->set('deposit_to_account_id', $this->uf->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '40.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $receipt = SalesReceipt::query()->firstOrFail();

    expect($receipt->status)->toBe(SalesReceiptStatus::Draft)
        ->and($receipt->journal_entry_id)->toBeNull();
});

it('lists sales receipts on the index', function () {
    $receipt = app(SaveSalesReceipt::class)->handle([
        'contact_id' => $this->contact->id,
        'receipt_date' => '2026-06-01',
        'deposit_to_account_id' => $this->uf->id,
        'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ]);

    Livewire::test('pages::sales-receipts.index', ['company' => $this->company])
        ->assertSee($receipt->sales_receipt_no);
});

it('voids a posted sales receipt from the show page', function () {
    $receipt = app(SaveSalesReceipt::class)->handle([
        'contact_id' => $this->contact->id,
        'receipt_date' => '2026-06-01',
        'deposit_to_account_id' => $this->uf->id,
        'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ]);
    app(SalesReceiptPoster::class)->post($receipt);

    Livewire::test('pages::sales-receipts.show', ['company' => $this->company, 'receipt' => $receipt->fresh()])
        ->call('void');

    expect($receipt->fresh()->status)->toBe(SalesReceiptStatus::Void);
});
