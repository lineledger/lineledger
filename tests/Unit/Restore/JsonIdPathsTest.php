<?php

use App\Services\Restore\JsonIdPaths;

$map = fn (array $ids): Closure => fn (int $id): ?int => $ids[$id] ?? null;

it('remaps a list of ids, dropping the ones that do not map', function () use ($map) {
    expect(JsonIdPaths::remap([1, 2, 3], '*', $map([1 => 10, 3 => 30])))->toBe([10, 30]);
});

it('remaps an id inside each element of a list', function () use ($map) {
    $layers = [['layer_id' => 1, 'qty' => '2'], ['layer_id' => 2, 'qty' => '1']];

    expect(JsonIdPaths::remap($layers, '*.layer_id', $map([1 => 10])))
        ->toBe([['layer_id' => 10, 'qty' => '2'], ['layer_id' => null, 'qty' => '1']]);
});

it('remaps object keys that are ids', function () use ($map) {
    expect(JsonIdPaths::remap(['5' => ['a' => 1], '6' => ['a' => 2]], '{key}', $map([5 => 50])))
        ->toBe([50 => ['a' => 1]]);
});

it('remaps an id at a named key, and leaves everything else alone', function () use ($map) {
    $settings = ['accountId' => 7, 'perPage' => 7, 'nested' => ['accountId' => 7]];

    expect(JsonIdPaths::remap($settings, 'accountId', $map([7 => 70])))
        ->toBe(['accountId' => 70, 'perPage' => 7, 'nested' => ['accountId' => 7]]);
});

it('remaps a bare id, and nulls one that does not map', function () use ($map) {
    expect(JsonIdPaths::remap(7, '', $map([7 => 70])))->toBe(70)
        ->and(JsonIdPaths::remap(8, '', $map([7 => 70])))->toBeNull()
        ->and(JsonIdPaths::remap('7', '', $map([7 => 70])))->toBe(70);
});

it('leaves values that are not ids, or paths that are not there, untouched', function () use ($map) {
    expect(JsonIdPaths::remap(null, '*', $map([])))->toBeNull()
        ->and(JsonIdPaths::remap('text', '', $map([])))->toBe('text')
        ->and(JsonIdPaths::remap(['other' => 1], 'accountId', $map([])))->toBe(['other' => 1])
        ->and(JsonIdPaths::remap([0, 'x'], '*', $map([])))->toBe([0, 'x']);
});

it('lists the ids at a path', function () {
    expect(JsonIdPaths::ids(['3' => ['interest_account_id' => 9]], '{key}'))->toBe([3])
        ->and(JsonIdPaths::ids(['3' => ['interest_account_id' => 9]], '*.interest_account_id'))->toBe([9])
        ->and(JsonIdPaths::ids([4, 5], '*'))->toBe([4, 5]);
});
