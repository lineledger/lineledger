<?php

use App\Enums\InsightCategory;
use App\Services\Banking\Import\AI\ClaudeStatementIntelligence;
use App\Services\Classification\AI\ClaudeTransactionClassifier;
use App\Services\Inbox\OCR\ClaudeReceiptIntelligence;
use App\Services\Insights\AI\ClaudeInsightNarrator;
use App\Services\Insights\InsightCandidate;
use App\Services\Insights\NarrationContext;
use App\Services\Insights\TemplateInsightNarrator;
use App\Support\Anthropic\StructuredOutputRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Structured outputs rejects a schema with an object that doesn't set
 * additionalProperties: false, so walk every nested object and item schema.
 *
 * @param  array<string, mixed>  $schema
 */
function structuredSchemaObjectsAreClosed(array $schema): bool
{
    if (($schema['type'] ?? null) === 'object' && ($schema['additionalProperties'] ?? null) !== false) {
        return false;
    }

    foreach ((array) ($schema['properties'] ?? []) as $property) {
        if (is_array($property) && ! structuredSchemaObjectsAreClosed($property)) {
            return false;
        }
    }

    if (is_array($schema['items'] ?? null) && ! structuredSchemaObjectsAreClosed($schema['items'])) {
        return false;
    }

    return true;
}

function structuredOutputRequest(): StructuredOutputRequest
{
    return new StructuredOutputRequest('test-key', 'https://api.anthropic.com', 'claude-sonnet-5-5', 5, 'low');
}

it('sends a structured-outputs request with no forced tool and returns the JSON answer', function () {
    Http::fake(['*/v1/messages' => Http::response(fakeClaudeJsonResponse(['ok' => true]), 200)]);

    $schema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false];

    $result = structuredOutputRequest()->send('system', 'user', $schema, maxTokens: 1000, logLabel: 'Test');

    expect($result->data)->toBe(['ok' => true])
        ->and($result->error)->toBeNull();

    Http::assertSent(function (Request $request) use ($schema): bool {
        $payload = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $payload['model'] === 'claude-sonnet-5-5'
            && $payload['max_tokens'] === 1000
            && $payload['output_config'] === ['format' => ['type' => 'json_schema', 'schema' => $schema], 'effort' => 'low']
            && ! array_key_exists('tools', $payload)
            && ! array_key_exists('tool_choice', $payload)
            && ! array_key_exists('thinking', $payload);
    });
});

it('reports transient failures with an error code and declines without one', function (Closure $fake, ?string $error) {
    Http::fake(['*/v1/messages' => $fake]);

    $result = structuredOutputRequest()->send('system', 'user', ['type' => 'object', 'additionalProperties' => false], maxTokens: 100, logLabel: 'Test');

    expect($result->data)->toBeNull()
        ->and($result->error)->toBe($error);
})->with([
    'network error' => [fn () => throw new ConnectionException('timed out'), 'request_failed'],
    'API error' => [fn () => Http::response(['type' => 'error'], 529), 'http_529'],
    'refusal' => [fn () => Http::response(['stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber'], 'content' => []], 200), null],
    'truncated' => [fn () => Http::response(['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '{"a":']]], 200), null],
    'not JSON' => [fn () => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'hello']]], 200), null],
    'no text block' => [fn () => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => '']]], 200), null],
]);

it('sends every AI feature as structured outputs with closed schemas', function () {
    Http::fake(['*/v1/messages' => Http::response(fakeClaudeJsonResponse([]), 200)]);

    $receiptPath = tempnam(sys_get_temp_dir(), 'receipt');
    file_put_contents($receiptPath, 'not really a png');

    try {
        (new ClaudeReceiptIntelligence('test-key', 'https://api.anthropic.com', 'claude-sonnet-5-5'))
            ->extract($receiptPath, 'image/png');
    } finally {
        @unlink($receiptPath);
    }

    (new ClaudeTransactionClassifier('test-key', 'https://api.anthropic.com', 'claude-sonnet-5-5'))
        ->classify(['Tim Hortons'], [['code' => '6200', 'name' => 'Meals']]);

    $statement = new ClaudeStatementIntelligence('test-key', 'https://api.anthropic.com', 'claude-sonnet-5-5');
    $statement->inferMapping(['Date', 'Description', 'Amount'], [['2026-01-02', 'Coffee', '-4.50']]);
    $statement->extractTransactions('2026-01-02 Coffee -4.50 995.50');

    (new ClaudeInsightNarrator('test-key', 'https://api.anthropic.com', 'claude-sonnet-5-5', 5, new TemplateInsightNarrator))
        ->narrate([new InsightCandidate('cash-trend-30d', InsightCategory::Fact, 50, ['current_display' => '$48,200'], 'Cash is up', 'Body.')],
            new NarrationContext('2026-06-10', ['home_currency' => 'CAD'], []));

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(5);

    foreach ($recorded as [$request]) {
        $payload = $request->data();

        expect($payload['model'])->toBe('claude-sonnet-5-5')
            ->and($payload)->not->toHaveKey('tool_choice')
            ->and($payload)->not->toHaveKey('tools')
            ->and($payload['output_config']['format']['type'])->toBe('json_schema')
            ->and($payload['output_config']['effort'])->toBe('low')
            ->and(structuredSchemaObjectsAreClosed($payload['output_config']['format']['schema']))->toBeTrue();
    }
});

it('defaults every AI feature to Claude Sonnet 5.5 at low effort', function () {
    expect(config('inbox.ai.model'))->toBe('claude-sonnet-5-5')
        ->and(config('inbox.ai.effort'))->toBe('low')
        ->and(config('banking.statement_import.ai.model'))->toBe('claude-sonnet-5-5')
        ->and(config('banking.statement_import.ai.effort'))->toBe('low')
        ->and(config('insights.ai.model'))->toBe('claude-sonnet-5-5')
        ->and(config('insights.ai.effort'))->toBe('low');
});
