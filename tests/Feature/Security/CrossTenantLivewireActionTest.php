<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentTerm;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Defense-in-depth coverage for cross-tenant WRITES through Livewire wire:
 * action methods. A component mounted for company A must never mutate a company
 * B record, even when the client supplies B's id as a method argument or via a
 * client-settable property (e.g. $editingId). The bound CompanyScope makes the
 * findOrFail miss (404); the explicit abort_unless/abort_if guards in the action
 * methods are the belt-and-suspenders if that scope is ever inactive. Either
 * way the outcome asserted here is the same: B's row is left untouched.
 */
beforeEach(function () {
    // Victim company B with a record on each surface under test.
    $this->companyB = Company::factory()->create();

    app()->instance('current_company', $this->companyB);

    $this->accountB = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->orderBy('code')
        ->first();

    $this->termB = PaymentTerm::create(['name' => 'Net 30 (B)', 'days' => 30, 'is_active' => true]);

    app()->forgetInstance('current_company');

    // Attacker, member of company A only.
    $this->attacker = User::factory()->create();
    $this->companyA = Company::factory()->create();
    $this->companyA->members()->attach($this->attacker, ['role' => CompanyRole::Owner->value]);

    actingAs($this->attacker);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('does not let a company A member toggle a company B account via a wire action', function () {
    $wasActive = $this->accountB->is_active;

    try {
        Livewire::test('pages::accounts.index', ['company' => $this->companyA])
            ->call('toggleActive', $this->accountB->id);
    } catch (Throwable) {
        // findOrFail miss (404) or abort(403) — both mean the write was blocked.
    }

    expect($this->accountB->fresh()->is_active)->toBe($wasActive);
});

it('does not let a company A member overwrite a company B payment term via a wire action', function () {
    try {
        Livewire::test('pages::settings.lists.payment-terms', ['company' => $this->companyA])
            ->set('editingId', $this->termB->id)
            ->set('f_name', 'HIJACKED')
            ->set('f_days', 1)
            ->call('save');
    } catch (Throwable) {
        // Blocked by scope miss or abort guard.
    }

    expect($this->termB->fresh()->name)->toBe('Net 30 (B)')
        ->and($this->termB->fresh()->days)->toBe(30);
});
