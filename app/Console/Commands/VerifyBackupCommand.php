<?php

namespace App\Console\Commands;

use App\Console\Concerns\RendersBackupVerification;
use App\Services\Backup\Verification\BackupVerifier;
use Illuminate\Console\Command;

/**
 * Proves an organization backup restores, and restores completely:
 *
 *   php artisan backup:verify /var/backups/lineledger/demo/demo-20261120-020000Z.zip
 *   php artisan backup:verify *.zip --quick     # the bundles alone; no database writes
 *   php artisan backup:verify bundle.zip --json # machine-readable
 *
 * Without `--quick` each bundle is restored for real inside a database
 * transaction that is always rolled back, and every restored row is compared
 * with the backup. Nothing is left behind, but the transaction holds its locks
 * until it ends, so verify a large organization off-peak.
 */
class VerifyBackupCommand extends Command
{
    use RendersBackupVerification;

    protected $signature = 'backup:verify
        {files* : Backup ZIPs to verify}
        {--quick : Only check each backup on its own (files, hashes, references, books, audit chain); skip the trial restore}
        {--json : Print the results as JSON}';

    protected $description = 'Verify organization backups: check each ZIP, restore it in a rolled-back transaction, and compare every row.';

    public function handle(BackupVerifier $verifier): int
    {
        $reports = [];
        $failed = false;

        foreach ((array) $this->argument('files') as $file) {
            $file = (string) $file;

            if (! is_file($file)) {
                $this->error("Backup file not found: {$file}");
                $failed = true;

                continue;
            }

            if (! $this->option('json')) {
                $this->line(sprintf('<options=bold>%s</>', $file));
            }

            $report = $verifier->verify($file, trialRestore: ! $this->option('quick'));
            $reports[] = $report->toArray() + ['file' => $file];
            $failed = $failed || ! $report->passed();

            if (! $this->option('json')) {
                $this->renderVerification($report);
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
