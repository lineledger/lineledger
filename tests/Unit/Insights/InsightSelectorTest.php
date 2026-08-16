<?php

use App\Enums\InsightCategory;
use App\Services\Insights\InsightCandidate;
use App\Services\Insights\InsightSelector;
use Carbon\CarbonImmutable;

// Pure array-in/array-out rules — no application container, no database.
// The weekly jitter is deterministic but only spans 0–6, so ordering
// assertions use margins the jitter (and the −10 variety nudge) cannot flip.

function makeSelectorCandidate(string $key, InsightCategory $category, int $score, bool $urgent = false): InsightCandidate
{
    return new InsightCandidate(
        key: $key,
        category: $category,
        score: $score,
        facts: [],
        headline: 'Headline '.$key,
        body: 'Body '.$key,
        urgent: $urgent,
    );
}

it('orders by score when the margin exceeds the weekly jitter', function () {
    $today = CarbonImmutable::parse('2026-06-10');

    $ranked = (new InsightSelector)->rank(
        [
            makeSelectorCandidate('low', InsightCategory::Fact, 40),
            makeSelectorCandidate('high', InsightCategory::Fact, 90),
        ],
        [],
        null,
        7,
        $today,
    );

    expect(array_map(fn ($c) => $c->key, $ranked))->toBe(['high', 'low']);
});

it('suppresses a key shown within its category window and re-allows it after', function (InsightCategory $category, int $window) {
    $today = CarbonImmutable::parse('2026-06-10');
    $candidate = makeSelectorCandidate('repeat', $category, 80);

    $inside = (new InsightSelector)->rank(
        [$candidate],
        [['type' => 'repeat', 'insight_date' => $today->subDays($window - 1)->toDateString()]],
        null,
        7,
        $today,
    );

    $outside = (new InsightSelector)->rank(
        [$candidate],
        [['type' => 'repeat', 'insight_date' => $today->subDays($window)->toDateString()]],
        null,
        7,
        $today,
    );

    expect($inside)->toBe([]);
    expect($outside)->toHaveCount(1);
})->with([
    'deadline (4 days)' => [InsightCategory::Deadline, 4],
    'hygiene (10 days)' => [InsightCategory::Hygiene, 10],
    'fact (21 days)' => [InsightCategory::Fact, 21],
]);

it('lets an urgent candidate bypass the anti-repeat window', function () {
    $today = CarbonImmutable::parse('2026-06-10');

    $ranked = (new InsightSelector)->rank(
        [makeSelectorCandidate('remit', InsightCategory::Deadline, 95, urgent: true)],
        [['type' => 'remit', 'insight_date' => $today->subDay()->toDateString()]],
        null,
        7,
        $today,
    );

    expect($ranked)->toHaveCount(1);
});

it('demotes candidates sharing yesterday\'s category', function () {
    $today = CarbonImmutable::parse('2026-06-10');

    // Base scores 72 vs 70: after the −10 nudge the fact candidate tops out
    // at 72−10+6 = 68, below the hygiene candidate's floor of 70 — the flip
    // is guaranteed regardless of jitter.
    $ranked = (new InsightSelector)->rank(
        [
            makeSelectorCandidate('fact-again', InsightCategory::Fact, 72),
            makeSelectorCandidate('hygiene-fresh', InsightCategory::Hygiene, 70),
        ],
        [],
        InsightCategory::Fact,
        7,
        $today,
    );

    expect($ranked[0]->key)->toBe('hygiene-fresh');
});

it('ranks deterministically for identical inputs', function () {
    $today = CarbonImmutable::parse('2026-06-10');
    $candidates = [
        makeSelectorCandidate('a', InsightCategory::Fact, 50),
        makeSelectorCandidate('b', InsightCategory::Hygiene, 50),
        makeSelectorCandidate('c', InsightCategory::Deadline, 50),
    ];

    $first = (new InsightSelector)->rank($candidates, [], null, 42, $today);
    $second = (new InsightSelector)->rank($candidates, [], null, 42, $today);

    expect(array_map(fn ($c) => $c->key, $first))
        ->toBe(array_map(fn ($c) => $c->key, $second));
});

it('returns an empty list when every candidate is suppressed', function () {
    $today = CarbonImmutable::parse('2026-06-10');

    $ranked = (new InsightSelector)->rank(
        [makeSelectorCandidate('only', InsightCategory::Fact, 90)],
        [['type' => 'only', 'insight_date' => $today->subDays(2)->toDateString()]],
        null,
        7,
        $today,
    );

    expect($ranked)->toBe([]);
});
