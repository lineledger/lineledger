<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\CompanyBackup;
use App\Support\Storage\StorageDisks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Audits where uploaded files actually go, and proves it by round-tripping a
 * throwaway object through every configured disk.
 *
 * Most storage misconfigurations are invisible until a user hits them: an IAM
 * policy missing DeleteObject only breaks when someone removes an attachment; a
 * missing bucket policy only breaks when a customer opens the payment portal.
 * The two that matter most cannot be answered by reading config at all —
 * whether the *public* bucket really serves its objects, and whether the
 * *private* bucket really doesn't — so both are probed over plain HTTP with no
 * credentials attached.
 *
 * Safe to run against production: it writes one small object per disk under
 * `.lineledger-storage-check/` and deletes it again.
 */
class CheckStorageConfigCommand extends Command
{
    protected $signature = 'storage:check
        {--skip-probes : Only report configuration; skip the write/read/delete and public-exposure probes}';

    protected $description = 'Verify the attachment / logo / backup storage configuration, and prove each disk works';

    private const PROBE_PREFIX = '.lineledger-storage-check';

    private int $failures = 0;

    private int $warnings = 0;

    /** @var list<string> Disks whose credentials have already been reported on. */
    private array $credentialsChecked = [];

    public function handle(): int
    {
        $roles = $this->resolveRoles();

        if ($roles === null) {
            return Command::FAILURE;
        }

        $this->newLine();
        $this->components->info('Storage roles');

        foreach ($roles as $role => $disk) {
            $this->reportRole($role, $disk);
        }

        if (! $this->option('skip-probes')) {
            $this->components->info('Round trip');

            foreach (array_unique($roles) as $disk) {
                $this->probeDisk($disk, shouldBePublic: $disk === $roles['logos']);
            }
        }

        $this->components->info('Supporting checks');

        $this->checkTemporaryUploadDisk();
        $this->checkPresignedUrls($roles['backups']);
        $this->checkRegionMatchesAppRegion($roles);
        $this->checkForRowsOnUnknownDisks();

        return $this->summarize();
    }

    /**
     * @return array{attachments: string, logos: string, backups: string}|null
     */
    private function resolveRoles(): ?array
    {
        try {
            return [
                'attachments' => StorageDisks::attachments(),
                'logos' => StorageDisks::logos(),
                'backups' => StorageDisks::backups(),
            ];
        } catch (Throwable $e) {
            // A role pointing at an undefined disk is fatal for everything that
            // follows, so bail rather than cascade confusing errors.
            $this->newLine();
            $this->components->error($e->getMessage());
            $this->components->warn('Fix ATTACHMENT_DISK / LOGO_DISK / BACKUP_DISK before re-running.');

            return null;
        }
    }

    private function reportRole(string $role, string $disk): void
    {
        $driver = (string) config("filesystems.disks.{$disk}.driver");

        if ($driver !== 's3') {
            $this->components->twoColumnDetail($role, sprintf('%s (%s)', $disk, $driver));

            return;
        }

        $bucket = (string) config("filesystems.disks.{$disk}.bucket");
        $region = (string) config("filesystems.disks.{$disk}.region");

        $this->components->twoColumnDetail($role, sprintf(
            '%s → s3://%s @ %s',
            $disk,
            $bucket !== '' ? $bucket : '<no bucket>',
            $region !== '' ? $region : '<no region>',
        ));

        // Two roles routinely share one disk (attachments + backups), and
        // repeating an identical credential complaint just buries the others.
        if (in_array($disk, $this->credentialsChecked, true)) {
            return;
        }

        $this->credentialsChecked[] = $disk;

        $missing = [];

        foreach (['key' => 'AWS_ACCESS_KEY_ID', 'secret' => 'AWS_SECRET_ACCESS_KEY'] as $key => $env) {
            if (blank(config("filesystems.disks.{$disk}.{$key}"))) {
                $missing[] = $env;
            }
        }

        if ($bucket === '') {
            $missing[] = $disk === 's3_public' ? 'AWS_PUBLIC_BUCKET' : 'AWS_BUCKET';
        }

        if ($missing !== []) {
            $this->reportFailure(sprintf('%s is missing %s', $disk, implode(', ', $missing)));
        }
    }

    /**
     * Write, read back, and delete a throwaway object. This is the only way to
     * tell a correct IAM policy from one that merely looks correct.
     */
    private function probeDisk(string $disk, bool $shouldBePublic): void
    {
        $path = self::PROBE_PREFIX.'/'.Str::ulid().'.txt';
        $payload = 'lineledger storage check '.Str::ulid();

        try {
            $filesystem = Storage::disk($disk);
        } catch (Throwable $e) {
            $this->reportFailure(sprintf('%s cannot be constructed: %s', $disk, $this->brief($e)));

            return;
        }

        try {
            $filesystem->put($path, $payload);
        } catch (Throwable $e) {
            $this->reportFailure(sprintf('%s write failed: %s', $disk, $this->brief($e)));

            return;
        }

        try {
            if ($filesystem->get($path) !== $payload) {
                $this->reportFailure(sprintf('%s read back different bytes than were written', $disk));
            } else {
                $this->pass($disk, 'write + read');
            }

            $this->probePublicExposure($disk, $path, $shouldBePublic);
        } finally {
            try {
                $filesystem->delete($path);

                if ($filesystem->exists($path)) {
                    $this->reportFailure(sprintf('%s delete reported success but the object is still there', $disk));
                }
            } catch (Throwable $e) {
                $this->reportFailure(sprintf('%s delete failed (leftover: %s): %s', $disk, $path, $this->brief($e)));
            }
        }
    }

    /**
     * The check that config alone cannot answer: fetch the object over plain
     * HTTP with no credentials. Logos must be readable; attachments and backups
     * must not be.
     */
    private function probePublicExposure(string $disk, string $path, bool $shouldBePublic): void
    {
        if (config("filesystems.disks.{$disk}.driver") !== 's3') {
            return;
        }

        try {
            $url = Storage::disk($disk)->url($path);
        } catch (Throwable $e) {
            $this->warnAbout(sprintf('%s: could not build a URL to test exposure (%s)', $disk, $this->brief($e)));

            return;
        }

        // A relative URL here means the disk's `url` is set but blank. The S3
        // adapter gates on isset(), so '' is treated as a base URL and every
        // link comes out relative — logos would 404 on the app's own domain.
        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $this->reportFailure(sprintf(
                '%s produced the relative URL [%s]. Its `url` is set to an empty value — '
                .'clear %s from your .env (or give it a full https:// base).',
                $disk,
                $url,
                $disk === 's3_public' ? 'AWS_PUBLIC_URL' : 'AWS_URL',
            ));

            return;
        }

        try {
            $status = Http::withOptions(['allow_redirects' => false])
                ->timeout(10)
                ->get($url)
                ->status();
        } catch (Throwable $e) {
            $this->warnAbout(sprintf('%s: exposure probe could not reach the bucket (%s)', $disk, $this->brief($e)));

            return;
        }

        $isPublic = $status === 200;

        if ($shouldBePublic && ! $isPublic) {
            $this->reportFailure(sprintf(
                '%s is NOT publicly readable (HTTP %d) — logos will not load. Add a bucket policy granting s3:GetObject.',
                $disk,
                $status,
            ));

            return;
        }

        if (! $shouldBePublic && $isPublic) {
            $this->reportFailure(sprintf(
                '%s IS PUBLICLY READABLE — attachments/backups are world-downloadable. Re-enable Block Public Access.',
                $disk,
            ));

            return;
        }

        $this->pass($disk, $shouldBePublic ? 'publicly readable (as intended)' : 'not publicly readable');
    }

    /**
     * Livewire stages uploads before the app moves them. Several importers read
     * the staged file with getRealPath(), which only exists on a local disk.
     */
    private function checkTemporaryUploadDisk(): void
    {
        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

        if (! is_string($disk) || ! StorageDisks::isLocal($disk)) {
            $this->reportFailure(sprintf(
                'Livewire temp uploads are on [%s]; CSV, chart-of-accounts and migration imports need a local disk.',
                is_string($disk) ? $disk : gettype($disk),
            ));

            return;
        }

        $this->pass('livewire temp', sprintf('staged locally (%s)', $disk));
    }

    private function checkPresignedUrls(string $backupDisk): void
    {
        if (StorageDisks::isLocal($backupDisk)) {
            $this->pass('backup download', 'served directly (local disk)');

            return;
        }

        try {
            Storage::disk($backupDisk)->temporaryUrl(self::PROBE_PREFIX.'/probe.zip', now()->addMinutes(5));
            $this->pass('backup download', 'can be presigned');
        } catch (Throwable $e) {
            $this->reportFailure('Backup disk cannot presign URLs, so downloads will fail: '.$this->brief($e));
        }
    }

    /**
     * Data residency: a Canadian deployment storing files in us-east-1 is a
     * compliance problem, not a performance one.
     *
     * @param  array<string, string>  $roles
     */
    private function checkRegionMatchesAppRegion(array $roles): void
    {
        $expected = match (strtoupper((string) config('app.region'))) {
            'CA' => 'ca-central-1',
            default => null, // US has several equally valid regions; nothing to assert.
        };

        if ($expected === null) {
            return;
        }

        foreach (array_unique($roles) as $disk) {
            if (config("filesystems.disks.{$disk}.driver") !== 's3') {
                continue;
            }

            $region = (string) config("filesystems.disks.{$disk}.region");

            if ($region !== $expected) {
                $this->warnAbout(sprintf(
                    '%s is in %s but APP_REGION=CA — Canadian customer data should sit in %s.',
                    $disk,
                    $region !== '' ? $region : 'an unset region',
                    $expected,
                ));
            }
        }
    }

    /**
     * A row whose recorded disk no longer exists in config is a file the app can
     * never serve again — the usual cause is renaming a disk instead of adding one.
     */
    private function checkForRowsOnUnknownDisks(): void
    {
        $configured = array_keys((array) config('filesystems.disks'));
        $orphans = [];

        $sources = [
            'attachments' => fn () => Attachment::query()->withoutGlobalScopes(),
            'company_backups' => fn () => CompanyBackup::query()->withoutGlobalScopes()->whereNotNull('file_path'),
        ];

        foreach ($sources as $table => $makeQuery) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'disk')) {
                continue;
            }

            // Grouped count rather than loading rows: `attachments` can be large.
            // `count(*)` is portable across the MySQL/SQLite split.
            $counts = $makeQuery()
                ->select(['disk'])
                ->selectRaw('count(*) as total')
                ->groupBy('disk')
                ->pluck('total', 'disk');

            foreach ($counts as $disk => $total) {
                $name = (string) $disk;

                // An empty value predates the column and means `local`.
                if ($name === '' || in_array($name, $configured, true)) {
                    continue;
                }

                $orphans[] = sprintf('%s: %d row(s) reference undefined disk [%s]', $table, $total, $name);
            }
        }

        if ($orphans === []) {
            $this->pass('stored files', 'all point at a disk that still exists');

            return;
        }

        foreach ($orphans as $orphan) {
            $this->reportFailure($orphan);
        }
    }

    private function pass(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, '<fg=green>'.$detail.'</>');
    }

    private function reportFailure(string $message): void
    {
        $this->components->error($message);
        $this->failures++;
    }

    private function warnAbout(string $message): void
    {
        $this->components->warn($message);
        $this->warnings++;
    }

    private function brief(Throwable $e): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 200);
    }

    private function summarize(): int
    {
        if ($this->failures > 0) {
            $this->components->error(sprintf(
                '%d problem(s)%s found.',
                $this->failures,
                $this->warnings > 0 ? sprintf(' and %d warning(s)', $this->warnings) : '',
            ));

            return Command::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->components->warn(sprintf('Storage is usable, with %d warning(s).', $this->warnings));

            return Command::SUCCESS;
        }

        $this->components->success('Storage configuration checks out.');

        return Command::SUCCESS;
    }
}
