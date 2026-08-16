<?php

namespace Database\Factories;

use App\Enums\InsightSource;
use App\Models\DailyInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyInsight>
 */
class DailyInsightFactory extends Factory
{
    protected $model = DailyInsight::class;

    public function definition(): array
    {
        return [
            'insight_date' => '2026-06-01',
            'type' => 'cash-trend-30d',
            'source' => InsightSource::Template,
            'headline' => 'Cash is up 12% over the last 30 days',
            'body' => 'Your bank balances total $48,200, $5,100 more than a month ago.',
            'facts' => ['current_cents' => 4820000, 'current_display' => '$48,200'],
        ];
    }

    public function ai(): static
    {
        return $this->state(fn () => ['source' => InsightSource::Ai]);
    }
}
