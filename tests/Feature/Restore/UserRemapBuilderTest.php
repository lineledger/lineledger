<?php

use App\Models\User;
use App\Services\Restore\UserRemapBuilder;

it('maps bundle users by email match and falls back to the importing user', function () {
    $importer = User::factory()->create(['email' => 'importer@example.com']);
    $alpha = User::factory()->create(['email' => 'alpha@example.com']);
    $bravo = User::factory()->create(['email' => 'bravo@example.com']);

    $bundleUsers = [
        ['id' => 11, 'email' => 'alpha@example.com', 'name' => 'Alpha User'],
        ['id' => 22, 'email' => 'bravo@example.com', 'name' => 'Bravo User'],
        ['id' => 33, 'email' => 'charlie@example.com', 'name' => 'Charlie User'],
    ];

    $result = (new UserRemapBuilder)->build($bundleUsers, $importer);

    expect($result['map'])->toBe([
        11 => $alpha->id,
        22 => $bravo->id,
        33 => $importer->id,
    ]);

    expect($result['matches'])->toHaveCount(3);

    $byOld = collect($result['matches'])->keyBy('old_id');

    expect($byOld[11]['match'])->toBe('email')
        ->and($byOld[11]['target_user_id'])->toBe($alpha->id)
        ->and($byOld[22]['match'])->toBe('email')
        ->and($byOld[22]['target_user_id'])->toBe($bravo->id)
        ->and($byOld[33]['match'])->toBe('fallback')
        ->and($byOld[33]['target_user_id'])->toBe($importer->id)
        ->and($byOld[33]['email'])->toBe('charlie@example.com')
        ->and($byOld[33]['name'])->toBe('Charlie User');
});

it('matches emails case-insensitively', function () {
    $importer = User::factory()->create(['email' => 'importer@example.com']);
    $foo = User::factory()->create(['email' => 'foo@example.com']);

    $bundleUsers = [
        ['id' => 7, 'email' => 'Foo@Example.com', 'name' => 'Foo Mixed'],
    ];

    $result = (new UserRemapBuilder)->build($bundleUsers, $importer);

    expect($result['map'])->toBe([7 => $foo->id])
        ->and($result['matches'][0]['match'])->toBe('email')
        ->and($result['matches'][0]['target_user_id'])->toBe($foo->id);
});

it('returns empty structures for an empty bundle', function () {
    $importer = User::factory()->create();

    $result = (new UserRemapBuilder)->build([], $importer);

    expect($result)->toBe(['map' => [], 'matches' => []]);
});
