<?php

use App\Enums\CompanyRole;
use App\Enums\JurisdictionCapability;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Jurisdiction capability matrix — anti-drift lock
|--------------------------------------------------------------------------
|
| This test pins the Canada/US capability classification so it cannot silently
| drift. The two arrays below are the canonical lists the suite asserts against;
| the anti-drift case (C) fails if a new JurisdictionCapability is added without
| being classified here.
|
*/

/** Capabilities that must be false for any US company (Canada-locked). */
const CA_ONLY = [
    JurisdictionCapability::Payroll,
    JurisdictionCapability::T4Slips,
    JurisdictionCapability::T4ASlips,
    JurisdictionCapability::Pd7aRemittance,
    JurisdictionCapability::RecordOfEmployment,
    JurisdictionCapability::WorkersComp,
    JurisdictionCapability::GifiStatement,
    JurisdictionCapability::T5013,
    JurisdictionCapability::T2125,
    JurisdictionCapability::T3010,
    JurisdictionCapability::T1044,
    JurisdictionCapability::GifiCodeMapping,
    JurisdictionCapability::VendorT4ATracking,
    JurisdictionCapability::CraTaxFiling,
    JurisdictionCapability::CharityDonationReceipts,
    JurisdictionCapability::CanadianCapitalCostAllowance,
];

/** Capabilities that must be false for any CA company (US-locked). */
const US_ONLY = [
    JurisdictionCapability::Form1099,
    JurisdictionCapability::Vendor1099Tracking,
];

/*
|--------------------------------------------------------------------------
| A) Resolver matrix — data-driven
|--------------------------------------------------------------------------
*/

it('blocks every CA-only capability for a US company', function (JurisdictionCapability $capability) {
    // A maximally-capable US company: incorporated AND payroll-enabled. If the
    // capability still resolves false, COUNTRY is proven to be the blocker —
    // not a missing entity type or feature flag.
    $company = Company::factory()->make([
        'address_country' => 'US',
        'organization_type' => OrganizationType::Corporation->value,
        'features_payroll' => true,
    ]);

    expect($company->supports($capability))->toBeFalse();
})->with(CA_ONLY);

it('blocks every US-only capability for a CA company', function (JurisdictionCapability $capability) {
    $company = Company::factory()->make([
        'address_country' => 'CA',
        'organization_type' => OrganizationType::Corporation->value,
        'features_payroll' => true,
    ]);

    expect($company->supports($capability))->toBeFalse();
})->with(US_ONLY);

/*
|--------------------------------------------------------------------------
| B) Composition guard — multi-factor caps are NOT flattened to a country check
|--------------------------------------------------------------------------
*/

it('keeps CRA returns entity-specific, not just country-gated', function () {
    // A Canadian sole proprietor files the T2125, NOT a GIFI/T2 statement —
    // even though both companies are Canadian. Proves country alone does not
    // unlock GifiStatement.
    $soleProp = Company::factory()->make([
        'address_country' => 'CA',
        'organization_type' => OrganizationType::SoleProprietorship->value,
    ]);

    expect($soleProp->supports(JurisdictionCapability::T2125))->toBeTrue()
        ->and($soleProp->supports(JurisdictionCapability::GifiStatement))->toBeFalse();

    // A Canadian corporation files the T2/GIFI statement.
    $corp = Company::factory()->make([
        'address_country' => 'CA',
        'organization_type' => OrganizationType::Corporation->value,
    ]);

    expect($corp->supports(JurisdictionCapability::GifiStatement))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| C) Anti-drift — every capability must be classified
|--------------------------------------------------------------------------
*/

it('classifies every capability as CA-only or US-only', function () {
    // If someone adds a new JurisdictionCapability without listing it in
    // CA_ONLY or US_ONLY above, this diff is non-empty and the test fails —
    // forcing a deliberate classification decision. Compare on the backing
    // value (enum objects are not string-castable for Collection::diff).
    $classified = collect([...CA_ONLY, ...US_ONLY])->map->value;

    $unclassified = collect(JurisdictionCapability::cases())
        ->map->value
        ->diff($classified);

    expect($unclassified)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| D) Route / guard integration
|--------------------------------------------------------------------------
*/

it('forbids the 1099 report for a Canadian company', function () {
    $ca = Company::factory()->create(['address_country' => 'CA']);
    $user = User::factory()->create();
    $ca->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('reports.form-1099', ['company' => $ca->slug]))
        ->assertForbidden();
});

it('404s the payroll index for a US company even with payroll enabled', function () {
    // features_payroll is true to prove COUNTRY blocks payroll, not the flag.
    $us = Company::factory()->create([
        'address_country' => 'US',
        'features_payroll' => true,
    ]);
    $user = User::factory()->create();
    $us->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('payroll.index', ['company' => $us->slug]))
        ->assertNotFound();
});

it('404s the Tax & filing settings page for a US company', function () {
    $us = Company::factory()->create(['address_country' => 'US']);
    $user = User::factory()->create();
    $us->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('settings.tax-and-filing', ['company' => $us->slug]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| E) Hardening regressions
|--------------------------------------------------------------------------
*/

it('ignores gifi_code on save for a US company', function () {
    $us = Company::factory()->create(['address_country' => 'US']);
    $user = User::factory()->create();
    $us->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $us);

    // Seeded US accounts may carry a backfilled gifi_code on the column; the
    // hardening guarantee is that a US *save* never writes the form's value.
    // Prove it by submitting a DIFFERENT valid GIFI code and asserting the
    // stored value did not change to it (GIFI mapping is Canada-only).
    $account = Account::query()
        ->where('company_id', $us->id)
        ->orderBy('code')
        ->firstOrFail();

    $original = $account->gifi_code;
    $submitted = $original === '1484' ? '1001' : '1484'; // a valid GIFI code, distinct from the original

    Livewire::actingAs($user)
        ->test('pages::accounts.index', ['company' => $us])
        ->call('openEdit', $account->id)
        ->set('form_gifi_code', $submitted)
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->gifi_code)
        ->toBe($original)
        ->not->toBe($submitted);
});

it('404s the payroll cheque PDF for a US company', function () {
    // note: A PayrollCheque has four NOT-NULL FK columns (pay_run_id,
    // pay_run_line_id, bank_account_id, payee_contact_id) with no factory, so a
    // valid fixture would require building an entire pay-run chain — impractical
    // and brittle for a one-line jurisdiction guard. The guard itself is
    // PrintPayrollChequeController::__invoke():
    //   abort_unless($company->supports(JurisdictionCapability::Payroll), 404);
    // and the Payroll capability is already covered exhaustively by the resolver
    // matrix above (it is in CA_ONLY, so it is false for any US company). Skipped
    // rather than written as a flaky test, per the test plan.
})->skip('PayrollCheque needs an unfactored pay-run fixture; the Payroll guard is covered by the resolver matrix.');
