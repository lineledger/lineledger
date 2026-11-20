<?php

use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\UkDemoCompanySeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\AdversarialBackupFixture;
use Tests\Support\Backup\BackupRoundTrip;

/**
 * The headline proof that an organization backup is complete and restores
 * completely, on the demo organizations (plus, on `demo`, every shape of data
 * a restore has got wrong: {@see AdversarialBackupFixture}).
 *
 * For each organization:
 *  1. the bundle holds every row and column of the live organization;
 *  2. the verifier passes it (bundle checks, then a rolled-back trial restore
 *     compared row by row, ledger and files checked);
 *  3. a real restore next to the original matches the bundle row for row, with
 *     the same trial balance;
 *  4. a backup of that copy verifies too (the copy is a sound organization);
 *  5. it restores onto an instance where the original and its people don't
 *     exist (the original purged, nobody matched by email).
 */
describe('Canadian demo organizations', function () {
    beforeEach(function () {
        Storage::fake('local');
        Storage::fake('public');

        $this->seed(DemoCompanySeeder::class);
        app()->forgetInstance('current_company');

        AdversarialBackupFixture::apply(BackupRoundTrip::company('demo'));
    });

    it('backs up and restores :dataset completely', function (string $slug) {
        BackupRoundTrip::assertCompleteRoundTrip(BackupRoundTrip::company($slug));
    })->with(['demo', 'demo-society']);
});

describe('UK demo organizations', function () {
    beforeEach(function () {
        Storage::fake('local');
        Storage::fake('public');

        $this->seed(UkDemoCompanySeeder::class);
        app()->forgetInstance('current_company');
    });

    it('backs up and restores :dataset completely', function (string $slug) {
        BackupRoundTrip::assertCompleteRoundTrip(BackupRoundTrip::company($slug));
    })->with(['uk-demo', 'iom-demo']);
});
