<?php

use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

/**
 * Closing the books is Owner/Admin-only and guarded by re-entering the actor's
 * account password; every change is written to the tamper-evident audit log.
 */
beforeEach(function () {
    // The default User factory hashes the literal password "password".
    $this->owner = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->owner);
    app()->forgetInstance('current_company');
});

it('locks the books with the correct password and records an audit log', function () {
    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('lockDate', '2026-03-31')
        ->set('lockPassword', 'password')
        ->call('confirmLockDate')
        ->assertHasNoErrors();

    expect($this->company->fresh()->lock_date->toDateString())->toBe('2026-03-31');

    $log = AccountingAuditLog::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('action', AuditAction::PeriodLockChanged->value)
        ->latest('sequence')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->payload['to'])->toBe('2026-03-31');
});

it('rejects an incorrect password and leaves the lock unchanged', function () {
    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('lockDate', '2026-03-31')
        ->set('lockPassword', 'wrong-password')
        ->call('confirmLockDate')
        ->assertHasErrors('lockPassword');

    expect($this->company->fresh()->lock_date)->toBeNull();
});

it('clears the lock when the date is left blank', function () {
    $this->company->update(['lock_date' => '2026-03-31']);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('lockDate', '')
        ->set('lockPassword', 'password')
        ->call('confirmLockDate')
        ->assertHasNoErrors();

    expect($this->company->fresh()->lock_date)->toBeNull();
});

it('forbids a non-admin member from locking the books', function () {
    $accountant = User::factory()->create();
    $this->company->members()->attach($accountant, ['role' => CompanyRole::Accountant->value]);

    Livewire::actingAs($accountant)
        ->test('pages::companies.edit', ['company' => $this->company])
        ->set('lockDate', '2026-03-31')
        ->set('lockPassword', 'password')
        ->call('confirmLockDate')
        ->assertForbidden();

    expect($this->company->fresh()->lock_date)->toBeNull();
});
