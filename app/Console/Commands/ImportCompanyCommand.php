<?php

namespace App\Console\Commands;

use App\Enums\CompanyRestoreStatus;
use App\Jobs\RestoreCompanyDataJob;
use App\Models\CompanyRestore;
use App\Models\User;
use App\Services\Restore\BundleInspector;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\Exceptions\BundleValidationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operational entry point for the bundle importer. Three modes:
 *
 *   php artisan backup:import /path/to/bundle.zip --user=1            # queue
 *   php artisan backup:import /path/to/bundle.zip --user=1 --sync     # in-process
 *   php artisan backup:import /path/to/bundle.zip --dry-run           # inspect only
 *
 * `--sync` runs the importer in-process so exceptions surface in the shell —
 * what you reach for during smoke testing. `--dry-run` runs the inspector
 * only and dumps the preview as JSON; no CompanyRestore row is created and
 * no DB writes happen.
 */
class ImportCompanyCommand extends Command
{
    protected $signature = 'backup:import
        {file : Absolute path to the backup ZIP}
        {--user= : User id who will own the restored company (required unless --dry-run)}
        {--sync : Run the import synchronously instead of dispatching the queued job}
        {--dry-run : Inspect the bundle and print the preview as JSON without touching the DB}';

    protected $description = 'Restore a Phase 1 backup ZIP into a new Company on this instance.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("Bundle file not found: {$file}");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->runDryRun($file);
        }

        $userOption = $this->option('user');

        if ($userOption === null || $userOption === '') {
            $this->error('--user=<id> is required (omit only with --dry-run).');

            return self::FAILURE;
        }

        $user = User::query()->find((int) $userOption);

        if ($user === null) {
            $this->error("No user with id [{$userOption}].");

            return self::FAILURE;
        }

        // Copy the file into storage/app/private/restores/ so the importer's
        // disk-relative path resolution mirrors the Livewire-upload flow.
        $localDisk = Storage::disk('local');
        $relativePath = 'restores/cli-'.Str::random(8).'-'.basename($file);

        $contents = @file_get_contents($file);
        if ($contents === false) {
            $this->error("Unable to read bundle file: {$file}");

            return self::FAILURE;
        }

        if (! $localDisk->put($relativePath, $contents)) {
            $this->error("Unable to persist bundle into local storage at {$relativePath}.");

            return self::FAILURE;
        }

        $absolutePath = $localDisk->path($relativePath);

        $restore = CompanyRestore::create([
            'requested_by_user_id' => $user->id,
            'status' => CompanyRestoreStatus::Ready,
            'file_path' => $relativePath,
            'file_size_bytes' => $localDisk->size($relativePath),
            'sha256' => hash_file('sha256', $absolutePath),
        ]);

        $this->line(sprintf('Created CompanyRestore #%d for user %d.', $restore->id, $user->id));

        if (! $this->option('sync')) {
            RestoreCompanyDataJob::dispatch($restore->fresh());
            $this->info(sprintf('Restore #%d queued.', $restore->id));

            return self::SUCCESS;
        }

        $restore->forceFill([
            'status' => CompanyRestoreStatus::Pending,
            'started_at' => now(),
        ])->save();

        try {
            app(CompanyImporter::class)->import($restore);
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

        return self::SUCCESS;
    }

    /**
     * Run the bundle inspector and dump the preview as JSON. No DB writes.
     */
    private function runDryRun(string $file): int
    {
        $userOption = $this->option('user');
        $user = $userOption !== null && $userOption !== ''
            ? User::query()->find((int) $userOption)
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
