<?php

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\Section;
use App\Models\Company;
use App\Models\User;
use App\Services\Access\PermissionResolver;
use Illuminate\Support\Facades\DB;

/**
 * Permissions are resolved once per request and dropped the moment a
 * membership changes, for any company — not only the one bound.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
});

it('looks the membership and its roles up once per request', function () {
    $this->company->memberships()->create(['user_id' => $this->user->id, 'role' => CompanyRole::Accountant]);
    app(PermissionResolver::class)->flush();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->user->permissions($this->company);
    $first = count(DB::getQueryLog());

    foreach (range(1, 5) as $ignored) {
        $this->user->canAccessSection($this->company, Section::Customers);
        $this->user->permissions($this->company)->allows(Activity::Invoices, PermissionAction::Delete);
        $this->user->companyMembership($this->company);
    }

    expect($first)->toBeLessThanOrEqual(3)
        ->and(DB::getQueryLog())->toHaveCount($first);
});

it('answers from the membership as it now stands after a change', function () {
    $membership = $this->company->memberships()->create([
        'user_id' => $this->user->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Reports->value],
    ]);

    expect($this->user->canAccessSection($this->company, Section::Reports))->toBeTrue();

    $membership->update(['sections' => [Section::Customers->value]]);
    expect($this->user->canAccessSection($this->company, Section::Reports))->toBeFalse();

    $membership->delete();
    expect($this->user->canAccessSection($this->company, Section::Customers))->toBeFalse()
        ->and($this->user->companyMembership($this->company))->toBeNull();
});

it('resolves another company while one is bound', function () {
    $other = Company::factory()->create();
    $other->memberships()->create(['user_id' => $this->user->id, 'role' => CompanyRole::Accountant]);
    app()->instance('current_company', $this->company);

    expect($this->user->permissions($other)->allows(Activity::Invoices, PermissionAction::View))->toBeTrue()
        ->and($this->user->permissions($this->company)->allows(Activity::Invoices, PermissionAction::View))->toBeFalse();

    app()->forgetInstance('current_company');
});

it('gives the owner everything and a non-member nothing', function () {
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    expect($this->user->permissions($this->company)->isOwner())->toBeTrue()
        ->and(User::factory()->create()->permissions($this->company)->canAccessArea(Section::Customers))->toBeFalse();
});
