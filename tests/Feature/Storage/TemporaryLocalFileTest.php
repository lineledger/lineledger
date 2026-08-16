<?php

use App\Support\Storage\TemporaryLocalFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

/**
 * The bridge between object storage and the consumers that can only take a
 * filesystem path (ZipArchive, pdftotext, fopen).
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');
});

it('hands a local disk its own path without copying', function () {
    Storage::disk('local')->put('reports/statement.csv', 'a,b,c');

    $seen = TemporaryLocalFile::with('local', 'reports/statement.csv', fn (string $path) => $path);

    expect($seen)->toBe(Storage::disk('local')->path('reports/statement.csv'))
        ->and(is_file($seen))->toBeTrue();
});

it('streams a remote file to a readable local path', function () {
    Storage::disk('s3')->put('reports/statement.csv', 'date,amount'.PHP_EOL.'2026-01-01,10.00');

    $contents = TemporaryLocalFile::with('s3', 'reports/statement.csv', function (string $path) {
        expect(is_file($path))->toBeTrue();

        return file_get_contents($path);
    });

    expect($contents)->toBe('date,amount'.PHP_EOL.'2026-01-01,10.00');
});

it('preserves the extension so path-sniffing consumers still work', function () {
    Storage::disk('s3')->put('inbox/receipt.pdf', '%PDF-1.4 fake');

    $extension = TemporaryLocalFile::with(
        's3',
        'inbox/receipt.pdf',
        fn (string $path) => pathinfo($path, PATHINFO_EXTENSION),
    );

    expect($extension)->toBe('pdf');
});

it('deletes the temporary copy once the callback returns', function () {
    Storage::disk('s3')->put('inbox/receipt.pdf', 'bytes');

    $path = TemporaryLocalFile::with('s3', 'inbox/receipt.pdf', fn (string $p) => $p);

    expect(is_file($path))->toBeFalse();
});

it('deletes the temporary copy even when the callback throws', function () {
    Storage::disk('s3')->put('inbox/receipt.pdf', 'bytes');

    $captured = null;
    $thrown = null;

    try {
        TemporaryLocalFile::with('s3', 'inbox/receipt.pdf', function (string $path) use (&$captured) {
            $captured = $path;

            throw new RuntimeException('parser blew up');
        });
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->toBe('parser blew up')
        ->and($captured)->not->toBeNull()
        ->and(is_file($captured))->toBeFalse();
});

it('fails loudly when the object is not there', function () {
    $reached = false;

    expect(function () use (&$reached) {
        TemporaryLocalFile::with('s3', 'missing/nope.pdf', function () use (&$reached) {
            $reached = true;
        });
    })->toThrow(UnableToReadFile::class);

    expect($reached)->toBeFalse();
});

it('returns whatever the callback returns', function () {
    Storage::disk('s3')->put('inbox/receipt.pdf', 'bytes');

    expect(TemporaryLocalFile::with('s3', 'inbox/receipt.pdf', fn () => ['parsed' => 3]))
        ->toBe(['parsed' => 3]);
});
