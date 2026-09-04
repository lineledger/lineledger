<?php

use App\Actions\Banking\CreateBankRuleFromLine;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BankRuleMatchType;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $this->expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->skip(1)->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $this->action = app(CreateBankRuleFromLine::class);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function ruleSourceLine(string $description, int $amount = -252000): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->bank->id,
        'txn_date' => '2026-06-10',
        'amount_cents' => $amount,
        'description' => $description,
        'match_status' => StatementLineMatchStatus::Unmatched->value,
    ]);
}

it('creates a merchant-key rule carrying the account and vendor, and fills sibling lines', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'L Socio Digital']);
    $line = ruleSourceLine('PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812');
    $sibling = ruleSourceLine('Pre-Authorized Payment, L SOCIO DIGITAL FEE/FRA    ,');
    $unrelated = ruleSourceLine('TIM HORTONS #1234');

    $rule = $this->action->handle($line, $this->expense->id, $vendor->id);

    expect($rule->match_type)->toBe(BankRuleMatchType::MerchantKey)
        ->and($rule->match_pattern)->toBe('pre authorized payment l socio digital fee fra')
        ->and($rule->name)->toBe('L Socio Digital')
        ->and($rule->action_account_id)->toBe($this->expense->id)
        ->and($rule->action_contact_id)->toBe($vendor->id)
        ->and($rule->is_active)->toBeTrue()
        ->and($line->fresh()->suggestion_source)->toBe(StatementSuggestionSource::Rule)
        ->and($sibling->fresh()->suggested_account_id)->toBe($this->expense->id)
        ->and($sibling->fresh()->suggested_contact_id)->toBe($vendor->id)
        ->and($sibling->fresh()->match_status)->toBe(StatementLineMatchStatus::Unmatched) // suggested, not confirmed
        ->and($unrelated->fresh()->suggested_account_id)->toBeNull()
        ->and($this->action->existingFor($sibling)?->id)->toBe($rule->id);
});

it('updates the existing rule for the same payee instead of stacking duplicates', function () {
    $line = ruleSourceLine('HYDRO ONE BILL PAYMENT CONF 77A1B2');

    $first = $this->action->handle($line, $this->expense->id);
    $second = $this->action->handle(ruleSourceLine('HYDRO ONE BILL PAYMENT CONF 99Z9Z9'), $this->expenseB->id);

    expect(BankRule::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->action_account_id)->toBe($this->expenseB->id);
});

it('names the rule after the payee text when no vendor is given', function () {
    $rule = $this->action->handle(ruleSourceLine('TIM HORTONS #1234 TORONTO ON'), $this->expense->id);

    expect($rule->name)->toBe('Tim Hortons Toronto')
        ->and($rule->action_contact_id)->toBeNull();
});

it('refuses a description too generic to key on', function () {
    expect(fn () => $this->action->handle(ruleSourceLine('CHQ 00123'), $this->expense->id))
        ->toThrow(ValidationException::class);

    expect(BankRule::query()->count())->toBe(0);
});

it('matches merchant-key rules across changed reference numbers and dates', function () {
    expect(BankRuleMatchType::MerchantKey->matches('PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 1', 'pre authorized payment l socio digital fee fra'))->toBeTrue()
        ->and(BankRuleMatchType::MerchantKey->matches('L SOCIO DIGITAL FEE/FRA 2026-09-03', 'Pre-Authorized Payment, L SOCIO DIGITAL FEE/FRA'))->toBeFalse()
        ->and(BankRuleMatchType::MerchantKey->matches('anything', 'chq'))->toBeFalse();
});
