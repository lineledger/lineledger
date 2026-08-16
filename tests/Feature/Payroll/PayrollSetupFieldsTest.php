<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
    $this->employee = Contact::create(['display_name' => 'Dana Detail', 'is_employee' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('saves an employee job title, employee ID and address', function () {
    Livewire::test('pages::employees.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'Jordan Hire')
        ->set('f_job_title', 'Welder')
        ->set('f_employee_id', 'EMP-014')
        ->set('f_billing_line1', '123 Main St')
        ->set('f_billing_city', 'Calgary')
        ->set('f_billing_region', 'AB')
        ->set('f_billing_postal_code', 'T2P 1A1')
        ->call('save')
        ->assertHasNoErrors();

    $contact = Contact::query()->where('display_name', 'Jordan Hire')->firstOrFail();
    expect($contact->job_title)->toBe('Welder')
        ->and($contact->employee_id)->toBe('EMP-014')
        ->and($contact->billing_line1)->toBe('123 Main St')
        ->and($contact->billing_city)->toBe('Calgary')
        ->and($contact->is_employee)->toBeTrue();
});

it('prefills the TD1 basic amounts when setting up a new employee', function () {
    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $this->employee])
        ->assertSet('td1_federal_claim', fn ($v) => $v !== '' && (float) $v > 0);
});

it('fills TD1 amounts via the prefill button', function () {
    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $this->employee])
        ->set('td1_federal_claim', '0')
        ->set('td1_provincial_claim', '0')
        ->call('prefillTd1')
        ->assertSet('td1_federal_claim', fn ($v) => (float) $v > 0)
        ->assertSet('td1_provincial_claim', fn ($v) => (float) $v > 0)
        ->assertHasNoErrors();
});
