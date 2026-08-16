<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Company;
use App\Models\User;
use App\Support\Reporting\ReportCatalog;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/**
 * @return list<string>
 */
function payrollReportKeys(): array
{
    return [
        'payroll.reports.register',
        'payroll.reports.pd7a',
        'payroll.reports.revenu-quebec',
        'payroll.reports.workers-comp',
        'payroll.reports.remittances',
        'payroll.reports.t4',
        'payroll.reports.t4a',
        'payroll.reports.rl1',
        'payroll.reports.roe',
        'payroll.reports.verification',
    ];
}

/**
 * @return list<string>
 */
function payrollCatalogKeys(Company $company, User $user): array
{
    return array_keys(ReportCatalog::flatten($company, $user));
}

it('lists every payroll report for a Canadian payroll company and renders the cards', function () {
    expect(payrollCatalogKeys($this->company, $this->user))
        ->toContain(...payrollReportKeys());

    Livewire::actingAs($this->user)
        ->test('pages::reports.index', ['company' => $this->company])
        ->assertSee('Employees & Payroll')
        ->assertSeeHtml('data-test="report-card-payroll.reports.pd7a"')
        ->assertSeeHtml('data-test="report-card-payroll.reports.register"');
});

it('hides payroll reports when the payroll feature is off', function () {
    $this->company->update(['features_payroll' => false]);

    expect(payrollCatalogKeys($this->company->fresh(), $this->user))
        ->not->toContain(...payrollReportKeys());
});

it('hides payroll reports for a US company even with the feature flag on', function () {
    $us = Company::factory()->create(['address_country' => 'US', 'features_payroll' => true]);
    $owner = User::factory()->create();
    $us->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    expect(payrollCatalogKeys($us, $owner))->not->toContain(...payrollReportKeys());
});

it('hides payroll reports from a member without the payroll section', function () {
    $member = User::factory()->create();
    $this->company->memberships()->create([
        'user_id' => $member->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Reports->value],
    ]);

    expect(payrollCatalogKeys($this->company, $member))->not->toContain(...payrollReportKeys())
        ->and(payrollCatalogKeys($this->company, $this->user))->toContain(...payrollReportKeys());
});

it('surfaces only the PD7A card when searching for pd7a', function () {
    $categories = Livewire::actingAs($this->user)
        ->test('pages::reports.index', ['company' => $this->company])
        ->set('search', 'pd7a')
        ->instance()
        ->categories();

    $labels = collect($categories)
        ->flatMap(fn (array $category) => collect($category['reports'])->pluck('label'))
        ->all();

    expect($labels)->toBe(['PD7A Remittance']);
});

it('toggles a favorite on a payroll report', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.index', ['company' => $this->company]);

    $component->call('toggleFavorite', 'payroll.reports.pd7a');

    $this->assertDatabaseHas('report_favorites', [
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => 'payroll.reports.pd7a',
    ]);

    expect(collect($component->instance()->favorites())->pluck('key'))
        ->toContain('payroll.reports.pd7a');

    $component->call('toggleFavorite', 'payroll.reports.pd7a');

    $this->assertDatabaseMissing('report_favorites', [
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => 'payroll.reports.pd7a',
    ]);
});
