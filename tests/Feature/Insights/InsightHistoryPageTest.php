<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\DailyInsight;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('lists insights newest first', function () {
    foreach ([
        ['2026-06-01', 'Oldest headline'],
        ['2026-06-05', 'Middle headline'],
        ['2026-06-09', 'Newest headline'],
    ] as [$date, $headline]) {
        DailyInsight::factory()->create([
            'company_id' => $this->company->id,
            'insight_date' => $date,
            'headline' => $headline,
        ]);
    }

    $this->get("/{$this->company->slug}/insights")
        ->assertOk()
        ->assertSeeInOrder(['Newest headline', 'Middle headline', 'Oldest headline']);
});

it('paginates at 30 rows', function () {
    foreach (range(0, 30) as $i) {
        DailyInsight::factory()->create([
            'company_id' => $this->company->id,
            'insight_date' => CarbonImmutable::parse('2026-01-01')->addDays($i)->toDateString(),
            'headline' => 'Headline number '.$i,
        ]);
    }

    $this->get("/{$this->company->slug}/insights")
        ->assertOk()
        ->assertSee('Headline number 30')   // newest
        ->assertDontSee('Headline number 0'); // 31st row → page 2
});

it('is reachable by a member with no section grants (deliberately ungated)', function () {
    $walled = User::factory()->create();
    $this->company->members()->attach($walled, ['role' => CompanyRole::Custom->value]);

    $this->actingAs($walled)
        ->get("/{$this->company->slug}/insights")
        ->assertOk();
});

it('never shows another company\'s insights', function () {
    app()->forgetInstance('current_company');
    $other = Company::factory()->create();
    DailyInsight::factory()->create([
        'company_id' => $other->id,
        'insight_date' => '2026-06-09',
        'headline' => 'Foreign headline',
    ]);
    app()->instance('current_company', $this->company);

    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => '2026-06-09',
        'headline' => 'Local headline',
    ]);

    $this->get("/{$this->company->slug}/insights")
        ->assertOk()
        ->assertSee('Local headline')
        ->assertDontSee('Foreign headline');
});

it('shows an empty state when there are no insights yet', function () {
    $this->get("/{$this->company->slug}/insights")
        ->assertOk()
        ->assertSee('Insights will appear here once your books have a day of activity.');
});
