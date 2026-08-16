<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Models\Company;
use App\Models\RecurringDocument;
use App\Models\RecurringJournalEntry;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use Carbon\CarbonImmutable;

/**
 * A recurring template stopped abnormally: inactive WITH a paused_reason
 * (a fully-retired schedule clears the reason — see hasEnded() on both
 * models). Recurring journal entries share the same pause semantics, so
 * their count is included. Only the count is surfaced — never the
 * paused_reason text, which can quote document details.
 */
final class RecurringTemplatePausedDetector implements InsightDetector
{
    use FormatsInsightFacts;

    public function key(): string
    {
        return 'recurring-template-paused';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Hygiene;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        $paused = RecurringDocument::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('is_active', false)
            ->whereNotNull('paused_reason')
            ->count();

        $paused += RecurringJournalEntry::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->where('is_active', false)
            ->whereNotNull('paused_reason')
            ->count();

        if ($paused < 1) {
            return [];
        }

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: 80,
            facts: [
                'paused_count' => $paused,
            ],
            headline: trans_choice(
                'A recurring template is paused|:count recurring templates are paused',
                $paused,
                ['count' => $paused],
            ),
            body: trans_choice(
                'Something stopped it from generating on schedule. Review it so invoices and bills keep flowing automatically.|Something stopped them from generating on schedule. Review them so invoices and bills keep flowing automatically.',
                $paused,
            ),
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'recurring.index', 'label' => __('Review recurring')];
    }
}
