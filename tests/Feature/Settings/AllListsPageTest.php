<?php

use App\Enums\CompanyRole;
use App\Enums\TaxAppliesTo;
use App\Models\Company;
use App\Models\Item;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the all lists hub page with the default row labels', function () {
    $this->get(route('lists.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('All lists')
        ->assertSee('Chart of accounts')
        ->assertSee('Recurring transactions')
        ->assertSee('Recurring journal entries')
        ->assertSee('Items')
        ->assertSee('Item categories')
        ->assertSee('Tax codes')
        ->assertSee('Payment terms')
        ->assertSee('Payment methods')
        ->assertSee('Asset categories')
        ->assertSee('Form styles')
        ->assertSee('Currencies')
        ->assertSee('Attachments');
});

it('renders record counts for each list', function () {
    Item::factory()->count(3)->create(['company_id' => $this->company->id]);

    $taxCodeBaseline = TaxCode::query()->count();
    foreach (['T10' => 1000, 'T20' => 2000] as $code => $basisPoints) {
        TaxCode::create([
            'code' => $code,
            'name' => "Test {$code}",
            'rate_basis_points' => $basisPoints,
            'applies_to' => TaxAppliesTo::Both->value,
            'is_active' => true,
        ]);
    }
    $expectedTaxCodes = $taxCodeBaseline + 2;

    $component = Livewire::test('pages::settings.lists.index', ['company' => $this->company]);

    $rows = collect($component->instance()->rows);

    expect($rows->firstWhere('key', 'items')['count'])->toBe(3)
        ->and($rows->firstWhere('key', 'tax-codes')['count'])->toBe($expectedTaxCodes);

    $html = $component->html();

    expect($html)->toMatch('/data-test="all-lists-count-items"[^>]*>\s*3\s*</')
        ->toMatch('/data-test="all-lists-count-tax-codes"[^>]*>\s*'.$expectedTaxCodes.'\s*</');
});

it('hides flag-gated rows when the company feature is off', function () {
    $this->get(route('lists.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertDontSee('data-test="all-lists-count-classifications"', false)
        ->assertDontSee('data-test="all-lists-count-locations"', false)
        ->assertDontSee('data-test="all-lists-count-membership-levels"', false);
});

it('shows flag-gated rows when the company feature is on', function () {
    $this->company->update([
        'features_classes' => true,
        'features_locations' => true,
        'features_membership' => true,
    ]);

    $this->get(route('lists.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Classes')
        ->assertSee('Locations')
        ->assertSee('Membership levels')
        ->assertSee('data-test="all-lists-count-classifications"', false)
        ->assertSee('data-test="all-lists-count-locations"', false)
        ->assertSee('data-test="all-lists-count-membership-levels"', false);
});

it('links rows to their list pages', function () {
    $this->get(route('lists.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee(route('lists.payment-methods', ['company' => $this->company]), false)
        ->assertSee(route('lists.tax-codes', ['company' => $this->company]), false)
        ->assertSee(route('accounts.index', ['company' => $this->company]), false);
});

it('does not shadow the specific list pages', function () {
    expect(route('lists.index', ['company' => $this->company]))
        ->toEndWith('/settings/lists')
        ->not->toBe(route('lists.payment-methods', ['company' => $this->company]));

    $this->get(route('lists.payment-methods', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Payment methods');
});
