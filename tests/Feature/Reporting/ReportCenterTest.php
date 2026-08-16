<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\ReportFavorite;
use App\Models\User;
use App\Support\Reporting\ReportCatalog;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA']);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/**
 * @return list<string>
 */
function catalogKeys(Company $company, User $user): array
{
    return array_keys(ReportCatalog::flatten($company, $user));
}

it('hides the 1099 summary for a Canadian company and shows it for a US one', function () {
    expect(catalogKeys($this->company, $this->user))->not->toContain('reports.form-1099');

    $us = Company::factory()->create(['address_country' => 'US']);
    $owner = User::factory()->create();
    $us->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    expect(catalogKeys($us, $owner))->toContain('reports.form-1099');
});

it('shows audit logs only to an owner', function () {
    expect(catalogKeys($this->company, $this->user))->toContain('reports.audit-log');

    $member = User::factory()->create();
    $this->company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    expect(catalogKeys($this->company, $member))->not->toContain('reports.audit-log');
});

it('renders the hub with report cards but not gated ones', function () {
    Livewire::actingAs($this->user)
        ->test('pages::reports.index', ['company' => $this->company])
        ->assertSee('Balance Sheet')
        ->assertSee('General Ledger')
        ->assertSeeHtml('data-test="report-card-reports.balance-sheet"')
        ->assertDontSeeHtml('data-test="report-card-reports.form-1099"');
});

it('toggles a favorite on and off', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.index', ['company' => $this->company]);

    $component->call('toggleFavorite', 'reports.balance-sheet');

    $this->assertDatabaseHas('report_favorites', [
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => 'reports.balance-sheet',
    ]);

    expect(collect($component->instance()->favorites())->pluck('key'))
        ->toContain('reports.balance-sheet');

    $component->call('toggleFavorite', 'reports.balance-sheet');

    $this->assertDatabaseMissing('report_favorites', [
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => 'reports.balance-sheet',
    ]);
});

it('ignores favorite toggles for keys outside the catalog', function () {
    Livewire::actingAs($this->user)
        ->test('pages::reports.index', ['company' => $this->company])
        ->call('toggleFavorite', 'reports.not-a-real-report');

    $this->assertDatabaseMissing('report_favorites', [
        'report_key' => 'reports.not-a-real-report',
    ]);
});

it('filters the catalog by search term', function () {
    $categories = Livewire::actingAs($this->user)
        ->test('pages::reports.index', ['company' => $this->company])
        ->set('search', 'balance sheet')
        ->instance()
        ->categories();

    $labels = collect($categories)
        ->flatMap(fn (array $category) => collect($category['reports'])->pluck('label'))
        ->all();

    expect($labels)->toContain('Balance Sheet')
        ->and($labels)->not->toContain('General Ledger');
});

it('renders favorited reports in the sidebar nav and nothing when none', function () {
    Livewire::actingAs($this->user)
        ->test('report-favorites-nav')
        ->assertDontSee('Balance Sheet');

    ReportFavorite::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => 'reports.balance-sheet',
    ]);

    Livewire::actingAs($this->user)
        ->test('report-favorites-nav')
        ->assertSee('Balance Sheet');
});
