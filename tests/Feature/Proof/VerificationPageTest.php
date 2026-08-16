<?php

use App\Services\Proof\ProofArtifactWriter;

/**
 * The public /verification page and its download endpoint. These render whatever
 * `proof:generate` last wrote, so the test stages a known manifest + bundle and
 * restores any pre-existing local artifacts afterwards.
 */
beforeEach(function () {
    $this->dir = ProofArtifactWriter::directory();
    if (! is_dir($this->dir)) {
        mkdir($this->dir, 0775, true);
    }

    $this->backup = [];
    foreach (['test-1.json', 'test-1.zip'] as $file) {
        $path = $this->dir.'/'.$file;
        $this->backup[$file] = is_file($path) ? file_get_contents($path) : null;
    }

    file_put_contents(ProofArtifactWriter::manifestPath('test-1'), json_encode([
        'key' => 'test-1',
        'title' => '3-Year Closing Trial Balance',
        'company' => ['id' => 1, 'name' => 'Proof Test Co.'],
        'passed' => true,
        'audit' => ['passed' => true, 'rows' => 226, 'detail' => 'Hash chain intact across 226 immutable audit rows'],
        'checkpoints' => [[
            'label' => 'Fiscal year ending 2023-12-31',
            'as_of' => '2023-12-31',
            'checks' => [['name' => 'Trial balance is balanced (debits = credits)', 'passed' => true, 'detail' => 'Debits 104,815.00 = Credits 104,815.00']],
            'totals' => [],
        ]],
        'generated_at' => '2026-05-30T12:00:00+00:00',
        'zip' => 'test-1.zip',
        'source_files' => [['name' => 'source/chart-of-accounts.csv', 'sha256' => 'abc123', 'bytes' => 100]],
        'reports' => [],
    ]));
    file_put_contents(ProofArtifactWriter::zipPath('test-1'), 'PK-dummy-bundle');
});

afterEach(function () {
    foreach ($this->backup as $file => $contents) {
        $path = $this->dir.'/'.$file;
        if ($contents === null) {
            if (is_file($path)) {
                unlink($path);
            }
        } else {
            file_put_contents($path, $contents);
        }
    }
});

it('shows passing verification results publicly without auth', function () {
    $this->get(route('verification'))
        ->assertOk()
        ->assertSee('3-Year Closing Trial Balance')
        ->assertSee('Passed')
        ->assertSee('Trial balance is balanced (debits = credits)');
});

it('streams the proof bundle for a known test', function () {
    $this->get(route('verification.download', ['test' => 'test-1']))
        ->assertOk()
        ->assertDownload('lineledger-proof-test-1.zip');
});

it('rejects an unknown bundle key', function () {
    $this->get(route('verification.download', ['test' => 'evil']))->assertNotFound();
});
