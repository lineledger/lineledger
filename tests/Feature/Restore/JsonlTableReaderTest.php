<?php

use App\Services\Restore\JsonlTableReader;

beforeEach(function () {
    $this->workDir = sys_get_temp_dir().'/restore-jsonl-test-'.uniqid();
    mkdir($this->workDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->workDir)) {
        foreach (glob($this->workDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->workDir);
    }
});

it('yields one decoded array per non-empty line', function () {
    $path = $this->workDir.'/sample.jsonl';
    $rows = [
        ['id' => 1, 'name' => 'Alpha'],
        ['id' => 2, 'name' => 'Bravo'],
        ['id' => 3, 'name' => 'Charlie'],
    ];

    $payload = '';
    foreach ($rows as $row) {
        $payload .= json_encode($row, JSON_UNESCAPED_SLASHES)."\n";
    }
    file_put_contents($path, $payload);

    $reader = new JsonlTableReader;
    $decoded = iterator_to_array($reader->read($path), preserve_keys: false);

    expect($decoded)->toHaveCount(3)
        ->and($decoded[0])->toBe(['id' => 1, 'name' => 'Alpha'])
        ->and($decoded[1])->toBe(['id' => 2, 'name' => 'Bravo'])
        ->and($decoded[2])->toBe(['id' => 3, 'name' => 'Charlie']);
});

it('handles trailing blank lines gracefully', function () {
    $path = $this->workDir.'/trailing.jsonl';
    $payload = json_encode(['a' => 1])."\n".json_encode(['a' => 2])."\n\n\n";
    file_put_contents($path, $payload);

    $reader = new JsonlTableReader;
    $decoded = iterator_to_array($reader->read($path), preserve_keys: false);

    expect($decoded)->toHaveCount(2)
        ->and($decoded[1])->toBe(['a' => 2]);
});

it('throws RuntimeException when the file is missing', function () {
    $reader = new JsonlTableReader;

    expect(fn () => iterator_to_array($reader->read($this->workDir.'/does-not-exist.jsonl')))
        ->toThrow(RuntimeException::class);
});

it('counts rows without exhausting memory', function () {
    $path = $this->workDir.'/count.jsonl';
    $payload = '';
    for ($i = 0; $i < 7; $i++) {
        $payload .= json_encode(['i' => $i])."\n";
    }
    // Trailing blank line should not affect the count.
    $payload .= "\n";
    file_put_contents($path, $payload);

    $reader = new JsonlTableReader;

    expect($reader->count($path))->toBe(7);
});
