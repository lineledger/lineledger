<?php

use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->expense = Account::query()
        ->where('type', AccountType::Expense->value)
        ->where('is_active', true)
        ->orderBy('code')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('saves the account number and expanded profile fields on a vendor', function () {
    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->set('f_display_name', 'Acme Supply')
        ->set('f_account_no', 'CUST-99812')
        ->set('f_first_name', 'Jane')
        ->set('f_last_name', 'Doe')
        ->set('f_job_title', 'Account Manager')
        ->set('f_mobile', '555-0100')
        ->set('f_billing_line1', '123 Main St')
        ->set('f_billing_city', 'Halifax')
        ->set('f_billing_country', 'ca')
        ->set('f_default_expense_account_id', $this->expense->id)
        ->call('save')
        ->assertHasNoErrors();

    $vendor = Contact::where('is_vendor', true)->firstOrFail();

    expect($vendor->account_no)->toBe('CUST-99812')
        ->and($vendor->first_name)->toBe('Jane')
        ->and($vendor->last_name)->toBe('Doe')
        ->and($vendor->job_title)->toBe('Account Manager')
        ->and($vendor->mobile)->toBe('555-0100')
        ->and($vendor->billing_line1)->toBe('123 Main St')
        ->and($vendor->billing_city)->toBe('Halifax')
        ->and($vendor->billing_country)->toBe('CA')
        ->and($vendor->default_expense_account_id)->toBe($this->expense->id);
});

it('rejects a full country name with a validation error, not a database error', function () {
    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->set('f_display_name', 'Acme Supply')
        ->set('f_billing_country', 'Canada')
        ->call('save')
        ->assertHasErrors(['f_billing_country' => 'max']);

    expect(Contact::query()->count())->toBe(0);
});

it('auto-fills the cheque memo from the payee account number', function () {
    $vendor = Contact::create([
        'company_id' => $this->company->id,
        'display_name' => 'Acme Supply',
        'account_no' => 'ACCT-7788',
        'is_vendor' => true,
    ]);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_contact_id', $vendor->id)
        ->assertSet('payee_name', 'Acme Supply')
        ->assertSet('memo', 'ACCT-7788');
});

it('does not clobber a memo the user already typed', function () {
    $vendor = Contact::create([
        'company_id' => $this->company->id,
        'display_name' => 'Acme Supply',
        'account_no' => 'ACCT-7788',
        'is_vendor' => true,
    ]);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('memo', 'Office chairs')
        ->set('payee_contact_id', $vendor->id)
        ->assertSet('memo', 'Office chairs');
});

it('auto-fills the bill-payment memo from the vendor account number', function () {
    $vendor = Contact::create([
        'company_id' => $this->company->id,
        'display_name' => 'Acme Supply',
        'account_no' => 'ACCT-7788',
        'is_vendor' => true,
    ]);

    Livewire::test('pages::bill-payments.form', ['company' => $this->company])
        ->set('contact_id', $vendor->id)
        ->assertSet('memo', 'ACCT-7788');
});
