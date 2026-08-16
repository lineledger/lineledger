<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

/**
 * The audit-log report (accounting audit + security log) is Owner-only (L1/L2).
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
});

it('lets the company owner view the audit log', function () {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $this->company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('reports.audit-log', ['company' => $this->company->slug]))
        ->assertOk();
});

it('forbids a non-owner member from viewing the audit log', function (CompanyRole $role) {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $this->company->members()->attach($member, ['role' => $role->value]);

    $this->actingAs($member)
        ->get(route('reports.audit-log', ['company' => $this->company->slug]))
        ->assertForbidden();
})->with([
    'admin' => [CompanyRole::Admin],
    'accountant' => [CompanyRole::Accountant],
]);
