<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'fiscal_year_start_month' => 1,
        'organization_type' => OrganizationType::Partnership->value,
    ]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function t5013Entry(Account $debit, Account $credit, int $cents, string $date): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $debit->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $credit->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
}

it('renders the GIFI schedules for a partnership', function () {
    t5013Entry($this->bank, $this->income, 100000, '2026-03-15');

    Livewire::test('pages::reports.t5013', ['company' => $this->company])
        ->assertOk()
        ->assertSee('Schedule 100')
        ->assertSee('Schedule 125')
        ->assertSee('Schedule 50');
});

it('allocates net income across partners and ties to the total', function () {
    t5013Entry($this->bank, $this->income, 100001, '2026-03-15'); // odd cents to exercise rounding

    Partner::factory()->for($this->company)->create(['share_bps' => 6000]);
    Partner::factory()->for($this->company)->create(['share_bps' => 4000]);

    $allocation = Livewire::test('pages::reports.t5013', ['company' => $this->company])
        ->instance()
        ->allocation();

    expect(collect($allocation['rows'])->sum('amount'))->toBe(100001)
        ->and($allocation['rows'][0]['amount'])->toBe(60001) // 60% rounded, remainder to last
        ->and($allocation['rows'][1]['amount'])->toBe(40000);
});

it('adds and removes partners from the report', function () {
    Livewire::test('pages::reports.t5013', ['company' => $this->company])
        ->set('partnerName', 'Alex Partner')
        ->set('partnerShare', '50')
        ->call('addPartner')
        ->assertHasNoErrors();

    $partner = Partner::query()->where('company_id', $this->company->id)->first();
    expect($partner->name)->toBe('Alex Partner')
        ->and($partner->share_bps)->toBe(5000);

    Livewire::test('pages::reports.t5013', ['company' => $this->company])
        ->call('deletePartner', $partner->id);

    expect(Partner::query()->where('company_id', $this->company->id)->count())->toBe(0);
});

it('is gated to Canadian partnerships', function () {
    $corp = Company::factory()->create(['organization_type' => OrganizationType::Corporation->value]);
    $corp->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user)
        ->get(route('reports.t5013', ['company' => $corp->slug]))
        ->assertNotFound();
});
