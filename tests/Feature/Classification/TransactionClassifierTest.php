<?php

use App\Services\Classification\AI\ClaudeTransactionClassifier;
use App\Services\Classification\AI\NullTransactionClassifier;
use App\Services\Classification\Contracts\TransactionClassifier;
use Illuminate\Support\Facades\Http;

function claudeClassifier(): ClaudeTransactionClassifier
{
    return new ClaudeTransactionClassifier('test-key', 'https://api.anthropic.com', 'claude-sonnet-4-6');
}

/** @return list<array{code: string, name: string}> */
function sampleAccounts(): array
{
    return [['code' => '6010', 'name' => 'Bank Charges'], ['code' => '6200', 'name' => 'Meals']];
}

it('maps descriptions to valid account codes and drops invalid or null picks', function () {
    Http::fake(['*/v1/messages' => Http::response(['content' => [[
        'type' => 'tool_use',
        'name' => 'classify_transactions',
        'input' => ['classifications' => [
            ['index' => 0, 'account_code' => '6200'],
            ['index' => 1, 'account_code' => '9999'], // not in the chart → dropped
            ['index' => 2, 'account_code' => null],   // no fit → dropped
        ]],
    ]]], 200)]);

    $result = claudeClassifier()->classify(['Tim Hortons', 'Mystery Co', 'Unknown'], sampleAccounts());

    expect($result)->toBe(['Tim Hortons' => '6200']);
});

it('returns an empty map and records lastError on an HTTP error', function () {
    Http::fake(['*/v1/messages' => Http::response([], 500)]);

    $classifier = claudeClassifier();

    expect($classifier->classify(['x'], sampleAccounts()))->toBe([])
        ->and($classifier->lastError())->toBe('http_500');
});

it('returns an empty map when the tool block is missing', function () {
    Http::fake(['*/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'no tool here']]], 200)]);

    expect(claudeClassifier()->classify(['x'], sampleAccounts()))->toBe([]);
});

it('the null classifier is disabled and returns nothing', function () {
    $null = new NullTransactionClassifier;

    expect($null->isEnabled())->toBeFalse()
        ->and($null->classify(['x'], sampleAccounts()))->toBe([]);
});

it('binds the null classifier when the AI gate is off', function () {
    config()->set('inbox.ai.enabled', false);

    expect(app(TransactionClassifier::class))->toBeInstanceOf(NullTransactionClassifier::class);
});

it('binds the claude classifier when the AI gate is on and keyed', function () {
    config()->set('inbox.ai.enabled', true);
    config()->set('inbox.ai.driver', 'http');
    config()->set('services.anthropic.key', 'test-key');

    expect(app(TransactionClassifier::class))->toBeInstanceOf(ClaudeTransactionClassifier::class);
});
