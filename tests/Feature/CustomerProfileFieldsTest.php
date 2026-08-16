<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PaymentMethod;
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

it('saves the expanded customer profile fields', function () {
    $method = PaymentMethod::create(['name' => 'Wire transfer', 'is_active' => true]);

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'Pacific Crematorium Limited')
        ->set('f_company_name', 'Pacific Crematorium Ltd.')
        ->set('f_first_name', 'Dana')
        ->set('f_last_name', 'Lee')
        ->set('f_job_title', 'Director')
        ->set('f_email', 'dana@example.com')
        ->set('f_phone', '250-555-0100')
        ->set('f_mobile', '250-555-0199')
        ->set('f_billing_line1', '1 Cemetery Rd')
        ->set('f_billing_city', 'Victoria')
        ->set('f_billing_region', 'BC')
        ->set('f_billing_postal_code', 'V8V 1A1')
        ->set('f_preferred_payment_method_id', $method->id)
        ->set('f_credit_limit', '2500.00')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Contact::query()->where('display_name', 'Pacific Crematorium Limited')->firstOrFail();

    expect($customer->first_name)->toBe('Dana')
        ->and($customer->last_name)->toBe('Lee')
        ->and($customer->job_title)->toBe('Director')
        ->and($customer->mobile)->toBe('250-555-0199')
        ->and($customer->billing_line1)->toBe('1 Cemetery Rd')
        ->and($customer->preferred_payment_method_id)->toBe($method->id)
        ->and($customer->credit_limit_cents)->toBe(250000)
        ->and($customer->is_customer)->toBeTrue();
});

it('copies the billing address into the shipping address', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->set('f_billing_line1', '1 Cemetery Rd')
        ->set('f_billing_city', 'Victoria')
        ->set('f_billing_region', 'BC')
        ->set('f_billing_postal_code', 'V8V 1A1')
        ->set('f_billing_country', 'CA')
        ->call('copyBillingToShipping')
        ->assertSet('f_shipping_line1', '1 Cemetery Rd')
        ->assertSet('f_shipping_city', 'Victoria')
        ->assertSet('f_shipping_region', 'BC')
        ->assertSet('f_shipping_postal_code', 'V8V 1A1')
        ->assertSet('f_shipping_country', 'CA');
});

it('rejects a full country name with a validation error, not a database error', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'World Wide Co')
        ->set('f_billing_country', 'Canada')
        ->call('save')
        ->assertHasErrors(['f_billing_country' => 'max']);

    expect(Contact::query()->count())->toBe(0);
});

it('uppercases and stores two-letter country codes on both addresses', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'World Wide Co')
        ->set('f_billing_country', 'ca')
        ->set('f_shipping_country', 'us')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Contact::query()->where('display_name', 'World Wide Co')->firstOrFail();

    expect($customer->billing_country)->toBe('CA')
        ->and($customer->shipping_country)->toBe('US');
});

it('leaves the credit limit null when left blank', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'No Limit Co')
        ->call('save')
        ->assertHasNoErrors();

    expect(Contact::query()->where('display_name', 'No Limit Co')->value('credit_limit_cents'))->toBeNull();
});
