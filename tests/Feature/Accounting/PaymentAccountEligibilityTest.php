<?php

use App\Enums\AccountSubtype;
use App\Enums\BillPaymentStatus;
use App\Enums\CompanyRole;
use App\Enums\ReceiptStatus;
use App\Enums\SalesReceiptStatus;
use App\Models\Account;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\SalesReceipt;
use App\Models\User;
use Livewire\Livewire;

/*
 * A payment form accepts what its list offers, and nothing else unless the document
 * was already saved on it. Stripe clearing, statement lines and QuickBooks imports
 * write through the Actions, so a saved document can sit on an account the list
 * doesn't offer, and it has to stay editable. The API half lives in
 * tests/Feature/Api/V1/ReceiptLifecycleTest.php and BillPaymentLifecycleTest.php.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $this->card = expensePaidFromAccount('2110', 'Company card', AccountSubtype::CreditCard);
    $this->clearing = expensePaidFromAccount('1890', 'Payment clearing', AccountSubtype::CurrentAsset);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('accepts on the bill payment form what its list offers', function () {
    $page = Livewire::test('pages::bill-payments.form', ['company' => $this->company]);

    expect($page->instance()->bankAccountOptions()->pluck('id'))
        ->toContain($this->bank->id, $this->card->id)
        ->not->toContain($this->clearing->id)
        ->not->toContain($this->undeposited->id);

    foreach ([$this->bank, $this->card] as $account) {
        $page->set('paid_from_account_id', $account->id)->call('save')->assertHasNoErrors('paid_from_account_id');
    }
});

it('refuses on the bill payment form what its list does not offer', function (string $account) {
    Livewire::test('pages::bill-payments.form', ['company' => $this->company])
        ->set('paid_from_account_id', $this->{$account}->id)
        ->call('save')
        ->assertHasErrors('paid_from_account_id');
})->with(['clearing', 'undeposited', 'income']);

it('accepts on the receipt form what its list offers', function () {
    $page = Livewire::test('pages::receipts.form', ['company' => $this->company]);

    expect($page->instance()->depositToOptions()->pluck('id'))
        ->toContain($this->bank->id, $this->undeposited->id)
        ->not->toContain($this->clearing->id)
        ->not->toContain($this->card->id);

    foreach ([$this->bank, $this->undeposited] as $account) {
        $page->set('deposit_to_account_id', $account->id)->call('save')->assertHasNoErrors('deposit_to_account_id');
    }
});

it('refuses on the receipt form what its list does not offer', function (string $account) {
    Livewire::test('pages::receipts.form', ['company' => $this->company])
        ->set('deposit_to_account_id', $this->{$account}->id)
        ->call('save')
        ->assertHasErrors('deposit_to_account_id');
})->with(['clearing', 'card', 'income']);

it('accepts on the sales receipt form what its list offers', function () {
    $page = Livewire::test('pages::sales-receipts.form', ['company' => $this->company]);

    expect($page->instance()->depositToOptions()->pluck('id'))
        ->toContain($this->bank->id, $this->undeposited->id)
        ->not->toContain($this->clearing->id)
        ->not->toContain($this->card->id);

    foreach ([$this->bank, $this->undeposited] as $account) {
        $page->set('deposit_to_account_id', $account->id)->call('saveDraft')->assertHasNoErrors('deposit_to_account_id');
    }
});

it('refuses on the sales receipt form what its list does not offer', function (string $account) {
    Livewire::test('pages::sales-receipts.form', ['company' => $this->company])
        ->set('deposit_to_account_id', $this->{$account}->id)
        ->call('saveDraft')
        ->assertHasErrors('deposit_to_account_id');
})->with(['clearing', 'card', 'income']);

it('lets a saved bill payment keep its account, but not move to another outside the list', function () {
    $vendor = Contact::create(['display_name' => 'Acme Supply', 'is_vendor' => true]);
    $payment = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_no' => 'BP-1',
        'payment_date' => '2026-05-20',
        'paid_from_account_id' => $this->clearing->id,
        'amount_cents' => 1000,
        'status' => BillPaymentStatus::Draft,
    ]);
    $other = expensePaidFromAccount('1891', 'Other clearing', AccountSubtype::CurrentAsset);

    $page = Livewire::test('pages::bill-payments.form', ['company' => $this->company, 'payment' => $payment]);

    expect($page->instance()->bankAccountOptions()->pluck('id'))->toContain($this->clearing->id);

    $page->call('save')->assertHasNoErrors('paid_from_account_id');
    $page->set('paid_from_account_id', $other->id)->call('save')->assertHasErrors('paid_from_account_id');
});

it('lets a saved receipt keep its account, but not move to another outside the list', function () {
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'R-1',
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->clearing->id,
        'amount_cents' => 1000,
        'status' => ReceiptStatus::Draft,
    ]);
    $other = expensePaidFromAccount('1891', 'Other clearing', AccountSubtype::CurrentAsset);

    $page = Livewire::test('pages::receipts.form', ['company' => $this->company, 'receipt' => $receipt]);

    expect($page->instance()->depositToOptions()->pluck('id'))->toContain($this->clearing->id);

    $page->call('save')->assertHasNoErrors('deposit_to_account_id');
    $page->set('deposit_to_account_id', $other->id)->call('save')->assertHasErrors('deposit_to_account_id');
});

it('lets a saved sales receipt keep its account, but not move to another outside the list', function () {
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $receipt = SalesReceipt::create([
        'contact_id' => $customer->id,
        'sales_receipt_no' => 'SR-1',
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => $this->clearing->id,
        'status' => SalesReceiptStatus::Draft,
    ]);
    $other = expensePaidFromAccount('1891', 'Other clearing', AccountSubtype::CurrentAsset);

    $page = Livewire::test('pages::sales-receipts.form', ['company' => $this->company, 'receipt' => $receipt]);

    expect($page->instance()->depositToOptions()->pluck('id'))->toContain($this->clearing->id);

    $page->call('saveDraft')->assertHasNoErrors('deposit_to_account_id');
    $page->set('deposit_to_account_id', $other->id)->call('saveDraft')->assertHasErrors('deposit_to_account_id');
});

it('refuses another organization\'s account on every form', function (string $component, string $property, string $method) {
    $other = Company::factory()->create();
    $foreignBank = Account::withoutGlobalScopes()
        ->where('company_id', $other->id)
        ->where('subtype', AccountSubtype::Bank->value)
        ->firstOrFail();

    Livewire::test($component, ['company' => $this->company])
        ->set($property, $foreignBank->id)
        ->call($method)
        ->assertHasErrors($property);
})->with([
    ['pages::bill-payments.form', 'paid_from_account_id', 'save'],
    ['pages::receipts.form', 'deposit_to_account_id', 'save'],
    ['pages::sales-receipts.form', 'deposit_to_account_id', 'saveDraft'],
]);
