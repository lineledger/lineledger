<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

test('company member role can be updated by owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->call('updateMember', $member->id, CompanyRole::Admin->value)
        ->assertHasNoErrors();

    expect($company->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(CompanyRole::Admin->value);
});

test('company member role cannot be updated by non owner', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($admin, ['role' => CompanyRole::Admin->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($admin);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->call('updateMember', $member->id, CompanyRole::Admin->value)
        ->assertForbidden();
});

test('company member can be removed by owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company])
        ->set('memberId', $member->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($member->fresh()->belongsToCompany($company))->toBeFalse();
});

test('company member cannot be removed by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($admin, ['role' => CompanyRole::Admin->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($admin);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company])
        ->set('memberId', $member->id)
        ->call('removeMember')
        ->assertForbidden();
});

test('removed members current company is set to personal company', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalCompany = $member->personalCompany();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $member->forceFill(['current_company_id' => $company->id])->save();

    $this->actingAs($owner);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company])
        ->set('memberId', $member->id)
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($member->fresh()->current_company_id)->toEqual($personalCompany->id);
});
