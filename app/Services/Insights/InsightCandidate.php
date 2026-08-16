<?php

namespace App\Services\Insights;

use App\Enums\InsightCategory;

/**
 * One fact a detector found interesting enough to surface today.
 *
 * Privacy invariant: $facts holds aggregates only — counts, amounts, dates,
 * percentages. Never customer/vendor/employee names or transaction
 * descriptions/memos (chart-of-accounts category names are the one reviewed
 * exception). Every money fact is an integer `*_cents` value PLUS a
 * pre-formatted `*_display` string in the dashboard's whole-dollar format,
 * so the AI narrator never does arithmetic and the stored payload keeps
 * exact cents. The headline/body here are the detector's own deterministic
 * template rendering — the default experience when AI narration is off.
 */
final readonly class InsightCandidate
{
    /**
     * @param  array<string, int|float|string|bool|null>  $facts
     * @param  bool  $urgent  skips the anti-repeat window (hard deadlines re-emit)
     */
    public function __construct(
        public string $key,
        public InsightCategory $category,
        public int $score,
        public array $facts,
        public string $headline,
        public string $body,
        public bool $urgent = false,
    ) {}
}
