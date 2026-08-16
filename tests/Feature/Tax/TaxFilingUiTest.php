<?php

use App\Enums\CompanyRole;
use App\Enums\LegalStructure;
use App\Enums\OrganizationType;
use App\Models\Company;
use App\Models\User;
use App\Support\Reporting\ReportCatalog;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

function makeCompany(User $user, OrganizationType $org, ?LegalStructure $tier = null, array $extra = []): Company
{
    $company = Company::factory()->create([
        'organization_type' => $org->value,
        'legal_structure' => $tier?->value,
    ] + $extra);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $company);

    return $company;
}

afterEach(fn () => app()->forgetInstance('current_company'));

it('shows GIFI to corporations only, not partnerships or sole proprietors', function () {
    $corp = makeCompany($this->user, OrganizationType::Corporation);
    $keys = array_keys(ReportCatalog::flatten($corp, $this->user));

    expect($keys)->toContain('reports.gifi')
        ->and($keys)->not->toContain('reports.t5013')
        ->and($keys)->not->toContain('reports.t2125');
});

it('shows the T5013 to partnerships and hides GIFI', function () {
    $p = makeCompany($this->user, OrganizationType::Partnership);
    $keys = array_keys(ReportCatalog::flatten($p, $this->user));

    expect($keys)->toContain('reports.t5013')
        ->and($keys)->not->toContain('reports.gifi');
});

it('shows the T2125 to sole proprietors and hides GIFI', function () {
    $sp = makeCompany($this->user, OrganizationType::SoleProprietorship);
    $keys = array_keys(ReportCatalog::flatten($sp, $this->user));

    expect($keys)->toContain('reports.t2125')
        ->and($keys)->not->toContain('reports.gifi');
});

it('shows GIFI to a non-profit corporation but not an unincorporated association', function () {
    $npoCorp = makeCompany($this->user, OrganizationType::NonProfit, LegalStructure::NonProfitCorporation);
    expect(array_keys(ReportCatalog::flatten($npoCorp, $this->user)))->toContain('reports.gifi');

    $club = makeCompany($this->user, OrganizationType::Club);
    $keys = array_keys(ReportCatalog::flatten($club, $this->user));
    expect($keys)->not->toContain('reports.gifi')
        ->and($keys)->not->toContain('reports.t5013')
        ->and($keys)->not->toContain('reports.t2125');
});

it('renders the Tax & filing page listing the applicable return', function () {
    $p = makeCompany($this->user, OrganizationType::Partnership);

    $this->actingAs($this->user);

    Livewire::test('pages::settings.tax-and-filing', ['company' => $p])
        ->assertOk()
        ->assertSee('T5013')
        ->assertDontSee('T2125');
});

it('404s the Tax & filing page for a non-Canadian company', function () {
    $us = makeCompany($this->user, OrganizationType::Corporation, extra: ['address_country' => 'US']);

    $this->actingAs($this->user)
        ->get(route('settings.tax-and-filing', ['company' => $us->slug]))
        ->assertNotFound();
});

it('shows the per-account GIFI field for sole proprietors and hides it for charities', function () {
    $sp = makeCompany($this->user, OrganizationType::SoleProprietorship);
    Livewire::test('pages::accounts.index', ['company' => $sp])
        ->assertSeeHtml('account-gifi-select');

    $charity = makeCompany($this->user, OrganizationType::Charity, LegalStructure::RegisteredCharity, ['charity_registration_number' => '123456789RR0001']);
    Livewire::test('pages::accounts.index', ['company' => $charity])
        ->assertDontSeeHtml('account-gifi-select');
});
