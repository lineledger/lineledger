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
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('shows today\'s insight', function () {
    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $this->company->currentDateTime()->toDateString(),
        'headline' => 'Cash is up 12% over the last 30 days',
    ]);

    Livewire::test('daily-insight')
        ->assertSee('Daily insight')
        ->assertSee('Cash is up 12% over the last 30 days');
});

it('still shows yesterday\'s insight (timezone gap window)', function () {
    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $this->company->currentDateTime()->subDay()->toDateString(),
        'headline' => 'Yesterday headline',
    ]);

    Livewire::test('daily-insight')->assertSee('Yesterday headline');
});

it('hides insights older than yesterday', function () {
    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $this->company->currentDateTime()->subDays(2)->toDateString(),
        'headline' => 'Stale headline',
    ]);

    Livewire::test('daily-insight')
        ->assertDontSee('Stale headline')
        ->assertDontSee('Daily insight');
});

it('renders nothing when no insight exists', function () {
    Livewire::test('daily-insight')->assertDontSee('Daily insight');
});

it('shows the AI badge only for AI-sourced insights', function () {
    DailyInsight::factory()->ai()->create([
        'company_id' => $this->company->id,
        'insight_date' => $this->company->currentDateTime()->toDateString(),
    ]);

    Livewire::test('daily-insight')
        ->assertSeeHtml('data-test="daily-insight-ai-badge"');
});

it('hides the AI badge for template-sourced insights', function () {
    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $this->company->currentDateTime()->toDateString(),
    ]);

    Livewire::test('daily-insight')
        ->assertSee('Daily insight')
        ->assertDontSeeHtml('data-test="daily-insight-ai-badge"');
});

it('resolves the CTA from the detector catalogue', function () {
    // Factory default type is cash-trend-30d → the cash-flow report CTA.
    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $this->company->currentDateTime()->toDateString(),
    ]);

    Livewire::test('daily-insight')
        ->assertSee('View cash flow')
        ->assertSee('Past insights');
});

it('renders on the dashboard', function () {
    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $this->company->currentDateTime()->toDateString(),
        'headline' => 'Dashboard-visible headline',
    ]);

    $this->get(route('dashboard', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Dashboard-visible headline');
});
