<?php

use App\Enums\AccountSubtype;
use App\Enums\OrganizationType;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use Livewire\Livewire;

/**
 * The GIFI statement is a report, but reassigning an account's GIFI line edits
 * the chart of accounts — so it needs Accounting, as the account editor does.
 * A Reports-only member reads the statement and cannot reassign (R07 lead).
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1, 'organization_type' => OrganizationType::Corporation->value]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->originalCode = $this->bank->gifi_code;
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('refuses a Reports-only member', function () {
    $member = customSectionsMember($this->company, [Section::Reports]);

    Livewire::actingAs($member)
        ->test('pages::reports.gifi', ['company' => $this->company])
        ->assertOk()
        ->call('reassign', $this->bank->id, '1180')
        ->assertForbidden();

    expect($this->bank->fresh()->gifi_code)->toBe($this->originalCode);
});

it('lets a member with Accounting reassign from the statement', function () {
    $member = customSectionsMember($this->company, [Section::Reports, Section::Accounting]);

    Livewire::actingAs($member)
        ->test('pages::reports.gifi', ['company' => $this->company])
        ->call('reassign', $this->bank->id, '1180');

    expect($this->bank->fresh()->gifi_code)->toBe('1180');
});
