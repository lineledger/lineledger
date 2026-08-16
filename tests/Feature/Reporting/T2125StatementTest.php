<?php

use App\Enums\AccountSubtype;
use App\Enums\CcaClass;
use App\Enums\CompanyRole;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Models\CcaPool;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'fiscal_year_start_month' => 1,
        'organization_type' => OrganizationType::SoleProprietorship->value,
    ]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function t2125Entry(Account $debit, Account $credit, int $cents, string $date): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $debit->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $credit->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

it('renders the T2125 parts and ties income to the ledger', function () {
    t2125Entry($this->bank, $this->income, 100000, '2026-03-15');
    t2125Entry($this->expense, $this->bank, 40000, '2026-03-20');

    $component = Livewire::test('pages::reports.t2125', ['company' => $this->company])
        ->assertOk()
        ->assertSee('Parts 3 & 4')
        ->assertSee('Part 7')
        ->assertSee('Part 5');

    expect($component->instance()->report()['is']['net_income'])->toBe(60000);
});

it('saves opening UCC from the CCA worksheet', function () {
    Livewire::test('pages::reports.t2125', ['company' => $this->company])
        ->set('openingDollars.8', '1000')
        ->call('saveOpeningUcc', '8');

    $pool = CcaPool::query()->where('company_id', $this->company->id)->where('cca_class', CcaClass::Class8->value)->first();

    expect($pool)->not->toBeNull()
        ->and($pool->opening_ucc_cents)->toBe(100000)
        ->and($pool->tax_year)->toBe(2026);
});

it('reduces net income by the CCA claimed', function () {
    t2125Entry($this->bank, $this->income, 100000, '2026-03-15');
    CcaPool::create(['company_id' => $this->company->id, 'tax_year' => 2026, 'cca_class' => CcaClass::Class8->value, 'opening_ucc_cents' => 100000]);

    $cca = Livewire::test('pages::reports.t2125', ['company' => $this->company])->instance()->cca();

    // CCA = 1000 * 20% = 200.
    expect($cca['total_cca_cents'])->toBe(20000);
});

it('is gated to Canadian sole proprietors', function () {
    $corp = Company::factory()->create(['organization_type' => OrganizationType::Corporation->value]);
    $corp->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user)
        ->get(route('reports.t2125', ['company' => $corp->slug]))
        ->assertNotFound();
});
