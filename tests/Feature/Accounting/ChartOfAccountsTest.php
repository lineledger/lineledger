<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Posting\JournalPoster;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

it('seeds a default chart of accounts when a company is created', function () {
    $company = Company::factory()->create();

    $count = Account::withoutGlobalScopes()->where('company_id', $company->id)->count();

    expect($count)->toBeGreaterThan(20);

    $ar = Account::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('subtype', AccountSubtype::AccountsReceivable->value)
        ->first();

    expect($ar)->not->toBeNull();
    expect($ar->is_system)->toBeTrue();
});

it('rejects a parent account of a different type', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openCreate')
        ->set('form_code', '6500')
        ->set('form_name', 'Subscriptions')
        ->set('form_subtype', AccountSubtype::Expense->value)
        ->set('form_parent_id', $bank->id) // a Bank can't parent an Expense
        ->call('save')
        ->assertHasErrors('form_parent_id');

    app()->forgetInstance('current_company');
});

it('allows a same-type parent account', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $parent = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openCreate')
        ->set('form_code', '6510')
        ->set('form_name', 'Software')
        ->set('form_subtype', AccountSubtype::Expense->value)
        ->set('form_parent_id', $parent->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->where('code', '6510')->first()->parent_id)->toBe($parent->id);

    app()->forgetInstance('current_company');
});

it('creates a foreign-currency bank account from the form when multi-currency is on', function () {
    $company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $company);
    app(EnableCompanyCurrency::class)->handle($company, 'USD');

    Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openCreate')
        ->set('form_subtype', AccountSubtype::Bank->value)
        ->assertSeeHtml('data-test="account-currency-select"') // shows for Bank under multi-currency
        ->set('form_code', '1015')
        ->set('form_name', 'USD Chequing')
        ->set('form_currency_code', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->where('code', '1015')->first()->currency_code)->toBe('USD');

    app()->forgetInstance('current_company');
});

it('ignores a currency on a bank account when multi-currency is disabled', function () {
    $company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $company);

    $account = app(SaveAccount::class)->handle([
        'code' => '1015', 'name' => 'USD Chequing',
        'subtype' => AccountSubtype::Bank->value,
        'currency_code' => 'USD',
    ]);

    expect($account->currency_code)->toBeNull();

    app()->forgetInstance('current_company');
});

it('enforces company scope when current_company is bound', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app()->instance('current_company', $companyA);

    expect(Account::query()->count())
        ->toBe(Account::withoutGlobalScopes()->where('company_id', $companyA->id)->count());

    app()->instance('current_company', $companyB);

    expect(Account::query()->count())
        ->toBe(Account::withoutGlobalScopes()->where('company_id', $companyB->id)->count());

    app()->forgetInstance('current_company');
});

it('allows saving an edit without changing the code', function () {
    $company = Company::factory()->create();

    app()->instance('current_company', $company);

    $account = Account::query()->where('code', '1000')->first();

    Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openEdit', $account->id)
        ->set('form_description', 'Primary chequing')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->description)->toBe('Primary chequing');

    app()->forgetInstance('current_company');
});

it('prevents editing accounts from a different company', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app()->instance('current_company', $companyA);

    $foreignAccount = Account::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('code', '1000')
        ->first();

    expect(fn () => Livewire::test('pages::accounts.index', ['company' => $companyA])
        ->call('openEdit', $foreignAccount->id))
        ->toThrow(ModelNotFoundException::class);

    app()->forgetInstance('current_company');
});

it('shows income accounts at their current-fiscal-year balance, not lifetime', function () {
    $company = Company::factory()->create(['fiscal_year_start_month' => 1]);

    app()->instance('current_company', $company);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $post = function (string $date, int $cents) use ($bank, $income) {
        $entry = JournalEntry::create(['entry_no' => 'JE-'.$date, 'entry_date' => $date]);
        $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
        app(JournalPoster::class)->post($entry);
    };

    // Prior fiscal year + this fiscal year. Today is 2026-05-22 (Jan FY start).
    $post('2025-06-01', 30000);
    $post('2026-02-01', 10000);

    $component = Livewire::test('pages::accounts.index', ['company' => $company]);
    $balances = $component->instance()->balances;

    // Income: only the current fiscal year's $100, not the lifetime $400.
    expect($balances[$income->id])->toBe(10000);
    // Balance-sheet (bank): cumulative across years.
    expect($balances[$bank->id])->toBe(40000);

    app()->forgetInstance('current_company');
});

it('allows changing the code of a system account', function () {
    $company = Company::factory()->create();

    app()->instance('current_company', $company);

    $system = Account::query()->where('is_system', true)->first();

    expect($system)->not->toBeNull();

    Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openEdit', $system->id)
        ->set('form_code', '1234')
        ->call('save')
        ->assertHasNoErrors();

    expect($system->fresh()->code)->toBe('1234');

    app()->forgetInstance('current_company');
});

it('keeps a system account subtype frozen even when a different subtype is submitted', function () {
    $company = Company::factory()->create();

    app()->instance('current_company', $company);

    $system = Account::query()->where('is_system', true)->first();
    $originalSubtype = $system->subtype;

    $otherSubtype = collect(AccountSubtype::cases())
        ->first(fn (AccountSubtype $subtype): bool => $subtype !== $originalSubtype);

    app(SaveAccount::class)->handle([
        'code' => '1357',
        'name' => $system->name,
        'subtype' => $otherSubtype->value,
    ], $system);

    $fresh = $system->fresh();

    // Code change went through; subtype/type stayed frozen.
    expect($fresh->code)->toBe('1357');
    expect($fresh->subtype)->toBe($originalSubtype);
    expect($fresh->type)->toBe($originalSubtype->type());

    app()->forgetInstance('current_company');
});

it('persists and clears a cash-flow activity override on a balance-sheet account', function () {
    $company = Company::factory()->create();

    app()->instance('current_company', $company);

    $fixedAsset = Account::query()->where('subtype', AccountSubtype::FixedAsset->value)->first();

    $component = Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openEdit', $fixedAsset->id)
        ->set('form_cash_flow_activity', 'operating')
        ->call('save')
        ->assertHasNoErrors();

    expect($fixedAsset->fresh()->cash_flow_activity->value)->toBe('operating');

    // Saving "Auto" (empty) clears the override back to null.
    $component->call('openEdit', $fixedAsset->id)
        ->set('form_cash_flow_activity', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($fixedAsset->fresh()->cash_flow_activity)->toBeNull();

    app()->forgetInstance('current_company');
});

it('drops a cash-flow activity override for an account that is not its own activity line', function () {
    $company = Company::factory()->create();

    app()->instance('current_company', $company);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    app(SaveAccount::class)->handle([
        'code' => $income->code,
        'name' => $income->name,
        'subtype' => $income->subtype->value,
        'cash_flow_activity' => 'operating',
    ], $income);

    // Income collapses into Net Income — it never carries an override.
    expect($income->fresh()->cash_flow_activity)->toBeNull();

    app()->forgetInstance('current_company');
});

it('requires unique code per company', function () {
    $company = Company::factory()->create();

    app()->instance('current_company', $company);

    Account::create([
        'code' => '9999',
        'name' => 'Test',
        'subtype' => AccountSubtype::Expense,
        'type' => AccountSubtype::Expense->type(),
        'normal_balance' => AccountSubtype::Expense->type()->normalBalance(),
    ]);

    expect(fn () => Account::create([
        'code' => '9999',
        'name' => 'Dup',
        'subtype' => AccountSubtype::Expense,
        'type' => AccountSubtype::Expense->type(),
        'normal_balance' => AccountSubtype::Expense->type()->normalBalance(),
    ]))->toThrow(QueryException::class);

    app()->forgetInstance('current_company');
});
