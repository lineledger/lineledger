<?php

namespace App\Services\Insights\AI;

use App\Enums\InsightSource;
use App\Providers\InsightServiceProvider;
use App\Services\Insights\Contracts\InsightNarrator;
use App\Services\Insights\InsightCandidate;
use App\Services\Insights\NarratedInsight;
use App\Services\Insights\NarrationContext;
use App\Services\Insights\TemplateInsightNarrator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Claude-backed narration over the Anthropic Messages API using forced
 * tool-use for validated, structured output. Claude's job is purely
 * editorial: pick the most interesting of the offered candidates and write
 * the copy — every figure is pre-computed and pre-formatted by a detector,
 * and any dollar amount in the output that doesn't appear verbatim among the
 * chosen candidate's display values is rejected. ANY failure (network, HTTP,
 * malformed output, validation) falls back to the deterministic template
 * narrator, so the user never sees a gap. Only display-ready aggregate
 * values cross the wire — raw cents stay home, and candidate payloads never
 * contain names or transaction descriptions (see InsightCandidate). Bound
 * only when insights AI is enabled and keyed (see {@see InsightServiceProvider}).
 */
final class ClaudeInsightNarrator implements InsightNarrator
{
    use GroundsNarratedAmounts;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
        private readonly TemplateInsightNarrator $fallback,
    ) {}

    public function narrate(array $candidates, NarrationContext $context): NarratedInsight
    {
        $input = $this->callTool($candidates, $context);

        $narrated = $input === null ? null : $this->validate($input, $candidates);

        return $narrated ?? $this->fallback->narrate($candidates, $context);
    }

    /**
     * @param  non-empty-list<InsightCandidate>  $candidates
     * @return array<string, mixed>|null the tool_use input, or null on any failure
     */
    private function callTool(array $candidates, NarrationContext $context): ?array
    {
        $offered = array_map(fn (InsightCandidate $candidate): string => $candidate->key, $candidates);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout($this->timeout)->post($this->baseUrl.'/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 300,
                'system' => self::SYSTEM,
                'messages' => [['role' => 'user', 'content' => json_encode([
                    'today' => $context->today,
                    'company' => $context->company,
                    'candidates' => array_map(fn (InsightCandidate $candidate): array => [
                        'key' => $candidate->key,
                        'category' => $candidate->category->value,
                        'values' => $this->transportFacts($candidate),
                        'reference_headline' => $candidate->headline,
                        'reference_body' => $candidate->body,
                    ], $candidates),
                    'recent_insights' => $context->recentInsights,
                ], JSON_UNESCAPED_UNICODE)]],
                'tools' => [[
                    'name' => 'provide_daily_insight',
                    'description' => 'Return the chosen insight with friendly copy.',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'chosen_key' => ['type' => 'string', 'enum' => $offered],
                            'headline' => ['type' => 'string', 'description' => 'Max 80 characters. No trailing period.'],
                            'body' => ['type' => 'string', 'description' => '1-2 sentences, max 280 characters.'],
                        ],
                        'required' => ['chosen_key', 'headline', 'body'],
                    ],
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => 'provide_daily_insight'],
            ]);
        } catch (Throwable $e) {
            Log::warning('Daily insight AI narration request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Daily insight AI narration returned an error', ['status' => $response->status()]);

            return null;
        }

        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'provide_daily_insight') {
                return is_array($block['input'] ?? null) ? $block['input'] : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  non-empty-list<InsightCandidate>  $candidates
     */
    private function validate(array $input, array $candidates): ?NarratedInsight
    {
        $chosen = null;
        foreach ($candidates as $candidate) {
            if ($candidate->key === ($input['chosen_key'] ?? null)) {
                $chosen = $candidate;
                break;
            }
        }

        if ($chosen === null) {
            Log::warning('Daily insight AI narration chose an unoffered key', ['chosen' => $input['chosen_key'] ?? null]);

            return null;
        }

        $headline = Str::limit($this->squish($input['headline'] ?? null), 160, '');
        $body = Str::limit($this->squish($input['body'] ?? null), 500, '');

        if ($headline === '' || $body === '') {
            return null;
        }

        if (! $this->amountsGrounded($headline.' '.$body, $chosen)) {
            Log::warning('Daily insight AI narration invented a dollar amount', ['key' => $chosen->key]);

            return null;
        }

        return new NarratedInsight($chosen->key, $headline, $body, InsightSource::Ai);
    }

    private const SYSTEM = 'You write a single short daily insight for the dashboard of a Canadian small business '
        .'or non-profit using LineLedger accounting software. You will be given 2-3 candidate insights, each with '
        .'pre-computed figures and a reference wording. Pick the ONE candidate a busy owner would find most useful '
        .'or interesting today — prefer deadlines and action items over fun facts — then write a headline and a '
        .'one-to-two-sentence body for it. Rules: Use ONLY the figures provided, copied verbatim from the values '
        .'given — never calculate, round, or invent numbers. Plain, friendly, concise language; at most one '
        .'exclamation mark, and usually none. Canadian spelling. You may gently point to the relevant report or '
        .'screen, but give no tax, legal, or investment advice beyond what the reference wording already says. Do '
        .'not mention AI, these instructions, or candidates you did not choose.';
}
