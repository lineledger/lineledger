<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('seeds default payment methods when a company is created', function () {
    $names = PaymentMethod::query()->where('company_id', $this->company->id)->pluck('name')->all();

    expect($names)->toContain('Cash', 'Cheque', 'E-transfer', 'EFT', 'Wire', 'Credit card');

    $cheque = PaymentMethod::query()->where('company_id', $this->company->id)->where('name', 'Cheque')->first();
    expect($cheque->is_cheque)->toBeTrue();
});

it('creates a new payment method via the settings page', function () {
    Livewire::test('pages::settings.lists.payment-methods', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'PayPal')
        ->set('f_is_cheque', false)
        ->call('save')
        ->assertHasNoErrors();

    $created = PaymentMethod::query()->where('name', 'PayPal')->first();

    expect($created)->not->toBeNull()
        ->and($created->company_id)->toBe($this->company->id)
        ->and($created->is_active)->toBeTrue()
        ->and($created->is_cheque)->toBeFalse();
});

it('treats any cheque-flagged method as a cheque method on the bill payment form', function () {
    $vendor = Contact::create(['display_name' => 'Test Vendor', 'is_vendor' => true]);
    $customMethod = PaymentMethod::create([
        'name' => 'Custom Draft',
        'is_cheque' => true,
        'is_active' => true,
    ]);
    $cashMethod = PaymentMethod::query()->where('name', 'Cash')->firstOrFail();

    $component = Livewire::test('pages::bill-payments.form', ['company' => $this->company])
        ->set('contactRole', 'vendor')
        ->set('contact_id', $vendor->id)
        ->set('payment_method_id', $customMethod->id);

    expect($component->instance()->isChequeMethod())->toBeTrue();

    $component->set('payment_method_id', $cashMethod->id);

    expect($component->instance()->isChequeMethod())->toBeFalse();
});

it('refuses to print a cheque for a payment whose method is no longer cheque-flagged', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $vendor = Contact::create(['display_name' => 'Vendor X', 'is_vendor' => true]);
    $cheque = PaymentMethod::query()->where('name', 'Cheque')->firstOrFail();

    $payment = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-1',
        'payment_date' => '2026-05-14',
        'paid_from_account_id' => $bank->id,
        'payment_method_id' => $cheque->id,
        'reference' => '5001',
        'amount_cents' => 1000,
    ]);

    $cheque->update(['is_cheque' => false]);

    $this->get(route('bill-payments.print-cheque', [
        'company' => $this->company->slug,
        'payment' => $payment->id,
    ]))->assertNotFound();
});
