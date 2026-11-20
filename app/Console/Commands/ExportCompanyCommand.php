<?php

namespace App\Console\Commands;

use App\Console\Concerns\RendersBackupVerification;
use App\Console\Concerns\ResolvesCompanyArgument;
use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Jobs\ExportCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\Membership;
use App\Services\Backup\CompanyExporter;
use App\Services\Backup\Verification\BackupVerifier;
use App\Support\Storage\TemporaryLocalFile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Back up organizations, each to its own ZIP bundle (the same bundle the
 * Settings → Backup & Export page makes, restorable with `backup:import`).
 *
 * Two ways to keep the result:
 *
 *   php artisan backup:export demo                    # in-app: listed under Settings → Backup & Export
 *   php artisan backup:export demo --sync             #   for 7 days, made by the queue (or here, --sync)
 *
 *   php artisan backup:export --all --output=/var/backups/lineledger --keep=14 --verify
 *       # into a directory, for a nightly cron: <dir>/<slug>/<slug>-<UTC timestamp>Z.zip
 *       # plus a .sha256 beside each; --keep prunes each organization's oldest;
 *       # --verify proves each new backup restores completely.
 *
 * Organizations are named by id or slug, as arguments or `--company=` (kept
 * for older scripts). Exits non-zero if any backup (or its verification)
 * fails, so a scheduler can alert on it.
 */
class ExportCompanyCommand extends Command
{
    use RendersBackupVerification;
    use ResolvesCompanyArgument;

    protected $signature = 'backup:export
        {companies?* : Organization id or slug (several allowed)}
        {--company= : Organization id or slug (the same as naming it as an argument)}
        {--all : Every organization (except deleted ones)}
        {--output= : Write each backup as a ZIP into this directory, rather than to the in-app backup list (runs here, synchronously)}
        {--keep= : With --output, keep only this many of each organization\'s newest backups}
        {--verify : Verify each new backup: check the ZIP, restore it in a rolled-back transaction and compare every row}
        {--sync : Without --output, make the in-app backup here instead of queueing it}';

    protected $description = 'Back up organizations to ZIP bundles: into the in-app backup list, or into a directory (for a nightly cron).';

    /** Timestamped bundle names: <slug>-YYYYmmdd-HHMMSSZ[-n].zip */
    private const NAME_PATTERN = '/^%s-\d{8}-\d{6}Z(-\d+)?\.zip$/';

    public function handle(CompanyExporter $exporter, BackupVerifier $verifier): int
    {
        $output = $this->option('output');
        $keep = $this->option('keep');

        if ($keep !== null && ($output === null || ! ctype_digit((string) $keep) || (int) $keep < 1)) {
            $this->error('--keep needs --output and a whole number of at least 1.');

            return self::FAILURE;
        }

        if ($this->option('verify') && $output === null && ! $this->option('sync')) {
            $this->error('--verify needs --output or --sync: a queued backup isn\'t finished when this command ends.');

            return self::FAILURE;
        }

        $companies = $this->companies();

        if ($companies === null) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($companies as $company) {
            $ok = $output !== null
                ? $this->backupToDirectory($company, (string) $output, $keep === null ? null : (int) $keep, $exporter, $verifier)
                : $this->backupInApp($company, $exporter, $verifier);

            $failed = $failed || ! $ok;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The organizations named, or null (after an error) when there are none
     * or one can't be found.
     *
     * @return list<Company>|null
     */
    private function companies(): ?array
    {
        $names = array_values(array_filter(array_merge(
            (array) $this->argument('companies'),
            $this->option('company') !== null ? [(string) $this->option('company')] : [],
        ), static fn (string $name): bool => $name !== ''));

        if ($this->option('all')) {
            if ($names !== []) {
                $this->error('Name organizations or pass --all, not both.');

                return null;
            }

            // Deleted organizations (awaiting purge) are left out; name one to back it up.
            $companies = Company::query()->orderBy('id')->get()->all();

            if ($companies === []) {
                $this->warn('There are no organizations to back up.');
            }

            return $companies;
        }

        if ($names === []) {
            $this->error('Name an organization (id or slug), or pass --all.');

            return null;
        }

        $companies = [];

        foreach ($names as $name) {
            $company = $this->whereCompanyArgument(Company::query()->withoutGlobalScopes(), $name)->first();

            if ($company === null) {
                $this->error("No organization with id or slug [{$name}].");

                return null;
            }

            $companies[$company->id] = $company;
        }

        return array_values($companies);
    }

    /**
     * Write the backup into `<directory>/<slug>/`, atomically (a partial file
     * is never left under the final name), readable by its owner only, with a
     * `sha256sum`-format checksum beside it.
     */
    private function backupToDirectory(Company $company, string $directory, ?int $keep, CompanyExporter $exporter, BackupVerifier $verifier): bool
    {
        $owner = $company->owner();

        if ($owner === null) {
            $this->error(sprintf('%s: has no owner to stamp on the backup; skipped.', $company->slug));

            return false;
        }

        $folder = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$company->slug;

        if (! is_dir($folder) && ! @mkdir($folder, 0700, true) && ! is_dir($folder)) {
            $this->error(sprintf('%s: cannot create the directory %s.', $company->slug, $folder));

            return false;
        }

        $stamp = CarbonImmutable::now('UTC')->format('Ymd-His').'Z';
        $name = "{$company->slug}-{$stamp}.zip";

        for ($n = 2; file_exists($folder.DIRECTORY_SEPARATOR.$name); $n++) {
            $name = "{$company->slug}-{$stamp}-{$n}.zip";
        }

        $final = $folder.DIRECTORY_SEPARATOR.$name;
        $partial = $folder.DIRECTORY_SEPARATOR.'.'.$name.'.partial';

        try {
            $bundle = $exporter->buildBundle($company, $owner, $partial);

            @chmod($partial, 0600);

            if (! @rename($partial, $final)) {
                throw new RuntimeException("Unable to move the finished backup to {$final}.");
            }

            file_put_contents($final.'.sha256', $bundle['sha256'].'  '.$name.PHP_EOL);
            @chmod($final.'.sha256', 0600);
        } catch (Throwable $e) {
            @unlink($partial);
            $this->error(sprintf('%s: backup failed: %s', $company->slug, $e->getMessage()));

            return false;
        }

        $this->info(sprintf(
            '%s: %s (%s bytes, %d rows, sha256 %s).',
            $company->slug,
            $final,
            number_format($bundle['bytes']),
            array_sum($bundle['row_counts']),
            $bundle['sha256'],
        ));

        if ($this->option('verify')) {
            $report = $verifier->verify($final);
            $this->renderVerification($report);

            if (! $report->passed()) {
                // Keep the older backups: this one may not be restorable.
                return false;
            }
        }

        if ($keep !== null) {
            $this->prune($company->slug, $folder, $keep);
        }

        return true;
    }

    /**
     * Delete all but the newest `$keep` of an organization's backups in its
     * folder (and their checksums). Only files this command named are touched.
     */
    private function prune(string $slug, string $folder, int $keep): void
    {
        $pattern = sprintf(self::NAME_PATTERN, preg_quote($slug, '/'));

        $backups = array_values(array_filter(
            array_map('basename', glob($folder.DIRECTORY_SEPARATOR.'*.zip') ?: []),
            static fn (string $name): bool => preg_match($pattern, $name) === 1,
        ));

        // The UTC timestamp in the name sorts oldest to newest.
        sort($backups);

        foreach (array_slice($backups, 0, max(0, count($backups) - $keep)) as $old) {
            @unlink($folder.DIRECTORY_SEPARATOR.$old);
            @unlink($folder.DIRECTORY_SEPARATOR.$old.'.sha256');
            $this->line(sprintf('%s: removed old backup %s.', $slug, $old));
        }
    }

    /**
     * The in-app backup: a CompanyBackup row the Settings page lists, made by
     * the queue, or here with --sync.
     */
    private function backupInApp(Company $company, CompanyExporter $exporter, BackupVerifier $verifier): bool
    {
        $ownerUserId = Membership::query()
            ->where('company_id', $company->id)
            ->where('role', CompanyRole::Owner)
            ->value('user_id');

        // BelongsToCompany stamps company_id from app('current_company') on
        // creating() — bind it so the row lands on the right tenant.
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            $backup = CompanyBackup::create([
                'status' => CompanyBackupStatus::Pending,
                'requested_by_user_id' => $ownerUserId,
                'app_version' => config('version.app'),
                'schema_version' => config('version.schema'),
            ]);
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }

        $this->line(sprintf('Created CompanyBackup #%d for %s.', $backup->id, $company->slug));

        if (! $this->option('sync')) {
            ExportCompanyDataJob::dispatch($backup);
            $this->info(sprintf('Backup #%d queued.', $backup->id));

            return true;
        }

        $backup->forceFill(['status' => CompanyBackupStatus::Running])->save();

        try {
            $exporter->export($backup);
        } catch (Throwable $e) {
            $this->error(sprintf('Backup #%d failed: %s', $backup->id, $e->getMessage()));

            return false;
        }

        $backup->refresh();

        $this->info(sprintf(
            'Backup #%d ready: %s (%s bytes, sha256 %s).',
            $backup->id,
            $backup->file_path ?? 'unknown',
            number_format((int) $backup->file_size_bytes),
            $backup->sha256 ?? 'n/a',
        ));

        if (! $this->option('verify')) {
            return true;
        }

        $report = TemporaryLocalFile::with(
            $backup->storageDisk(),
            (string) $backup->file_path,
            fn (string $path) => $verifier->verify($path),
        );
        $this->renderVerification($report);

        return $report->passed();
    }
}
