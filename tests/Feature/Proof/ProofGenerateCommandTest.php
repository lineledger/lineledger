<?php

use App\Services\Proof\ProofArtifactWriter;

/**
 * Exercises the full artifact pipeline: build → validate → render every report →
 * zip. Guards against regressions in any of the PDF/CSV renders. Uses a small
 * per-year volume and restores any locally-published bundle afterwards.
 */
beforeEach(function () {
    // Park any locally-published bundle on DISK (a published zip can run tens
    // of MB — slurping it into memory has blown the test memory limit).
    $this->backup = [];
    foreach (['test-1.json', 'test-1.zip'] as $file) {
        $path = ProofArtifactWriter::directory().'/'.$file;
        if (is_file($path)) {
            $this->backup[$file] = $path.'.test-backup';
            rename($path, $this->backup[$file]);
        } else {
            $this->backup[$file] = null;
        }
    }
});

afterEach(function () {
    app()->forgetInstance('current_company');
    foreach ($this->backup as $file => $parked) {
        $path = ProofArtifactWriter::directory().'/'.$file;
        if (is_file($path)) {
            unlink($path);
        }
        if ($parked !== null && is_file($parked)) {
            rename($parked, $path);
        }
    }
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
