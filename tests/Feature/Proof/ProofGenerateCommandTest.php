<?php

use App\Services\Proof\ProofArtifactWriter;
use Illuminate\Support\Facades\File;

/**
 * Exercises the full artifact pipeline: build → validate → render every report →
 * zip. Guards against regressions in any of the PDF/CSV renders. Uses a small
 * per-year volume and writes to a scratch directory of its own, so it never
 * touches a locally-published bundle or another parallel worker's files.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/proof-generate-'.uniqid();
    config(['proof.directory' => $this->dir]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    File::deleteDirectory($this->dir);
});

it('generates a passing bundle containing every report', function () {
    $this->artisan('proof:generate', ['test' => 'test-1', '--per-year' => 12])
        ->assertExitCode(0);

    $zip = ProofArtifactWriter::zipPath('test-1');
    expect(is_file($zip))->toBeTrue();

    $archive = new ZipArchive;
    $archive->open($zip);
    $names = [];
    for ($i = 0; $i < $archive->numFiles; $i++) {
        $names[] = $archive->statIndex($i)['name'];
    }
    $archive->close();

    expect($names)->toContain(
        'manifest.json',
        'source/journal-entries.csv',
        'reports/trial-balance-2025-12-31.pdf',
        'reports/ar-aging-2025-12-31.pdf',
        'reports/ap-aging-2025-12-31.pdf',
        'reports/open-invoices-2025-12-31.pdf',
        'reports/open-bills-2025-12-31.pdf',
        'reports/general-ledger-2023-01-01_to_2025-12-31.csv',
        'reports/general-ledger-2025-01-01_to_2025-12-31.pdf',
    );
});
