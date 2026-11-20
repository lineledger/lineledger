<?php

namespace App\Console\Commands;

use App\Console\Concerns\RendersBackupVerification;
use App\Enums\CompanyRestoreStatus;
use App\Jobs\RestoreCompanyDataJob;
use App\Models\CompanyRestore;
use App\Models\User;
use App\Services\Backup\Verification\BackupVerifier;
use App\Services\Restore\BundleInspector;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\Exceptions\BundleValidationException;
use App\Services\Restore\RestoreTrace;
use App\Support\Storage\StorageDisks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restore an organization backup into a NEW organization on this instance
 * (never over an existing one). Also available as `backup:restore`.
 *
 *   php artisan backup:restore /path/to/bundle.zip --owner=you@example.com --verify
 *       # restore here, then compare every restored row with the backup
 *   php artisan backup:import /path/to/bundle.zip --owner=1            # queue
 *   php artisan backup:import /path/to/bundle.zip --owner=1 --sync     # in-process
 *   php artisan backup:import /path/to/bundle.zip --dry-run            # inspect only
 *
 * `--owner` (or `--user`) takes a user id or email; that user owns the copy.
 * `--sync` runs the importer in-process so exceptions surface in the shell.
 * `--verify` implies `--sync`. `--dry-run` runs the inspector only and dumps
 * the preview as JSON; no CompanyRestore row is created and no DB writes happen.
 */
class ImportCompanyCommand extends Command
{
    use RendersBackupVerification;

    protected $signature = 'backup:import
        {file : Path to the backup ZIP}
        {--owner= : Id or email of the user who will own the restored organization (required unless --dry-run)}
        {--user= : The same as --owner}
        {--sync : Run the import synchronously instead of dispatching the queued job}
        {--verify : Restore synchronously, then compare every restored row with the backup}
        {--dry-run : Inspect the bundle and print the preview as JSON without touching the DB}';

    protected $description = 'Restore an organization backup ZIP into a new organization on this instance.';

    /** @var list<string> */
    protected $aliases = ['backup:restore'];

    public function handle(BackupVerifier $verifier): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("Bundle file not found: {$file}");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->runDryRun($file);
        }

        $userOption = $this->ownerOption();

        if ($userOption === null) {
            $this->error('--owner=<id or email> is required (omit only with --dry-run).');

            return self::FAILURE;
        }

        $user = $this->findUser($userOption);

        if ($user === null) {
            $this->error("No user with id or email [{$userOption}].");

            return self::FAILURE;
        }

        // Stage the bundle where the Livewire-upload flow does — the backups
        // disk — so a queued restore works even when the worker runs on
        // another machine.
        $disk = StorageDisks::backups();
        $relativePath = 'restores/cli-'.Str::random(8).'-'.basename($file);

        // Streamed: a bundle is unbounded in size.
        $stream = @fopen($file, 'rb');
        if ($stream === false) {
            $this->error("Unable to read bundle file: {$file}");

            return self::FAILURE;
        }

        try {
            $stored = Storage::disk($disk)->writeStream($relativePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored === false) {
            $this->error("Unable to persist bundle onto the [{$disk}] disk at {$relativePath}.");

            return self::FAILURE;
        }

        $restore = CompanyRestore::create([
            'requested_by_user_id' => $user->id,
            'status' => CompanyRestoreStatus::Ready,
            'file_path' => $relativePath,
            'disk' => $disk,
            'file_size_bytes' => (int) filesize($file),
            'sha256' => (string) hash_file('sha256', $file),
        ]);

        $this->line(sprintf('Created CompanyRestore #%d for user %d.', $restore->id, $user->id));

        if (! $this->option('sync') && ! $this->option('verify')) {
            RestoreCompanyDataJob::dispatch($restore->fresh());
            $this->info(sprintf('Restore #%d queued.', $restore->id));

            return self::SUCCESS;
        }

        $restore->forceFill([
            'status' => CompanyRestoreStatus::Pending,
            'started_at' => now(),
        ])->save();

        $trace = new RestoreTrace;

        try {
            app(CompanyImporter::class)->import($restore, $trace);
        } catch (Throwable $e) {
            $this->error(sprintf('Restore #%d failed: %s', $restore->id, $e->getMessage()));
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }

        $restore->refresh();
        $companyId = $restore->company_id;
        $companySlug = $companyId !== null ? optional($restore->company)->slug : null;

        $this->info(sprintf(
            'Restore #%d complete. New Company id=%s, slug=%s.',
            $restore->id,
            $companyId !== null ? (string) $companyId : 'n/a',
            $companySlug ?? 'n/a',
        ));

        $stepResults = $restore->step_results ?? [];
        if ($stepResults !== []) {
            $this->line('Step results:');
            foreach ($stepResults as $table => $info) {
                if (! is_array($info)) {
                    continue;
                }

                $inserted = (int) ($info['inserted'] ?? 0);
                $skipped = (int) ($info['skipped'] ?? 0);
                $this->line(sprintf('  %-35s inserted=%d skipped=%d', $table, $inserted, $skipped));
            }
        }

        if (! $this->option('verify')) {
            return self::SUCCESS;
        }

        $this->line('Comparing the restored organization with the backup…');
        $report = $verifier->verifyRestored($file, $trace);
        $this->renderVerification($report);

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function ownerOption(): ?string
    {
        foreach (['owner', 'user'] as $option) {
            $value = $this->option($option);

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * A user by id (all digits) or by email (case-insensitive).
     */
    private function findUser(string $value): ?User
    {
        return ctype_digit($value)
            ? User::query()->find((int) $value)
            : User::query()->whereRaw('LOWER(email) = ?', [strtolower($value)])->first();
    }

    /**
     * Run the bundle inspector and dump the preview as JSON. No DB writes.
     */
    private function runDryRun(string $file): int
    {
        $userOption = $this->ownerOption();
        $user = $userOption !== null
            ? $this->findUser($userOption)
            : User::query()->orderBy('id')->first();

        if ($user === null) {
            $this->error('No user available for dry-run (pass --user=<id> or seed a user first).');

            return self::FAILURE;
        }

        try {
            $preview = app(BundleInspector::class)->inspect($file, $user);
        } catch (BundleValidationException $e) {
            $this->error('Bundle rejected: '.$e->getMessage());

            return self::FAILURE;
        }

        // Don't dump the entire manifest — it's verbose; surface the curated
        // preview shape the UI uses, minus the raw manifest blob.
        $printable = $preview;
        unset($printable['manifest']);

        $this->line((string) json_encode($printable, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
