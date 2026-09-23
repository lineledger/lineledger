<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

/**
 * A cheque line can be coded to any account — a revenue account included (a
 * refund of a sale, a reversed commission), not just the expense, asset,
 * liability and equity accounts the picker used to offer.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('lists an active account of every type on a cheque line', function () {
    $ids = Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->instance()->lineAccountOptions()->pluck('id');

    foreach (AccountType::cases() as $type) {
        $account = Account::query()->where('type', $type->value)->where('is_active', true)->orderBy('code')->firstOrFail();

        expect($ids)->toContain($account->id);
    }
});

it('leaves an inactive account off the list unless a line already uses it', function () {
    $this->income->update(['is_active' => false]);

    $component = Livewire::test('pages::cheques.form', ['company' => $this->company]);

    expect($component->instance()->lineAccountOptions()->pluck('id'))->not->toContain($this->income->id);

    $component->set('lines.0.account_id', $this->income->id);

    expect($component->instance()->lineAccountOptions()->pluck('id'))->toContain($this->income->id);
});

it('posts a cheque line coded to a revenue account', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->bank->id)
        ->set('payee_name', 'Refunded customer')
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.amount', '25.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    $leg = JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_type', Cheque::class))
        ->where('account_id', $this->income->id)
        ->firstOrFail();

    expect((int) $leg->debit_cents)->toBe(2500);
});

it('does not copy a sales-only default tax code onto a cheque line', function () {
    $salesOnly = TaxCode::create([
        'code' => 'SALEONLY',
        'name' => 'Sales-only tax',
        'rate_basis_points' => 500,
        'applies_to' => TaxAppliesTo::SaleOnly,
        'is_active' => true,
    ]);
    $this->income->update(['default_tax_code_id' => $salesOnly->id]);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->income->id)
        ->assertSet('lines.0.tax_code_id', null);
});
