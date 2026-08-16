<?php

use App\Actions\Reporting\SeedReportGroupMappings;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\ReportGroup;
use App\Models\ReportGroupAccountMap;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: Collection<int, Company>}
 */
function userWithCompanies(int $count, string $currency = 'CAD'): array
{
    $user = User::factory()->create();

    $companies = collect(range(1, $count))->map(function () use ($user, $currency) {
        $company = Company::factory()->create(['currency_code' => $currency]);
        $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

        return $company;
    });

    return [$user, $companies];
}

it('creates a group with two companies and auto-seeds mappings', function () {
    [$user, $companies] = userWithCompanies(2);
    $this->actingAs($user);

    Livewire::test('pages::report-groups.index')
        ->set('f_name', 'My Combined')
        ->set('f_companies', $companies->pluck('id')->all())
        ->call('create')
        ->assertHasNoErrors();

    $group = ReportGroup::firstWhere('name', 'My Combined');

    expect($group)->not->toBeNull()
        ->and($group->currency_code)->toBe('CAD')
        ->and($group->companies()->count())->toBe(2)
        ->and($group->lines()->count())->toBeGreaterThan(0)
        ->and($group->accountMaps()->count())->toBeGreaterThan(0);
});

it('rejects a group whose companies use different currencies', function () {
    $user = User::factory()->create();
    $cad = Company::factory()->create(['currency_code' => 'CAD']);
    $usd = Company::factory()->create(['currency_code' => 'USD']);
    foreach ([$cad, $usd] as $c) {
        $c->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    }
    $this->actingAs($user);

    Livewire::test('pages::report-groups.index')
        ->set('f_name', 'Mixed')
        ->set('f_companies', [$cad->id, $usd->id])
        ->call('create')
        ->assertHasErrors('f_companies');

    expect(ReportGroup::count())->toBe(0);
});

it('requires at least two companies', function () {
    [$user, $companies] = userWithCompanies(1);
    $this->actingAs($user);

    Livewire::test('pages::report-groups.index')
        ->set('f_name', 'Solo')
        ->set('f_companies', $companies->pluck('id')->all())
        ->call('create')
        ->assertHasErrors('f_companies');
});

it('lets co-members view but only the creator edit', function () {
    [$creator, $companies] = userWithCompanies(2);
    $coMember = User::factory()->create();
    $outsider = User::factory()->create();

    foreach ($companies as $company) {
        $company->members()->attach($coMember, ['role' => CompanyRole::Accountant->value]);
    }

    $group = ReportGroup::create(['user_id' => $creator->id, 'name' => 'G', 'currency_code' => 'CAD']);
    $group->companies()->attach($companies->pluck('id')->all());

    expect($coMember->can('view', $group))->toBeTrue()
        ->and($coMember->can('update', $group))->toBeFalse()
        ->and($outsider->can('view', $group))->toBeFalse()
        ->and($creator->can('update', $group))->toBeTrue();
});

it('forbids a non-creator from opening the editor', function () {
    [$creator, $companies] = userWithCompanies(2);
    $outsider = User::factory()->create();

    $group = ReportGroup::create(['user_id' => $creator->id, 'name' => 'G', 'currency_code' => 'CAD']);
    $group->companies()->attach($companies->pluck('id')->all());

    $this->actingAs($outsider)
        ->get(route('report-groups.edit', $group))
        ->assertForbidden();
});

it('blocks adding a company with a mismatched currency', function () {
    [$user, $companies] = userWithCompanies(2);
    $usd = Company::factory()->create(['currency_code' => 'USD']);
    $usd->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $group = ReportGroup::create(['user_id' => $user->id, 'name' => 'G', 'currency_code' => 'CAD']);
    $group->companies()->attach($companies->pluck('id')->all());

    $this->actingAs($user);

    Livewire::test('pages::report-groups.edit', ['reportGroup' => $group])
        ->set('addCompanyId', $usd->id)
        ->call('addCompany')
        ->assertHasErrors('addCompanyId');

    expect($group->companies()->count())->toBe(2);
});

it('moves an account from one line to another', function () {
    [$user, $companies] = userWithCompanies(2);

    $group = ReportGroup::create(['user_id' => $user->id, 'name' => 'G', 'currency_code' => 'CAD']);
    $group->companies()->attach($companies->pluck('id')->all());
    app(SeedReportGroupMappings::class)->handle($group);

    $this->actingAs($user);

    $map = $group->accountMaps()->first();
    $otherLine = $group->lines()->where('id', '!=', $map->report_group_line_id)->first();

    Livewire::test('pages::report-groups.edit', ['reportGroup' => $group])
        ->call('moveAccount', $map->id, $otherLine->id);

    expect(ReportGroupAccountMap::find($map->id)->report_group_line_id)->toBe($otherLine->id);
});
