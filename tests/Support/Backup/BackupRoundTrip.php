<?php

namespace Tests\Support\Backup;

use App\Actions\Companies\PurgeCompany;
use App\Enums\CompanyRestoreStatus;
use App\Models\Company;
use App\Models\CompanyRestore;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use App\Services\Backup\Verification\BackupVerifier;
use App\Services\Backup\Verification\BundleReader;
use App\Services\Backup\Verification\RowComparator;
use App\Services\Backup\Verification\VerificationReport;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\RestoreTrace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Backup → restore plumbing for tests: bundles land on the (faked) `local`
 * disk, so each test's files go when the next one fakes the disk again.
 */
final class BackupRoundTrip
{
    public static function company(string $slug): Company
    {
        return Company::query()->withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
    }

    /**
     * The full proof for one organization (see DemoRoundTripTest): its backup
     * is complete, verifies, restores exactly next to it, backs up again, and
     * restores onto an instance without it or its people. Purges `$company`.
     */
    public static function assertCompleteRoundTrip(Company $company): void
    {
        $owner = $company->owner();
        $zip = self::bundle($company);

        // 1. The backup is complete.
        $export = self::compareExport($zip, $company);
        expect($export->passed())->toBeTrue('Backup incomplete:'.self::failures($export));

        // 2. The verifier passes it.
        $verified = app(BackupVerifier::class)->verify($zip);
        expect($verified->passed())->toBeTrue('Verification failed:'.self::failures($verified));

        // 3. A real restore next to the original comes back exactly.
        $trace = self::restore($zip, $owner);
        $copy = Company::query()->withoutGlobalScopes()->findOrFail($trace->companyId);

        $restored = app(BackupVerifier::class)->verifyRestored($zip, $trace);
        expect($restored->passed())->toBeTrue('Restore differs:'.self::failures($restored))
            ->and($copy->id)->not->toBe($company->id)
            ->and(self::trialBalance($copy))->toBe(self::trialBalance($company))
            ->and(self::trialBalance($copy))->not->toBe([]);

        // 4. The copy backs up and verifies in turn.
        $secondGeneration = app(BackupVerifier::class)->verify(self::bundle($copy, $owner));
        expect($secondGeneration->passed())->toBeTrue('Second-generation backup failed:'.self::failures($secondGeneration));

        // 5. Onto an instance without the original or its people.
        $company->delete();
        app(PurgeCompany::class)->handle(Company::withTrashed()->findOrFail($company->id));

        $stranger = User::factory()->create();
        $elsewhere = self::restore($zip, $stranger, matchUsersByEmail: false);
        $elsewhereReport = app(BackupVerifier::class)->verifyRestored($zip, $elsewhere);

        expect($elsewhereReport->passed())->toBeTrue('Restore onto another instance differs:'.self::failures($elsewhereReport))
            ->and(Company::query()->withoutGlobalScopes()->findOrFail($elsewhere->companyId)->owner()?->is($stranger))->toBeTrue();
    }

    /**
     * Back `$company` up and return the bundle's local path.
     */
    public static function bundle(Company $company, ?User $exportedBy = null): string
    {
        $path = Storage::disk('local')->path('test-bundles/'.$company->slug.'-'.Str::random(8).'.zip');

        app(CompanyExporter::class)->buildBundle(
            $company,
            $exportedBy ?? $company->owner() ?? User::query()->firstOrFail(),
            $path,
        );

        return $path;
    }

    /**
     * Restore the bundle at `$zipPath` for real, owned by `$owner`; throws if
     * the restore fails.
     */
    public static function restore(string $zipPath, User $owner, bool $matchUsersByEmail = true): RestoreTrace
    {
        $relative = 'test-restores/'.Str::random(8).'.zip';
        Storage::disk('local')->put($relative, (string) file_get_contents($zipPath));

        $restore = CompanyRestore::create([
            'requested_by_user_id' => $owner->id,
            'status' => CompanyRestoreStatus::Pending,
            'disk' => 'local',
            'file_path' => $relative,
            'file_size_bytes' => (int) filesize($zipPath),
            'sha256' => (string) hash_file('sha256', $zipPath),
        ]);

        $previous = app()->bound('current_company') ? app('current_company') : null;
        $trace = new RestoreTrace($matchUsersByEmail);

        try {
            app(CompanyImporter::class)->import($restore, $trace);
        } finally {
            $previous !== null ? app()->instance('current_company', $previous) : app()->forgetInstance('current_company');
        }

        return $trace;
    }

    /**
     * Compare a live organization with its own bundle: every row and column.
     */
    public static function compareExport(string $zipPath, Company $company): VerificationReport
    {
        $report = new VerificationReport(basename($zipPath));
        $bundle = BundleReader::open($zipPath);

        try {
            app(RowComparator::class)->compareExport($bundle, (int) $company->id, $report);
        } finally {
            $bundle->close();
        }

        return $report;
    }

    /**
     * Posted debits and credits per account code: the trial balance, keyed
     * by something a restore keeps.
     *
     * @return array<string, array{debit: int, credit: int}>
     */
    public static function trialBalance(Company $company): array
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.company_id', $company->id)
            ->where('journal_lines.is_posted', true)
            ->groupBy('accounts.code')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.code, SUM(journal_lines.debit_cents) AS debit, SUM(journal_lines.credit_cents) AS credit')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->code => ['debit' => (int) $row->debit, 'credit' => (int) $row->credit]])
            ->all();
    }

    /**
     * A copy of the bundle at `$zipPath`, changed by `$change` (given the
     * unpacked bundle's directory). With `$rehash` each table's sha256 and row
     * count in the manifest are brought back in line, so only checks deeper
     * than the hashes can notice the change.
     *
     * @param  \Closure(string): void  $change
     */
    public static function rewrite(string $zipPath, \Closure $change, bool $rehash = false): string
    {
        $dir = Storage::disk('local')->path('test-rewrites/'.Str::random(8));
        mkdir($dir, 0700, true);

        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $zip->extractTo($dir);
        $zip->close();

        $change($dir);

        if ($rehash) {
            $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

            foreach (array_keys($manifest['tables']) as $table) {
                $file = $dir.'/data/'.$table.'.jsonl';

                if (is_file($file)) {
                    $manifest['tables'][$table]['sha256'] = hash_file('sha256', $file);
                    $manifest['tables'][$table]['rows'] = count(array_filter(explode("\n", (string) file_get_contents($file))));
                }
            }

            file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $out = $dir.'.zip';
        $zip = new \ZipArchive;
        $zip->open($out, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($dir) + 1));
        }

        $zip->close();

        return $out;
    }

    /**
     * Rewrite one table's rows in an unpacked bundle.
     *
     * @param  \Closure(array<string, mixed>): ?array<string, mixed>  $change  Return null to drop the row.
     */
    public static function editRows(string $dir, string $table, \Closure $change): void
    {
        $file = $dir.'/data/'.$table.'.jsonl';
        $out = [];

        foreach (array_filter(explode("\n", (string) file_get_contents($file))) as $line) {
            $row = $change(json_decode($line, true));

            if ($row !== null) {
                $out[] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        file_put_contents($file, $out === [] ? '' : implode("\n", $out)."\n");
    }

    /**
     * The statuses a report gave the named check, in order.
     *
     * @return list<string>
     */
    public static function checkStatuses(VerificationReport $report, string $name): array
    {
        return array_values(array_map(
            static fn (array $check): string => $check['status'],
            array_filter($report->checks(), static fn (array $check): bool => $check['name'] === $name),
        ));
    }

    /**
     * The report's failures, for an assertion message.
     */
    public static function failures(VerificationReport $report): string
    {
        return "\n".implode("\n", $report->failures());
    }
}
