<?php

use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\Section;
use App\Models\Company;
use App\Models\User;
use App\Support\Navigation\SidebarNavCatalog;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget('site_settings');

    $this->owner = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
});

it('section is enabled by default and listed in the sidebar catalog', function () {
    $keys = collect(SidebarNavCatalog::forUser($this->company, $this->owner))->pluck('key');

    expect($keys)->toContain('accounting');
    expect(SiteSettings::sectionEnabled(Section::Accounting))->toBeTrue();
});

it('disabling a section removes it from the sidebar catalog', function () {
    SiteSettings::set('disabled_sections', [Section::Accounting->value]);

    $keys = collect(SidebarNavCatalog::forUser($this->company, $this->owner))->pluck('key');

    expect($keys)->not->toContain('accounting');
});

it('never lets the Settings section be disabled', function () {
    SiteSettings::set('disabled_sections', [Section::Settings->value]);

    expect(SiteSettings::sectionEnabled(Section::Settings))->toBeTrue();
});

it('serves a section route when its section is enabled', function () {
    $this->actingAs($this->owner)
        ->get(route('accounts.index', ['company' => $this->company->slug]))
        ->assertOk();
});

it('404s a section route when its section is disabled platform-wide', function () {
    SiteSettings::set('disabled_sections', [Section::Accounting->value]);

    $this->actingAs($this->owner)
        ->get(route('accounts.index', ['company' => $this->company->slug]))
        ->assertNotFound();
});

it('404s payroll time-tracking routes when Payroll is disabled platform-wide', function () {
    // Regression: time-entries / time-off-policies map to Payroll, so the
    // platform kill switch must block them too — not just payroll.index.
    $company = Company::factory()->create([
        'address_country' => Country::Canada->value,
        'features_payroll' => true,
    ]);
    $company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    foreach (['time-entries.index', 'time-off-policies.index', 'transfers.index'] as $route) {
        $section = $route === 'transfers.index' ? Section::Banking : Section::Payroll;
        SiteSettings::set('disabled_sections', [$section->value]);

        $this->actingAs($this->owner)
            ->get(route($route, ['company' => $company->slug]))
            ->assertNotFound();
    }
});

it('blocks a normal user with the maintenance page while maintenance is on', function () {
    SiteSettings::set('maintenance_mode', true);

    $this->actingAs($this->owner)
        ->get(route('dashboard', ['company' => $this->company->slug]))
        ->assertStatus(503)
        ->assertSee('maintenance');
});

it('lets a site admin through while maintenance is on', function () {
    SiteSettings::set('maintenance_mode', true);

    $admin = User::factory()->siteAdmin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard', ['company' => $admin->currentCompany->slug]))
        ->assertOk();
});

it('keeps the login page reachable during maintenance', function () {
    SiteSettings::set('maintenance_mode', true);

    $this->get(route('login'))->assertOk();
});

it('persists toggles from the admin settings page', function () {
    $admin = User::factory()->siteAdmin()->create();

    $this->actingAs($admin);

    Livewire\Livewire::test('pages::admin.settings')
        ->set('registrationsEnabled', false)
        ->set('maintenanceMode', true)
        ->set('sectionEnabled.'.Section::Payroll->value, false);

    expect(SiteSettings::registrationsEnabled())->toBeFalse();
    expect(SiteSettings::maintenanceMode())->toBeTrue();
    expect(SiteSettings::sectionEnabled(Section::Payroll))->toBeFalse();
});
