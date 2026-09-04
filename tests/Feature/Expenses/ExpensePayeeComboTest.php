<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\ExpenseStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @return array<string, mixed>
 */
function payeeComboExpenseLine(int $accountId): array
{
    return [
        'account_id' => $accountId,
        'description' => 'Refund',
        'amount' => '80.00',
        'tax_code_id' => null,
        'tax_override' => '',
        'class_id' => null,
        'location_id' => null,
        'auto_tax_cents' => 0,
        'tax_cents' => 0,
        'total' => 0,
    ];
}

it('offers vendors, customers, employees and other names on the expense form', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Alpha Vendor']);
    $other = Contact::factory()->otherName()->create(['display_name' => 'Alpha Other']);
    $employee = Contact::factory()->create(['display_name' => 'Alpha Employee', 'is_employee' => true]);
    $inactive = Contact::factory()->vendor()->create(['display_name' => 'Alpha Inactive', 'is_active' => false]);

    $ids = Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payee_query', 'Alpha')
        ->get('payeeOptions')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($vendor->id, $other->id, $employee->id)
        ->and($ids)->not->toContain($inactive->id);
});

it('selects an existing payee and defaults the memo from the account number', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme', 'account_no' => 'AC-1']);

    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->assertSeeHtml('data-test="expense-payee-combo-search"')
        ->call('selectPayee', $vendor->id)
        ->assertSet('payee_contact_id', $vendor->id)
        ->assertSet('payee_name', 'Acme')
        ->assertSet('memo', 'AC-1')
        ->assertSeeHtml('data-test="expense-payee-combo-selected"')
        ->assertDontSeeHtml('data-test="expense-payee-combo-search"')
        ->assertSee('Vendor')
        ->call('clearPayee')
        ->assertSet('payee_contact_id', null)
        ->assertSet('payee_name', '')
        ->assertSeeHtml('data-test="expense-payee-combo-search"');
});

it('quick-adds an Other name inline and stamps it on every GL leg when posted', function () {
    $component = Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payment_account_id', $this->bank->id)
        ->set('payee_query', 'Walk-in refund')
        ->assertSeeHtml('data-test="expense-payee-combo-add-other-name"')
        ->call('startNewOtherName')
        ->assertSet('payee_creating', true)
        ->assertSet('new_payee_name', 'Walk-in refund')
        ->call('createOtherName')
        ->assertHasNoErrors();

    $contact = Contact::query()->where('display_name', 'Walk-in refund')->firstOrFail();

    expect($contact->is_other_name)->toBeTrue()
        ->and($contact->is_vendor)->toBeFalse()
        ->and($contact->is_customer)->toBeFalse()
        ->and($contact->is_employee)->toBeFalse()
        ->and($contact->company_id)->toBe($this->company->id);

    $component
        ->assertSet('payee_contact_id', $contact->id)
        ->assertSet('payee_name', 'Walk-in refund')
        ->assertSeeHtml('data-test="expense-payee-combo-selected"')
        ->assertSee('Other name')
        ->set('lines', [payeeComboExpenseLine($this->expenseAccount->id)])
        ->call('postExpense')
        ->assertHasNoErrors();

    $expense = Expense::firstOrFail();

    expect($expense->status)->toBe(ExpenseStatus::Posted)
        ->and($expense->payee_contact_id)->toBe($contact->id)
        ->and($expense->payee_name)->toBe('Walk-in refund');

    $legs = $expense->journalEntry->lines;

    expect($legs->count())->toBeGreaterThanOrEqual(2)
        ->and($legs->pluck('contact_id')->unique()->all())->toBe([$contact->id]);
});

it('selects the existing contact instead of duplicating it as an Other name', function () {
    $employee = Contact::factory()->create(['display_name' => 'Jane Doe', 'is_employee' => true]);

    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payee_query', 'jane doe')
        ->assertDontSeeHtml('data-test="expense-payee-combo-add-other-name"')
        ->call('startNewOtherName')
        ->call('createOtherName', 'JANE DOE')
        ->assertHasNoErrors()
        ->assertSet('payee_contact_id', $employee->id)
        ->assertSet('payee_name', 'Jane Doe');

    expect(Contact::query()->count())->toBe(1);
});

it('refuses a new expense with no payee chosen, using the picker message', function () {
    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payment_account_id', $this->bank->id)
        ->set('lines', [payeeComboExpenseLine($this->expenseAccount->id)])
        ->call('saveDraft')
        ->assertHasErrors(['payee_name' => 'Choose a payee, or add the name as an Other name.']);

    expect(Expense::query()->count())->toBe(0);
});
