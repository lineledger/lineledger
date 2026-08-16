<?php

use App\Actions\Banking\SaveDeposit;
use App\Enums\AccountSubtype;
use App\Enums\BankRuleMatchType;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\User;
use App\Services\Banking\Import\BankRuleEngine;
use App\Services\Posting\DepositPoster;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->equity = Account::query()->where('subtype', AccountSubtype::Equity->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('nets a fee line out of a deposit so only the net hits the bank', function () {
    $deposit = app(SaveDeposit::class)->handle([
        'bank_account_id' => $this->bank->id,
        'deposit_date' => '2026-06-01',
        'lines' => [
            ['account_id' => $this->equity->id, 'amount_cents' => 100000],
            ['account_id' => $this->expense->id, 'amount_cents' => -1000], // merchant fee
        ],
    ]);

    $entry = app(DepositPoster::class)->post($deposit);
    $entry->load('lines');

    expect($deposit->fresh()->amount_cents)->toBe(99000)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(99000)
        ->and($entry->lines->firstWhere('account_id', $this->expense->id)->debit_cents)->toBe(1000)
        ->and($entry->lines->firstWhere('account_id', $this->equity->id)->credit_cents)->toBe(100000);
});

it('auto-categorizes an unmatched imported line via a bank rule', function () {
    BankRule::create([
        'name' => 'AWS',
        'match_type' => 'contains',
        'match_pattern' => 'AWS',
        'action_account_id' => $this->expense->id,
        'is_active' => true,
        'priority' => 0,
    ]);

    $import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $line = BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $this->bank->id,
        'description' => 'AWS Cloud Services',
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'suggested_account_id' => null,
    ]);

    $applied = app(BankRuleEngine::class)->apply($import);

    expect($applied)->toBe(1)
        ->and($line->fresh()->suggested_account_id)->toBe($this->expense->id);
});

it('matches rule patterns case-insensitively by type', function () {
    expect(BankRuleMatchType::Contains->matches('STRIPE PAYOUT 123', 'stripe'))->toBeTrue()
        ->and(BankRuleMatchType::StartsWith->matches('Stripe payout', 'stripe'))->toBeTrue()
        ->and(BankRuleMatchType::StartsWith->matches('Refund from Stripe', 'stripe'))->toBeFalse()
        ->and(BankRuleMatchType::Equals->matches('Stripe', 'STRIPE'))->toBeTrue()
        ->and(BankRuleMatchType::Regex->matches('Cheque #1042', 'cheque #\d+'))->toBeTrue();
});
