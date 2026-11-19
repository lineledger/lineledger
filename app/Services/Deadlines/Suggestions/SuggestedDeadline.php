<?php

namespace App\Services\Deadlines\Suggestions;

use App\Enums\DeadlineCategory;
use App\Enums\DeadlineKind;
use App\Services\Deadlines\DeadlineRule;

/**
 * One deadline the suggestions page offers, ready to hand to SaveDeadline.
 */
final readonly class SuggestedDeadline
{
    /**
     * @param  array<string, mixed>  $schedule  SaveDeadline schedule keys (frequency, period_anchor, due rule…)
     */
    public function __construct(
        /** Stable id, stored as the deadline's template_key. */
        public string $key,
        public string $title,
        public DeadlineCategory $category,
        public DeadlineKind $kind,
        public string $agency,
        /** Why it's suggested for this organization. */
        public string $why,
        /** The official page the rule comes from (null for a template with no rule of its own). */
        public ?string $sourceUrl,
        public array $schedule,
        public bool $defaultOn = true,
        public ?string $note = null,
        public ?string $portalUrl = null,
    ) {}

    public function rule(): DeadlineRule
    {
        return DeadlineRule::fromArray($this->schedule);
    }

    /**
     * The SaveDeadline payload for this suggestion.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'title' => $this->title,
            'category' => $this->category->value,
            'kind' => $this->kind->value,
            'agency_name' => $this->agency,
            'portal_url' => $this->portalUrl,
            'notify_days_before' => [7, 1],
            'template_key' => $this->key,
            'interval' => 1,
            'annual_dates' => [],
            'due_months_after' => 0,
            'due_day_rule' => 'same_day',
            'due_days_after' => 0,
            'weekend_adjustment' => 'keep',
            'end_type' => 'never',
            ...$this->schedule,
        ];
    }
}
