<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\ReportGroup;
use App\Models\ReportGroupAccountMap;
use App\Models\ReportGroupLine;
use App\Models\User;
use App\Services\Reporting\CombinedReportCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Combined report groups sit outside the {company} routes, so section access
 * never ran on them: a member walled off from Reports could read a company's
 * full statements through a group, and a soft-deleted company's ledger kept
 * appearing (R07, audit D7Security-05 / D9Reporting-01).
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->a = Company::factory()->create(['name' => 'Alpha']);
    $this->b = Company::factory()->create(['name' => 'Bravo']);
    $this->a->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->group = ReportGroup::create(['user_id' => $this->user->id, 'name' => 'Combined', 'currency_code' => 'CAD']);
    $this->group->companies()->attach([$this->a->id, $this->b->id]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

$groupRoutes = [
    'report-groups.balance-sheet', 'report-groups.income-statement', 'report-groups.cash-flow',
    'report-groups.trial-balance', 'report-groups.edit', 'report-groups.balance-sheet.sections',
    'report-groups.income-statement.sections', 'report-groups.cash-flow.sections',
];

it('refuses every group page to a member without Reports in one of its companies', function (string $route) {
    $this->b->memberships()->create([
        'user_id' => $this->user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Customers->value],
    ]);

    $this->actingAs($this->user)
        ->get(route($route, ['reportGroup' => $this->group]))
        ->assertForbidden();
})->with($groupRoutes);

it('opens the group once the member can open Reports in every company', function () {
    $this->b->memberships()->create([
        'user_id' => $this->user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Customers->value, Section::Reports->value],
    ]);

    $this->actingAs($this->user)
        ->get(route('report-groups.balance-sheet', ['reportGroup' => $this->group]))
        ->assertOk();
});

it('re-checks access on every Livewire request, not only on page load', function () {
    $membership = $this->b->memberships()->create(['user_id' => $this->user->id, 'role' => CompanyRole::Accountant]);

    $page = Livewire::actingAs($this->user)
        ->test('pages::report-groups.balance-sheet', ['reportGroup' => $this->group])
        ->assertOk();

    $membership->update(['role' => CompanyRole::Custom, 'sections' => [Section::Customers->value]]);

    $page->call('$refresh')->assertForbidden();
});

it('lets a creator who lost access to a member company delete the group but not edit it', function () {
    // The creator belongs to Alpha only.
    $this->actingAs($this->user)
        ->get(route('report-groups.edit', ['reportGroup' => $this->group]))
        ->assertForbidden();

    expect(Gate::forUser($this->user)->allows('update', $this->group))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('delete', $this->group))->toBeTrue();
});

it('only offers companies the user can open Reports in when building a group', function () {
    $this->b->memberships()->create([
        'user_id' => $this->user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Customers->value],
    ]);

    $options = Livewire::actingAs($this->user)
        ->test('pages::report-groups.index')
        ->instance()
        ->ownedCompanyOptions
        ->pluck('id');

    expect($options)->toContain($this->a->id)
        ->and($options)->not->toContain($this->b->id);
});

it('drops a soft-deleted company from the group and from its figures', function () {
    $this->b->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    // $100 into Alpha's bank, $50 into Bravo's, both on one combined Cash line.
    $line = ReportGroupLine::create([
        'report_group_id' => $this->group->id,
        'name' => 'Cash',
        'type' => AccountType::Asset,
        'subtype' => AccountSubtype::Bank,
        'sort_order' => 0,
    ]);

    foreach ([[$this->a, 10000], [$this->b, 5000]] as [$company, $cents]) {
        app()->instance('current_company', $company);

        $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
        $income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();

        $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => '2026-03-01', 'is_posted' => true]);
        $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);

        ReportGroupAccountMap::create([
            'report_group_id' => $this->group->id,
            'report_group_line_id' => $line->id,
            'company_id' => $company->id,
            'account_id' => $bank->id,
        ]);
    }

    app()->forgetInstance('current_company');
    $calc = app(CombinedReportCalculator::class);
    $asOf = CarbonImmutable::create(2026, 4, 1);

    expect($calc->balanceSheet($this->group, $asOf)['total_assets'])->toBe(15000);

    $this->b->delete();

    // Bravo's ledger is gone from the group — including for a viewer who was
    // never one of its members (they can now open the group: Alpha is all
    // that is left in it).
    $alphaOnly = User::factory()->create(['email_verified_at' => now()]);
    $this->a->members()->attach($alphaOnly, ['role' => CompanyRole::Accountant->value]);

    expect($calc->companies($this->group->fresh())->keys()->all())->toBe([$this->a->id])
        ->and($calc->balanceSheet($this->group->fresh(), $asOf)['total_assets'])->toBe(10000)
        ->and($this->group->fresh()->isVisibleTo($alphaOnly))->toBeTrue();
});
