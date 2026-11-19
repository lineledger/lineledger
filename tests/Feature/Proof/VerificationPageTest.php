<?php

use App\Services\Proof\ProofArtifactWriter;
use Illuminate\Support\Facades\File;

/**
 * The public /verification page and its download endpoint. These render whatever
 * `proof:generate` last wrote, so the test stages a known manifest + bundle in a
 * scratch directory of its own, never the published one that parallel workers
 * and a developer's local bundle would otherwise share.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/proof-page-'.uniqid();
    mkdir($this->dir, 0775, true);
    config(['proof.directory' => $this->dir]);

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
    File::deleteDirectory($this->dir);
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
