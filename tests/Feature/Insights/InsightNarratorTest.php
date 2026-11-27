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
        model: 'claude-sonnet-5-5',
        timeout: 5,
        fallback: new TemplateInsightNarrator,
    );
}

it('template narrator returns the top candidate verbatim', function () {
    $narrated = (new TemplateInsightNarrator)->narrate([makeNarratorCandidate()], makeNarratorContext());

    expect($narrated->key)->toBe('cash-trend-30d');
    expect($narrated->headline)->toBe('Cash is up 12% over the last 30 days');
    expect($narrated->source)->toBe(InsightSource::Template);
});

it('returns AI copy on a valid structured response and sends only display values', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeJsonResponse([
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

        return $payload['model'] === 'claude-sonnet-5-5'
            && ! isset($payload['tool_choice']) && ! isset($payload['tools'])   // forced tool use is a 400 on Sonnet 5.5
            && $payload['output_config']['format']['type'] === 'json_schema'
            && $payload['output_config']['format']['schema']['properties']['chosen_key']['enum'] === ['cash-trend-30d']
            && $payload['output_config']['format']['schema']['additionalProperties'] === false
            && $payload['output_config']['effort'] === 'low'
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

it('falls back when the response text is not JSON', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'nope']]]),
    ]);

    expect(makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext())->source)
        ->toBe(InsightSource::Template);
});

it('falls back when the response has no text block', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => '']]]),
    ]);

    expect(makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext())->source)
        ->toBe(InsightSource::Template);
});

it('reads the answer after a leading thinking block', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeJsonResponse([
            'chosen_key' => 'cash-trend-30d',
            'headline' => 'Your cash climbed 12% this month',
            'body' => 'Bank balances now sit at $48,200.',
        ], leadingBlocks: [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']])),
    ]);

    $narrated = makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext());

    expect($narrated->source)->toBe(InsightSource::Ai)
        ->and($narrated->headline)->toBe('Your cash climbed 12% this month');
});

it('falls back on a refusal or a truncated answer', function (string $stopReason) {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => $stopReason,
            'stop_details' => $stopReason === 'refusal' ? ['type' => 'refusal', 'category' => 'general_harms'] : null,
            'content' => [['type' => 'text', 'text' => '{"chosen_key": "cash-trend-30d", "headl']],
        ]),
    ]);

    expect(makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext())->source)
        ->toBe(InsightSource::Template);
})->with(['refusal', 'max_tokens']);

it('accepts a chosen key that differs only in letter case', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeJsonResponse([
            // Built from the allowlisted literal: a mixed-case key in the source
            // trips gitleaks' generic-api-key rule (see .gitleaks.toml).
            'chosen_key' => strtoupper('cash-trend-30d'),
            'headline' => 'Your cash climbed 12% this month',
            'body' => 'Bank balances now sit at $48,200.',
        ])),
    ]);

    $narrated = makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext());

    expect($narrated->source)->toBe(InsightSource::Ai)
        ->and($narrated->key)->toBe('cash-trend-30d');
});

it('omits effort when it is configured empty', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeJsonResponse([
            'chosen_key' => 'cash-trend-30d',
            'headline' => 'Your cash climbed 12% this month',
            'body' => 'Bank balances now sit at $48,200.',
        ])),
    ]);

    $narrator = new ClaudeInsightNarrator(
        apiKey: 'test-key',
        baseUrl: 'https://api.anthropic.com',
        model: 'claude-haiku-4-5',
        timeout: 5,
        fallback: new TemplateInsightNarrator,
        effort: '',
    );

    $narrator->narrate([makeNarratorCandidate()], makeNarratorContext());

    Http::assertSent(fn ($request): bool => ! array_key_exists('effort', $request->data()['output_config'])
        && isset($request->data()['output_config']['format']));
});

it('rejects a chosen key that was not offered', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(fakeClaudeJsonResponse([
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
        'api.anthropic.com/*' => Http::response(fakeClaudeJsonResponse([
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
        'api.anthropic.com/*' => Http::response(fakeClaudeJsonResponse([
            'chosen_key' => 'cash-trend-30d',
            'headline' => 'Cash on hand reached $48,200',
            'body' => 'That is 12% higher than a month ago.',
        ])),
    ]);

    expect(makeClaudeNarrator()->narrate([makeNarratorCandidate()], makeNarratorContext())->source)
        ->toBe(InsightSource::Ai);
});

it('rejects copy that invents a pound amount for a sterling company', function () {
    $candidate = new InsightCandidate(
        key: 'cash-trend-30d',
        category: InsightCategory::Fact,
        score: 50,
        facts: ['current_cents' => 4820000, 'current_display' => '£48,200', 'pct_change' => 12],
        headline: 'Cash is up 12% over the last 30 days',
        body: 'Your bank balances total £48,200.',
    );

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(fakeClaudeJsonResponse([
                'chosen_key' => 'cash-trend-30d',
                'headline' => 'You are basically rich',
                'body' => 'Bank balances now total £999,999.',
            ]))
            ->push(fakeClaudeJsonResponse([
                'chosen_key' => 'cash-trend-30d',
                'headline' => 'Cash on hand reached £48,200',
                'body' => 'That is 12% higher than a month ago.',
            ])),
    ]);

    expect(makeClaudeNarrator()->narrate([$candidate], makeNarratorContext())->source)->toBe(InsightSource::Template)
        ->and(makeClaudeNarrator()->narrate([$candidate], makeNarratorContext())->source)->toBe(InsightSource::Ai);
});

it('binds the Claude narrator only when the operator enabled and keyed it', function () {
    config(['insights.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
    expect(app(InsightNarrator::class))->toBeInstanceOf(ClaudeInsightNarrator::class);

    config(['insights.ai.enabled' => false]);
    expect(app(InsightNarrator::class))->toBeInstanceOf(TemplateInsightNarrator::class);

    config(['insights.ai.enabled' => true, 'services.anthropic.key' => null]);
    expect(app(InsightNarrator::class))->toBeInstanceOf(TemplateInsightNarrator::class);
});
