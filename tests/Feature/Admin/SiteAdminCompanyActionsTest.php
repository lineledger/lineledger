<?php

use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\Section;
use App\Models\Company;
use App\Models\User;
use App\Support\Navigation\SidebarNavCatalog;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::forget('site_settings');

    $this->admin = User::factory()->siteAdmin()->create();
    $this->owner = User::factory()->create();
    $this->company = Company::factory()->create([
        'name' => 'Override Inc',
        'address_country' => Country::Canada->value,
        'features_payroll' => false,
    ]);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
});

it('blocks non-admins from the company detail page', function () {
    $this->actingAs($this->owner);

    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->assertStatus(404);
});

it('grants the payroll override and bypasses the platform-wide kill switch', function () {
    SiteSettings::set('disabled_sections', [Section::Payroll->value]);
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('togglePayrollOverride');

    $this->company->refresh();
    expect($this->company->payroll_admin_enabled_at)->not->toBeNull();
    expect($this->company->payroll_admin_enabled_by)->toBe($this->admin->email);

    // Override implies features_payroll for usesPayroll().
    expect($this->company->usesPayroll())->toBeTrue();

    // Section gate respects the per-company override.
    expect($this->company->sectionEnabled(Section::Payroll))->toBeTrue();

    // Sidebar nav still surfaces Payroll for this tenant.
    $keys = collect(SidebarNavCatalog::forUser($this->company, $this->owner))->pluck('key');
    expect($keys)->toContain('payroll');

    // The route works even with the global kill switch on.
    app()->instance('current_company', $this->company);
    $this->actingAs($this->owner)
        ->get(route('payroll.index', ['company' => $this->company->slug]))
        ->assertOk();
});

it('does not leak the override to other companies', function () {
    SiteSettings::set('disabled_sections', [Section::Payroll->value]);

    $other = Company::factory()->create([
        'address_country' => Country::Canada->value,
        'features_payroll' => true,
    ]);
    $other->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->admin);
    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('togglePayrollOverride');

    expect($other->sectionEnabled(Section::Payroll))->toBeFalse();
});

it('toggles the override off when called again', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('togglePayrollOverride')
        ->call('togglePayrollOverride');

    $this->company->refresh();
    expect($this->company->payroll_admin_enabled_at)->toBeNull();
    expect($this->company->payroll_admin_enabled_by)->toBeNull();
});
