<?php

use App\Actions\Banking\CreateBankRuleFromLine;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
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

    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('saves a rule with a vendor and lists it', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'L Socio Digital']);

    Livewire::test('pages::banking.rules', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Socio')
        ->set('f_match_pattern', 'SOCIO')
        ->set('f_action_account_id', $this->expense->id)
        ->set('f_action_contact_id', $vendor->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="bank-rule-vendor"')
        ->assertSee('L Socio Digital');

    expect(BankRule::query()->firstOrFail()->action_contact_id)->toBe($vendor->id);
});

it('rejects a contact from another company', function () {
    $foreign = Company::factory()->create();
    app()->instance('current_company', $foreign);
    $other = Contact::factory()->vendor()->create();
    app()->instance('current_company', $this->company);

    Livewire::test('pages::banking.rules', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Bad')
        ->set('f_match_pattern', 'x')
        ->set('f_action_account_id', $this->expense->id)
        ->set('f_action_contact_id', $other->id)
        ->call('save')
        ->assertHasErrors('f_action_contact_id');

    expect(BankRule::query()->count())->toBe(0);
});

it('opens a rule created from the import for editing with its vendor and match type', function () {
    $vendor = Contact::factory()->vendor()->create();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $import = BankStatementImport::factory()->create(['account_id' => $bank->id]);
    $line = BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
        'txn_date' => '2026-06-10',
        'amount_cents' => -252000,
        'description' => 'PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812',
        'match_status' => StatementLineMatchStatus::Unmatched->value,
    ]);

    $rule = app(CreateBankRuleFromLine::class)->handle($line, $this->expense->id, $vendor->id);

    Livewire::test('pages::banking.rules', ['company' => $this->company])
        ->assertSee('Same payee')
        ->call('openEdit', $rule->id)
        ->assertSet('f_action_contact_id', $vendor->id)
        ->assertSet('f_match_type', 'merchant_key')
        ->assertSet('f_match_pattern', 'pre authorized payment l socio digital fee fra');
});
