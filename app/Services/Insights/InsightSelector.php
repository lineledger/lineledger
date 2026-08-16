<?php

namespace App\Services\Insights;

use App\Enums\InsightCategory;
use Carbon\CarbonImmutable;

/**
 * Pure ranking of the day's candidates — plain arrays in and out, so every
 * rule is unit-testable without a database:
 *
 *  1. Anti-repeat — a key shown within its category's antiRepeatDays()
 *     window is suppressed, unless the candidate is urgent (hard deadlines
 *     re-emit as the date nears).
 *  2. Variety — candidates sharing yesterday's category lose 10 points.
 *  3. Rotation — a deterministic per-week jitter (0–6) keyed on
 *     company + key, so quiet books still vary without RNG (reproducible
 *     in tests).
 *  4. Order — adjusted score desc, then category priority desc (action
 *     beats trivia), then key asc.
 */
final class InsightSelector
{
    /**
     * @param  list<InsightCandidate>  $candidates
     * @param  list<array{type: string, insight_date: string}>  $recent  stored insights in the look-back window, newest first
     * @param  InsightCategory|null  $yesterdayCategory  category of yesterday's stored insight, if any
     * @return list<InsightCandidate> best-first
     */
    public function rank(
        array $candidates,
        array $recent,
        ?InsightCategory $yesterdayCategory,
        int $companyId,
        CarbonImmutable $today,
    ): array {
        $lastShown = [];
        foreach ($recent as $row) {
            $lastShown[$row['type']] ??= $row['insight_date']; // newest first → first wins
        }

        $eligible = array_values(array_filter(
            $candidates,
            function (InsightCandidate $candidate) use ($lastShown, $today): bool {
                $last = $lastShown[$candidate->key] ?? null;

                if ($last === null || $candidate->urgent) {
                    return true;
                }

                $daysSince = (int) CarbonImmutable::parse($last)->diffInDays($today);

                return $daysSince >= $candidate->category->antiRepeatDays();
            },
        ));

        $scored = [];
        foreach ($eligible as $candidate) {
            $score = $candidate->score;

            if ($yesterdayCategory !== null && $candidate->category === $yesterdayCategory) {
                $score -= 10;
            }

            $score += crc32($companyId.':'.$candidate->key.':'.$today->format('o-W')) % 7;

            $scored[] = [$candidate, $score];
        }

        usort($scored, fn (array $a, array $b): int => [
            $b[1], $b[0]->category->priority(), $a[0]->key,
        ] <=> [
            $a[1], $a[0]->category->priority(), $b[0]->key,
        ]);

        return array_map(fn (array $pair): InsightCandidate => $pair[0], $scored);
    }
}
