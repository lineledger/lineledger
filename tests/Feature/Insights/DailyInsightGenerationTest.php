<?php

use App\Enums\InsightCategory;
use App\Enums\InsightSource;
use App\Models\Company;
use App\Models\DailyInsight;
use App\Services\Insights\AI\ClaudeInsightNarrator;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Contracts\InsightNarrator;
use App\Services\Insights\DailyInsightGenerator;
use App\Services\Insights\InsightCandidate;
use App\Services\Insights\InsightSelector;
use App\Services\Insights\TemplateInsightNarrator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function makeGenerationDetector(string $key, InsightCategory $category, int $score, bool $urgent = false): InsightDetector
{
    return new class($key, $category, $score, $urgent) implements InsightDetector
    {
        public function __construct(
            private readonly string $detectorKey,
            private readonly InsightCategory $detectorCategory,
            private readonly int $score,
            private readonly bool $urgent,
        ) {}

        public function key(): string
        {
            return $this->detectorKey;
        }

        public function category(): InsightCategory
        {
            return $this->detectorCategory;
        }

        public function detect(Company $company, CarbonImmutable $today): array
        {
            return [new InsightCandidate(
                key: $this->detectorKey,
                category: $this->detectorCategory,
                score: $this->score,
                facts: ['amount_cents' => 482000, 'amount_display' => '$4,820'],
                headline: 'Template headline for '.$this->detectorKey,
                body: 'Template body for '.$this->detectorKey.' worth $4,820.',
                urgent: $this->urgent,
            )];
        }

        public function cta(Company $company): ?array
        {
            return null;
        }
    };
}

/**
 * @param  list<InsightDetector>  $detectors
 */
function makeInsightGenerator(array $detectors, ?InsightNarrator $narrator = null): DailyInsightGenerator
{
    $template = new TemplateInsightNarrator;

    return new DailyInsightGenerator(
        narrator: $narrator ?? $template,
        template: $template,
        selector: new InsightSelector,
        detectors: $detectors,
    );
}

function makeFakeClaudeNarrator(): ClaudeInsightNarrator
{
    return new ClaudeInsightNarrator(
        apiKey: 'test-key',
        baseUrl: 'https://api.anthropic.com',
        model: 'claude-sonnet-4-6',
        timeout: 5,
        fallback: new TemplateInsightNarrator,
    );
}

it('stores the winning candidate as a template-sourced row', function () {
    $generator = makeInsightGenerator([makeGenerationDetector('aaa', InsightCategory::Hygiene, 80)]);

    $insight = $generator->generate($this->company, $this->company->currentDateTime());

    expect($insight)->not->toBeNull();
    expect($insight->type)->toBe('aaa');
    expect($insight->source)->toBe(InsightSource::Template);
    expect($insight->headline)->toBe('Template headline for aaa');
    expect($insight->insight_date->toDateString())->toBe($this->company->currentDateTime()->toDateString());
    expect($insight->facts['amount_cents'])->toBe(482000);
    expect(DailyInsight::query()->count())->toBe(1);
});

it('is idempotent for the same company-day', function () {
    $generator = makeInsightGenerator([makeGenerationDetector('aaa', InsightCategory::Hygiene, 80)]);
    $now = $this->company->currentDateTime();

    $first = $generator->generate($this->company, $now);
    $second = $generator->generate($this->company, $now);

    expect($second->id)->toBe($first->id);
    expect(DailyInsight::query()->count())->toBe(1);
});

it('skips the day quietly when no detector fires', function () {
    $insight = makeInsightGenerator([])->generate($this->company, $this->company->currentDateTime());

    expect($insight)->toBeNull();
    expect(DailyInsight::query()->count())->toBe(0);
});

it('suppresses a recently shown type and picks the runner-up', function () {
    $now = $this->company->currentDateTime();

    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $now->subDay()->toDateString(),
        'type' => 'aaa',
    ]);

    $generator = makeInsightGenerator([
        makeGenerationDetector('aaa', InsightCategory::Hygiene, 90),
        makeGenerationDetector('bbb', InsightCategory::Fact, 10),
    ]);

    expect($generator->generate($this->company, $now)->type)->toBe('bbb');
});

it('re-allows a type once its anti-repeat window has passed', function () {
    $now = $this->company->currentDateTime();

    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $now->subDays(10)->toDateString(), // hygiene window is 10 days
        'type' => 'aaa',
    ]);

    $generator = makeInsightGenerator([
        makeGenerationDetector('aaa', InsightCategory::Hygiene, 90),
        makeGenerationDetector('bbb', InsightCategory::Fact, 10),
    ]);

    expect($generator->generate($this->company, $now)->type)->toBe('aaa');
});

it('lets an urgent deadline re-emit inside its window', function () {
    $now = $this->company->currentDateTime();

    DailyInsight::factory()->create([
        'company_id' => $this->company->id,
        'insight_date' => $now->subDay()->toDateString(),
        'type' => 'aaa',
    ]);

    $generator = makeInsightGenerator([
        makeGenerationDetector('aaa', InsightCategory::Deadline, 95, urgent: true),
    ]);

    expect($generator->generate($this->company, $now)->type)->toBe('aaa');
});

it('never calls the AI for a company that has not opted in', function () {
    Http::fake();

    $generator = makeInsightGenerator(
        [makeGenerationDetector('aaa', InsightCategory::Hygiene, 80)],
        narrator: makeFakeClaudeNarrator(),
    );

    $insight = $generator->generate($this->company, $this->company->currentDateTime());

    Http::assertNothingSent();
    expect($insight->source)->toBe(InsightSource::Template);
});

it('uses the AI narrator once the company opts in', function () {
    $this->company->setInsightsState(['ai_narration' => true]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'tool_use',
                'name' => 'provide_daily_insight',
                'input' => [
                    'chosen_key' => 'aaa',
                    'headline' => 'An AI headline',
                    'body' => 'An AI body quoting $4,820 faithfully.',
                ],
            ]],
        ]),
    ]);

    $generator = makeInsightGenerator(
        [makeGenerationDetector('aaa', InsightCategory::Hygiene, 80)],
        narrator: makeFakeClaudeNarrator(),
    );

    $insight = $generator->generate($this->company, $this->company->currentDateTime());

    Http::assertSentCount(1);
    expect($insight->source)->toBe(InsightSource::Ai);
    expect($insight->headline)->toBe('An AI headline');
    expect($insight->type)->toBe('aaa');
});
