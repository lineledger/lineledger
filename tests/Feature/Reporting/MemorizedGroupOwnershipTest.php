<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\MemorizedReportGroup;
use App\Models\User;
use Livewire\Livewire;

/**
 * A memorized-report group is what a report email schedule sends, so a member
 * may only file reports into their own groups — not a colleague's, which would
 * mail the report out under the colleague's name (R07, audit D9Reporting-04).
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    $this->colleague = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->company->members()->attach($this->colleague, ['role' => CompanyRole::Accountant->value]);

    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('refuses to file a report into another member\'s group', function () {
    $theirs = MemorizedReportGroup::create(['user_id' => $this->colleague->id, 'name' => 'Board pack']);

    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('memorizeName', 'Sneaky P&L')
        ->set('memorizeGroupId', $theirs->id)
        ->call('memorizeReport')
        ->assertHasErrors('memorizeGroupId');

    expect(MemorizedReport::query()->where('memorized_report_group_id', $theirs->id)->exists())->toBeFalse();
});

it('refuses a group id from another company', function () {
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $foreign = MemorizedReportGroup::create(['user_id' => $this->user->id, 'name' => 'Elsewhere']);
    app()->instance('current_company', $this->company);

    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('memorizeName', 'P&L')
        ->set('memorizeGroupId', $foreign->id)
        ->call('memorizeReport')
        ->assertHasErrors('memorizeGroupId');
});

it('files a report into the member\'s own group', function () {
    $mine = MemorizedReportGroup::create(['user_id' => $this->user->id, 'name' => 'Month end']);

    Livewire::actingAs($this->user)
        ->test('pages::reports.income-statement', ['company' => $this->company])
        ->set('memorizeName', 'Monthly P&L')
        ->set('memorizeGroupId', $mine->id)
        ->call('memorizeReport')
        ->assertHasNoErrors();

    expect(MemorizedReport::query()->where('memorized_report_group_id', $mine->id)->value('name'))->toBe('Monthly P&L');
});
