<?php

use App\Enums\InsightCategory;
use App\Enums\InsightSource;
use App\Services\Insights\AI\ClaudeInsightNarrator;
use App\Services\Insights\Contracts\InsightNarrator;
use App\Services\Insights\InsightCandidate;
use App\Services\Insights\NarrationContext;
use App\Services\Insights\TemplateInsightNarrator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function makeNarratorCandidate(string $key = 'cash-trend-30d'): InsightCandidate
{
    return new InsightCandidate(
        key: $key,
        category: InsightCategory::Fact,
        score: 50,
        facts: ['current_cents' => 4820000, 'current_display' => '$48,200', 'pct_change' => 12],
        headline: 'Cash is up 12% over the last 30 days',
        body: 'Your bank balances total $48,200.',
    );
}

function makeNarratorContext(): NarrationContext
{
    return new NarrationContext(
        today: '2026-06-10',
        company: ['organization_type' => null, 'is_non_profit' => false, 'tracks_membership' => false, 'home_currency' => 'CAD'],
        recentInsights: [],
    );
}

function makeClaudeNarrator(): ClaudeInsightNarrator
{
    return new ClaudeInsightNarrator(
        apiKey: 'test-key',
        baseUrl: 'https://api.anthropic.com',
        model: 'claude-sonnet-4-6',
        timeout: 5,
        fallback: new TemplateInsightNarrator,
    );
}

function fakeClaudeToolResponse(array $input): array
{
    return [
        'content' => [[
            'type' => 'tool_use',
            'name' => 'provide_daily_insight',
            'input' => $input,
        ]],
    ];
}

it('template narrator returns the top candidate verbatim', function () {
    $narrated = (new TemplateInsightNarrator)->narrate([makeNarratorCandidate()], makeNarratorContext());

    expect($narrated->key)->toBe('cash-trend-30d');
    expect($narrated->headline)->toBe('Cash is up 12% over the last 30 days');
    expect($narrated->source)->toBe(InsightSource::Template);
});

it('returns AI copy on a valid forced tool response and sends only display values', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeToolResponse([
            'chosen_key' => 'cash-trend-30d',
            'headline' => 'Your cash climbed 12% this month',
            'body' => 'Bank balances now sit at $48,200 — nice tailwind.',
        ])),
    ]);

    $narrated = makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext());

    expect($narrated->source)->toBe(InsightSource::Ai);
    expect($narrated->headline)->toBe('Your cash climbed 12% this month');

    Http::assertSent(function ($request): bool {
        $body = $request->body();
        $payload = json_decode($body, true);

        return $payload['tool_choice'] === ['type' => 'tool', 'name' => 'provide_daily_insight']
            && $payload['tools'][0]['input_schema']['properties']['chosen_key']['enum'] === ['cash-trend-30d']
            && str_contains($body, 'current_display')
            && ! str_contains($body, 'current_cents')   // raw cents never cross the wire
            && ! str_contains($body, '4820000');
    });
});

it('falls back to the template on an HTTP error', function () {
    Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 529)]);

    $narrated = makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext());

    expect($narrated->source)->toBe(InsightSource::Template);
    expect($narrated->headline)->toBe('Cash is up 12% over the last 30 days');
});

it('falls back to the template on a connection failure', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $narrated = makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext());

    expect($narrated->source)->toBe(InsightSource::Template);
});

it('falls back when the response has no tool_use block', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'nope']]]),
    ]);

    expect(makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext())->source)
        ->toBe(InsightSource::Template);
});

it('rejects a chosen key that was not offered', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeToolResponse([
            'chosen_key' => 'not-a-candidate',
            'headline' => 'Surprise',
            'body' => 'Surprise body.',
        ])),
    ]);

    expect(makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext())->source)
        ->toBe(InsightSource::Template);
});

it('rejects copy that invents a dollar amount', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeToolResponse([
            'chosen_key' => 'cash-trend-30d',
            'headline' => 'You are basically rich',
            'body' => 'Bank balances now total $999,999.',
        ])),
    ]);

    expect(makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext())->source)
        ->toBe(InsightSource::Template);
});

it('accepts copy whose dollar amounts match the offered display values', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeToolResponse([
            'chosen_key' => 'cash-trend-30d',
            'headline' => 'Cash on hand reached $48,200',
            'body' => 'That is 12% higher than a month ago.',
        ])),
    ]);

    expect(makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext())->source)
        ->toBe(InsightSource::Ai);
});

it('binds the Claude narrator only when the operator enabled and keyed it', function () {
    config(['insights.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
    expect(app(InsightNarrator::class))->toBeInstanceOf(ClaudeInsightNarrator::class);

    config(['insights.ai.enabled' => false]);
    expect(app(InsightNarrator::class))->toBeInstanceOf(TemplateInsightNarrator::class);

    config(['insights.ai.enabled' => true, 'services.anthropic.key' => null]);
    expect(app(InsightNarrator::class))->toBeInstanceOf(TemplateInsightNarrator::class);
});
