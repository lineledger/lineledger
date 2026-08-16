<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ExpenseStatus;
use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\TaxCode;
use App\Services\Classification\CategorySuggester;
use App\Services\Classification\CategorySuggestion;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->suggester = app(CategorySuggester::class);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expenseA = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $this->expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->skip(1)->firstOrFail();
    $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** A committed statement line categorized to an account — the description-history signal. */
function createdLine(Account $bank, string $description, int $accountId, string $date, int $amount = -450): BankStatementLine
{
    $import = BankStatementImport::factory()->committed()->create(['account_id' => $bank->id]);

    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
        'txn_date' => $date,
        'amount_cents' => $amount,
        'description' => $description,
        'match_status' => StatementLineMatchStatus::Created->value,
        'suggested_account_id' => $accountId,
    ]);
}

/** A posted expense for a contact with one categorized line — the contact-history signal. */
function postedExpenseFor(Account $payment, int $contactId, Account $category, string $date, ?int $taxCodeId = null): Expense
{
    $expense = Expense::create([
        'payment_account_id' => $payment->id,
        'expense_date' => $date,
        'payee_contact_id' => $contactId,
        'payee_name' => 'history',
        'amount_cents' => 10000,
        'status' => ExpenseStatus::Posted->value,
        'posted_at' => now(),
    ]);

    $expense->lines()->create([
        'account_id' => $category->id,
        'amount_cents' => 10000,
        'tax_code_id' => $taxCodeId,
        'line_order' => 0,
    ]);

    return $expense;
}

it('suggests the account a merchant was categorized to before, ignoring case and spacing', function () {
    createdLine($this->bank, 'TIM HORTONS', $this->expenseA->id, now()->subDays(10)->toDateString());

    $suggestion = $this->suggester->fromDescription($this->company->id, 'tim   hortons');

    expect($suggestion)->toBeInstanceOf(CategorySuggestion::class)
        ->and($suggestion->accountId)->toBe($this->expenseA->id)
        ->and($suggestion->source)->toBe(CategorySuggestion::SOURCE_HISTORY);
});

it('lets the most recent categorization win for a merchant', function () {
    createdLine($this->bank, 'SHELL', $this->expenseA->id, now()->subDays(30)->toDateString());
    createdLine($this->bank, 'SHELL', $this->expenseB->id, now()->subDays(3)->toDateString());

    expect($this->suggester->fromDescription($this->company->id, 'SHELL')->accountId)->toBe($this->expenseB->id);
});

it('ignores history outside the configured window', function () {
    config()->set('classification.history_days', 365);
    createdLine($this->bank, 'OLD VENDOR', $this->expenseA->id, now()->subDays(400)->toDateString());

    expect($this->suggester->fromDescription($this->company->id, 'OLD VENDOR'))->toBeNull();
});

it('suggests the most-used account from a contact prior bills/expenses with its tax code', function () {
    $vendor = Contact::factory()->vendor()->create();

    postedExpenseFor($this->bank, $vendor->id, $this->expenseA, now()->subDays(20)->toDateString(), $this->gst->id);
    postedExpenseFor($this->bank, $vendor->id, $this->expenseA, now()->subDays(10)->toDateString(), $this->gst->id);
    postedExpenseFor($this->bank, $vendor->id, $this->expenseB, now()->subDays(5)->toDateString());

    $suggestion = $this->suggester->fromContact($this->company->id, $vendor->id);

    expect($suggestion->accountId)->toBe($this->expenseA->id)
        ->and($suggestion->taxCodeId)->toBe($this->gst->id)
        ->and($suggestion->source)->toBe(CategorySuggestion::SOURCE_HISTORY);
});

it('prefers a contact explicit default over its history', function () {
    $vendor = Contact::factory()->vendor()->create([
        'default_expense_account_id' => $this->expenseB->id,
        'default_tax_code_id' => $this->gst->id,
    ]);

    postedExpenseFor($this->bank, $vendor->id, $this->expenseA, now()->subDays(5)->toDateString());

    $suggestion = $this->suggester->fromContact($this->company->id, $vendor->id);

    expect($suggestion->accountId)->toBe($this->expenseB->id)
        ->and($suggestion->taxCodeId)->toBe($this->gst->id)
        ->and($suggestion->source)->toBe(CategorySuggestion::SOURCE_CONTACT_DEFAULT);
});

it('never suggests an inactive account', function () {
    $this->expenseA->update(['is_active' => false]);
    createdLine($this->bank, 'GHOST', $this->expenseA->id, now()->subDays(2)->toDateString());

    expect($this->suggester->fromDescription($this->company->id, 'GHOST'))->toBeNull();
});

it('does not leak another company history', function () {
    $vendorA = Contact::factory()->vendor()->create();

    $companyB = Company::factory()->create();
    app()->instance('current_company', $companyB);

    $bankB = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $vendorB = Contact::factory()->vendor()->create();

    createdLine($bankB, 'CROSS TENANT', $expenseB->id, now()->subDays(2)->toDateString());
    postedExpenseFor($bankB, $vendorB->id, $expenseB, now()->subDays(2)->toDateString());

    app()->instance('current_company', $this->company);

    expect($this->suggester->fromDescription($this->company->id, 'CROSS TENANT'))->toBeNull()
        ->and($this->suggester->fromContact($this->company->id, $vendorB->id))->toBeNull();
});
