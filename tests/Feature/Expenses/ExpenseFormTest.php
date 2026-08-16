<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\ExpenseStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\PaymentMethod;
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
function expenseLineInput(int $accountId): array
{
    return [
        'account_id' => $accountId,
        'description' => 'Hosting',
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

it('creates and posts an expense via the form', function () {
    $method = PaymentMethod::create(['name' => 'Visa', 'is_active' => true]);

    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payment_account_id', $this->bank->id)
        ->set('payment_method_id', $method->id)
        ->set('payee_name', 'Cloud Host')
        ->set('reference', 'TXN-9')
        ->set('lines', [expenseLineInput($this->expenseAccount->id)])
        ->call('postExpense')
        ->assertHasNoErrors();

    $exp = Expense::firstOrFail();

    expect($exp->status)->toBe(ExpenseStatus::Posted)
        ->and($exp->amount_cents)->toBe(8000)
        ->and($exp->payment_method_id)->toBe($method->id)
        ->and($exp->journal_entry_id)->not->toBeNull()
        // Bank is debit-normal: a credit of 8000 → balance -8000
        ->and($this->bank->fresh()->balance_cents)->toBe(-8000);
});

it('saves an expense as a draft without posting', function () {
    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payment_account_id', $this->bank->id)
        ->set('payee_name', 'Cloud Host')
        ->set('lines', [expenseLineInput($this->expenseAccount->id)])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $exp = Expense::firstOrFail();

    expect($exp->status)->toBe(ExpenseStatus::Draft)
        ->and($exp->journal_entry_id)->toBeNull();
});

it('prefills the memo from the payee account number', function () {
    $vendor = Contact::create([
        'company_id' => $this->company->id,
        'display_name' => 'Acme',
        'account_no' => 'AC-1',
        'is_vendor' => true,
    ]);

    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payee_contact_id', $vendor->id)
        ->assertSet('payee_name', 'Acme')
        ->assertSet('memo', 'AC-1');
});

it('filters the expense index by payment method', function () {
    $visa = PaymentMethod::create(['name' => 'Visa', 'is_active' => true]);
    $interac = PaymentMethod::create(['name' => 'Interac', 'is_active' => true]);

    Expense::create(['payment_account_id' => $this->bank->id, 'payment_method_id' => $visa->id, 'expense_date' => now()->toDateString(), 'payee_name' => 'A']);
    Expense::create(['payment_account_id' => $this->bank->id, 'payment_method_id' => $interac->id, 'expense_date' => now()->toDateString(), 'payee_name' => 'B']);

    $rows = Livewire::test('pages::expenses.index', ['company' => $this->company])
        ->set('methodFilter', $visa->id)
        ->instance()
        ->expenses();

    expect($rows->total())->toBe(1)
        ->and($rows->first()->payee_name)->toBe('A');
});
