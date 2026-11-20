<?php

use App\Models\Company;
use App\Models\CompanyRestore;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Backup\Verification\BackupVerifier;
use App\Services\Backup\Verification\BundleReader;
use App\Services\Backup\Verification\LedgerChecks;
use App\Services\Backup\Verification\RowComparator;
use App\Services\Backup\Verification\TrialRestore;
use App\Services\Backup\Verification\VerificationReport;
use App\Services\Restore\RestoreTrace;
use App\Support\Storage\StorageDisks;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\AdversarialBackupFixture;
use Tests\Support\Backup\BackupRoundTrip;

/**
 * The verifier must fail a backup that is damaged, and a restore that isn't
 * faithful: each test breaks one thing and expects the matching check to say
 * so. The damage done "with the hashes made to match" gets past the per-file
 * checksums, so only the deeper checks can catch it.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->seed(DemoCompanySeeder::class);
    app()->forgetInstance('current_company');

    $this->company = BackupRoundTrip::company('demo');
    AdversarialBackupFixture::apply($this->company);

    $this->zip = BackupRoundTrip::bundle($this->company);
});

it('refuses a file that is not a backup', function () {
    $path = Storage::disk('local')->path('not-a-backup.zip');
    file_put_contents($path, 'definitely not a zip');

    $report = app(BackupVerifier::class)->verify($path);

    expect($report->passed())->toBeFalse()
        ->and(BackupRoundTrip::checkStatuses($report, 'Bundle readable'))->toBe(['fail']);
});

it('catches a table file missing from the bundle', function () {
    $damaged = BackupRoundTrip::rewrite($this->zip, fn (string $dir) => unlink($dir.'/data/invoices.jsonl'));

    $report = app(BackupVerifier::class)->verify($damaged, trialRestore: false);

    expect(BackupRoundTrip::checkStatuses($report, 'Table files intact'))->toContain('fail')
        ->and(implode(' ', $report->failures()))->toContain('data/invoices.jsonl is missing');
});

it('catches a table file that does not match its recorded hash', function () {
    $damaged = BackupRoundTrip::rewrite($this->zip, fn (string $dir) => BackupRoundTrip::editRows(
        $dir,
        'contacts',
        fn (array $row): array => ['display_name' => 'Edited '.$row['display_name']] + $row,
    ));

    $report = app(BackupVerifier::class)->verify($damaged, trialRestore: false);

    expect(BackupRoundTrip::checkStatuses($report, 'Table files intact'))->toContain('fail')
        ->and(implode(' ', $report->failures()))->toContain('data/contacts.jsonl does not match its recorded sha256');
});

it('catches a tampered users file', function () {
    $damaged = BackupRoundTrip::rewrite($this->zip, fn (string $dir) => file_put_contents($dir.'/users.json', '[]'));

    $report = app(BackupVerifier::class)->verify($damaged, trialRestore: false);

    expect(BackupRoundTrip::checkStatuses($report, 'Users file intact'))->toBe(['fail']);
});

it('catches an attachment file missing from the bundle', function () {
    $damaged = BackupRoundTrip::rewrite($this->zip, function (string $dir): void {
        $files = glob($dir.'/files/attachments/invoice/*/*') ?: [];
        expect($files)->not->toBe([]);
        unlink($files[0]);
    });

    $report = app(BackupVerifier::class)->verify($damaged, trialRestore: false);

    expect(BackupRoundTrip::checkStatuses($report, 'Attachment files present'))->toContain('fail');
});

it('catches books that no longer balance, even with the hashes made to match', function () {
    $edited = false;
    $damaged = BackupRoundTrip::rewrite($this->zip, fn (string $dir) => BackupRoundTrip::editRows(
        $dir,
        'journal_lines',
        function (array $row) use (&$edited): array {
            if (! $edited && $row['is_posted'] && $row['debit_cents'] > 0) {
                $edited = true;
                $row['debit_cents'] += 1;
            }

            return $row;
        },
    ), rehash: true);

    $report = app(BackupVerifier::class)->verify($damaged);

    expect(BackupRoundTrip::checkStatuses($report, 'Table files intact'))->toBe(['pass'])
        ->and(BackupRoundTrip::checkStatuses($report, 'Books balance'))->toBe(['fail'])
        ->and(BackupRoundTrip::checkStatuses($report, 'Trial restore'))->toBe(['fail']);
});

it('catches a broken audit chain, even with the hashes made to match', function () {
    $damaged = BackupRoundTrip::rewrite($this->zip, fn (string $dir) => BackupRoundTrip::editRows(
        $dir,
        'accounting_audit_logs',
        fn (array $row): array => $row['sequence'] === 3 ? ['hash_input' => $row['hash_input'].' '] + $row : $row,
    ), rehash: true);

    $report = app(BackupVerifier::class)->verify($damaged, trialRestore: false);

    expect(BackupRoundTrip::checkStatuses($report, 'Audit chain intact'))->toBe(['fail'])
        ->and(implode(' ', $report->failures()))->toContain('#3 does not match its recorded input');
});

it('catches a row the backup left out that others depend on, even with the hashes made to match', function () {
    $invoicedContactId = Invoice::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->value('contact_id');

    $damaged = BackupRoundTrip::rewrite($this->zip, fn (string $dir) => BackupRoundTrip::editRows(
        $dir,
        'contacts',
        fn (array $row): ?array => $row['id'] === $invoicedContactId ? null : $row,
    ), rehash: true);

    $report = app(BackupVerifier::class)->verify($damaged, trialRestore: false);

    expect(BackupRoundTrip::checkStatuses($report, 'References resolve'))->toContain('fail')
        ->and(implode(' ', $report->failures()))->toContain('invoices.contact_id');
});

it('catches a restore that leaves :dataset at the id it had in the original', function (string $table, string $column) {
    // What restores used to do: a reference to a row restored later kept its
    // source id. Recreate that on the copy and the comparison must object.
    $bundle = BundleReader::open($this->zip);
    $report = new VerificationReport('bundle');
    $injected = 0;

    app(TrialRestore::class)->run($this->zip, function (RestoreTrace $trace) use ($bundle, $report, $table, $column, &$injected): void {
        foreach ($bundle->rows($table) as $row) {
            $copyId = $trace->ids->get($table, $row['id']);
            $sourceValue = is_array($row[$column]) ? json_encode($row[$column]) : $row[$column];

            // Rows the restore left out, and ids that happen to coincide, can't show the bug.
            if ($row[$column] === null || $copyId === null || DB::table($table)->where('id', $copyId)->value($column) == $sourceValue) {
                continue;
            }

            DB::table($table)->where('id', $copyId)->update([$column => $sourceValue]);
            $injected++;
        }

        app(RowComparator::class)->compareRestore($bundle, $trace, $report);
    });
    $bundle->close();

    $differing = array_keys(array_filter($report->tables(), VerificationReport::tableFailed(...)));

    expect($injected)->toBeGreaterThan(0)
        ->and($differing)->toBe([$table])
        ->and($report->tables()[$table]['mismatched'])->toBe($injected)
        ->and($report->tables()[$table]['samples'][0])->toContain("{$column}: expected");
})->with([
    'a journal entry\'s source document' => ['journal_entries', 'source_id'],
    'a voided entry\'s reversal' => ['journal_entries', 'reversed_by_entry_id'],
    'the FIFO layers an issue consumed' => ['stock_movements', 'consumed_layers'],
    'an inbox document\'s item' => ['attachments', 'attachable_id'],
]);

it('catches a restored logo that is still the original organization\'s file', function () {
    // What restores used to do: the copy's logo_path named the source's own
    // file, so removing either organization's logo deleted both.
    $bundle = BundleReader::open($this->zip);
    $report = new VerificationReport('bundle');
    $sourceLogo = $this->company->logo_path;

    app(TrialRestore::class)->run($this->zip, function (RestoreTrace $trace) use ($bundle, $report, $sourceLogo): void {
        Storage::disk(StorageDisks::logos())->put($sourceLogo, 'logo-bytes');
        DB::table('companies')->where('id', $trace->companyId)->update(['logo_path' => $sourceLogo]);

        app(LedgerChecks::class)->check($bundle, $trace, $report);
    });
    $bundle->close();

    expect(BackupRoundTrip::checkStatuses($report, 'Files restored intact'))->toBe(['fail'])
        ->and(implode(' ', $report->failures()))->toContain('logo_path (shares its file with another organization)');
});

it('runs the ledger integrity check on the copy, and fails when it does', function () {
    $bundle = BundleReader::open($this->zip);
    $report = new VerificationReport('bundle');

    app(TrialRestore::class)->run($this->zip, function (RestoreTrace $trace) use ($bundle, $report): void {
        DB::table('accounts')->where('company_id', $trace->companyId)->where('balance_cents', '!=', 0)
            ->orderBy('id')->limit(1)->update(['balance_cents' => DB::raw('balance_cents + 1')]);

        app(LedgerChecks::class)->check($bundle, $trace, $report);
    });
    $bundle->close();

    expect(BackupRoundTrip::checkStatuses($report, 'Ledger integrity'))->toBe(['fail'])
        ->and(BackupRoundTrip::checkStatuses($report, 'Account totals match'))->toBe(['pass']);
});

it('catches a restore that drops, adds or changes a row', function () {
    $bundle = BundleReader::open($this->zip);
    $report = new VerificationReport('bundle');

    app(TrialRestore::class)->run($this->zip, function (RestoreTrace $trace) use ($bundle, $report): void {
        DB::table('invoice_lines')
            ->whereIn('invoice_id', DB::table('invoices')->where('company_id', $trace->companyId)->select('id'))
            ->orderBy('id')
            ->limit(1)
            ->delete();

        DB::table('contacts')->where('company_id', $trace->companyId)->orderBy('id')->limit(1)
            ->update(['email' => 'changed@example.test']);

        $item = DB::table('items')->where('company_id', $trace->companyId)->first();
        DB::table('items')->insert(['name' => 'Not in the backup'] + array_diff_key((array) $item, ['id' => true, 'name' => true]));

        app(RowComparator::class)->compareRestore($bundle, $trace, $report);
    });
    $bundle->close();

    $tables = $report->tables();

    expect($tables['invoice_lines']['missing'])->toBe(1)
        ->and($tables['contacts']['mismatched'])->toBe(1)
        ->and($tables['items']['extra'])->toBe(1)
        ->and($report->passed())->toBeFalse();
});

it('leaves nothing behind after a trial restore', function () {
    $counts = fn (): array => [
        'companies' => Company::query()->withoutGlobalScopes()->withTrashed()->count(),
        'users' => User::query()->count(),
        'restores' => CompanyRestore::query()->count(),
        'attachments' => DB::table('attachments')->count(),
        'journal_lines' => DB::table('journal_lines')->count(),
        'audit' => DB::table('accounting_audit_logs')->count(),
    ];
    $before = $counts();
    $roles = config('filesystems.roles');
    $scratch = glob(storage_path('app/private/backups/verify/*')) ?: [];

    $report = app(BackupVerifier::class)->verify($this->zip);

    expect($report->passed())->toBeTrue(BackupRoundTrip::failures($report))
        ->and($counts())->toBe($before)
        ->and(config('filesystems.roles'))->toBe($roles)
        ->and(glob(storage_path('app/private/backups/verify/*')) ?: [])->toBe($scratch)
        ->and(app()->bound('current_company'))->toBeFalse();
});

it('writes nothing to the database when checking the bundle alone', function () {
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $report = app(BackupVerifier::class)->verify($this->zip, trialRestore: false);

    expect($report->passed())->toBeTrue(BackupRoundTrip::failures($report))
        ->and($writes)->toBe([])
        ->and(BackupRoundTrip::checkStatuses($report, 'Trial restore'))->toBe([]);
});
