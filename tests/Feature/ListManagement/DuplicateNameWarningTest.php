<?php

use App\Enums\AccountSubtype;
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
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function duplicateWarnExpense(string $code, string $name, array $overrides = []): Account
{
    return Account::create([
        'code' => $code,
        'name' => $name,
        'subtype' => AccountSubtype::Expense,
        'type' => AccountSubtype::Expense->type(),
        'normal_balance' => AccountSubtype::Expense->type()->normalBalance(),
        'is_active' => true,
        ...$overrides,
    ]);
}

describe('accounts page', function () {
    it('warns about a case-insensitive duplicate of an active account', function () {
        duplicateWarnExpense('9981', 'Llama Grooming');

        Livewire::test('pages::accounts.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('form_name', '  LLAMA grooming ')
            ->assertSeeHtml('data-test="duplicate-name-warning"')
            ->assertSee(__('Another active account already has this name.'));
    });

    it('does not warn when the only duplicate is inactive', function () {
        duplicateWarnExpense('9981', 'Llama Grooming', ['is_active' => false]);

        Livewire::test('pages::accounts.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('form_name', 'Llama Grooming')
            ->assertDontSeeHtml('data-test="duplicate-name-warning"');
    });

    it('does not warn about the account being edited', function () {
        $account = duplicateWarnExpense('9981', 'Llama Grooming');

        Livewire::test('pages::accounts.index', ['company' => $this->company])
            ->call('openEdit', $account->id)
            ->assertDontSeeHtml('data-test="duplicate-name-warning"');
    });

    it('still saves an account with a duplicate name', function () {
        duplicateWarnExpense('9981', 'Llama Grooming');

        Livewire::test('pages::accounts.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('form_code', '9982')
            ->set('form_name', 'llama grooming')
            ->set('form_subtype', AccountSubtype::Expense->value)
            ->call('save')
            ->assertHasNoErrors();

        expect(Account::query()->whereRaw('LOWER(name) = ?', ['llama grooming'])->count())->toBe(2);
    });
});

describe('customers page', function () {
    it('warns about a case-insensitive duplicate of an active customer', function () {
        Contact::factory()->customer()->create(['display_name' => 'Acme Industries']);

        Livewire::test('pages::customers.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('f_display_name', 'ACME industries')
            ->assertSeeHtml('data-test="duplicate-name-warning"')
            ->assertSee(__('Another contact already uses this name.'));
    });

    it('warns when the duplicate is a vendor, because the check is role-agnostic', function () {
        Contact::factory()->vendor()->create(['display_name' => 'Hydro Supplies']);

        Livewire::test('pages::customers.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('f_display_name', 'hydro supplies')
            ->assertSeeHtml('data-test="duplicate-name-warning"');
    });

    it('does not warn when the only duplicate is inactive', function () {
        Contact::factory()->customer()->create(['display_name' => 'Acme Industries', 'is_active' => false]);

        Livewire::test('pages::customers.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('f_display_name', 'Acme Industries')
            ->assertDontSeeHtml('data-test="duplicate-name-warning"');
    });

    it('does not warn about the customer being edited', function () {
        $customer = Contact::factory()->customer()->create(['display_name' => 'Acme Industries']);

        Livewire::test('pages::customers.index', ['company' => $this->company])
            ->call('openEdit', $customer->id)
            ->assertDontSeeHtml('data-test="duplicate-name-warning"');
    });

    it('still saves a customer with a duplicate name', function () {
        Contact::factory()->customer()->create(['display_name' => 'Acme Industries']);

        Livewire::test('pages::customers.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('f_display_name', 'acme industries')
            ->call('save')
            ->assertHasNoErrors();

        expect(Contact::query()->whereRaw('LOWER(display_name) = ?', ['acme industries'])->count())->toBe(2);
    });
});

describe('vendors page', function () {
    it('warns when the duplicate is a customer, because the check is role-agnostic', function () {
        Contact::factory()->customer()->create(['display_name' => 'Acme Industries']);

        Livewire::test('pages::vendors.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('f_display_name', 'ACME INDUSTRIES')
            ->assertSeeHtml('data-test="duplicate-name-warning"')
            ->assertSee(__('Another contact already uses this name.'));
    });

    it('does not warn when the only duplicate is inactive', function () {
        Contact::factory()->vendor()->create(['display_name' => 'Hydro Supplies', 'is_active' => false]);

        Livewire::test('pages::vendors.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('f_display_name', 'Hydro Supplies')
            ->assertDontSeeHtml('data-test="duplicate-name-warning"');
    });

    it('does not warn about the vendor being edited', function () {
        $vendor = Contact::factory()->vendor()->create(['display_name' => 'Hydro Supplies']);

        Livewire::test('pages::vendors.index', ['company' => $this->company])
            ->call('openEdit', $vendor->id)
            ->assertDontSeeHtml('data-test="duplicate-name-warning"');
    });

    it('still saves a vendor with a duplicate name', function () {
        Contact::factory()->vendor()->create(['display_name' => 'Hydro Supplies']);

        Livewire::test('pages::vendors.index', ['company' => $this->company])
            ->call('openCreate')
            ->set('f_display_name', 'hydro supplies')
            ->call('save')
            ->assertHasNoErrors();

        expect(Contact::query()->whereRaw('LOWER(display_name) = ?', ['hydro supplies'])->count())->toBe(2);
    });
});
