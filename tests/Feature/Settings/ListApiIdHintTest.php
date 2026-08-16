<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\PaymentTerm;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

/**
 * Editing a list record shows its surrogate id, so an integrator can read the
 * value their API calls hardcode without going to the database. It is absent on
 * create, where no id exists yet.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

it('shows the tax_code_id when editing a tax code, but not when creating one', function () {
    $code = TaxCode::query()->orderBy('code')->firstOrFail();

    Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->call('openCreate')
        ->assertDontSeeHtml('data-test="api-id-hint"')
        ->call('openEdit', $code->id)
        ->assertSeeHtml('data-test="api-id-hint"')
        ->assertSee('tax_code_id')
        ->assertSeeHtml('data-test="api-id-value">'.$code->id.'</span>');
});

it('shows the agency_id when editing a tax agency', function () {
    $agency = TaxAgency::query()->firstOrFail();

    Livewire::test('pages::settings.lists.tax-codes', ['company' => $this->company])
        ->call('openAgencyCreate')
        ->assertDontSeeHtml('data-test="api-id-hint"')
        ->call('openAgencyEdit', $agency->id)
        ->assertSee('agency_id')
        ->assertSeeHtml('data-test="api-id-value">'.$agency->id.'</span>');
});

it('shows the terms_id when editing a payment term, but not when creating one', function () {
    $term = PaymentTerm::query()->orderBy('id')->firstOrFail();

    Livewire::test('pages::settings.lists.payment-terms', ['company' => $this->company])
        ->call('openCreate')
        ->assertDontSeeHtml('data-test="api-id-hint"')
        ->call('openEdit', $term->id)
        ->assertSee('terms_id')
        ->assertSeeHtml('data-test="api-id-value">'.$term->id.'</span>');
});
