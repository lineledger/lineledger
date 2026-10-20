<?php

use App\Enums\CompanyRole;
use App\Models\Company;
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

it('shows cleared rows on a first visit', function () {
    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->assertSet('showCleared', true);
});

it('reopens the register with the operator’s last Show cleared choice', function () {
    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->set('showCleared', false);

    session()->flush();

    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->assertSet('showCleared', false)
        ->set('showCleared', true);

    Livewire::test('pages::banking.register', ['company' => $this->company])
        ->assertSet('showCleared', true);
});
