<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\DailyInsight;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $this->company->currentDateTime()->toDateString(),
        'headline' => 'Preference-test headline',
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('links to the insights page from the sidebar', function () {
    $this->get(route('dashboard', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSeeHtml('data-test="sidebar-insights"')
        ->assertSee(route('insights.index', ['company' => $this->company->slug]), false);
});

it('shows the card by default and hides it when the user turns it off', function () {
    Livewire::test('daily-insight')->assertSee('Preference-test headline');

    Livewire::test('pages::insights.index', ['company' => $this->company])
        ->assertSet('showOnDashboard', true)
        ->set('showOnDashboard', false);

    expect($this->user->fresh()->show_daily_insights)->toBeFalse();

    Livewire::test('daily-insight')->assertDontSee('Preference-test headline');

    Livewire::test('pages::insights.index', ['company' => $this->company])
        ->assertSet('showOnDashboard', false)
        ->set('showOnDashboard', true);

    expect($this->user->fresh()->show_daily_insights)->toBeTrue();

    Livewire::test('daily-insight')->assertSee('Preference-test headline');
});

it('offers to put today\'s insight back on the dashboard', function () {
    Livewire::test('pages::insights.index', ['company' => $this->company])
        ->assertSeeHtml('data-test="insight-show-again"')
        ->assertSee('insight-dismissed-'.$this->company->id.'-'.$this->company->currentDateTime()->toDateString());
});
