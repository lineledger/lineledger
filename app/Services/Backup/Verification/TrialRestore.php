<?php

namespace App\Services\Backup\Verification;

use App\Enums\CompanyRestoreStatus;
use App\Models\CompanyRestore;
use App\Models\User;
use App\Services\Access\PermissionResolver;
use App\Services\Restore\BundleExtractor;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\RestoreTrace;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Restores a bundle for real, inspects the result, then undoes it all.
 *
 * The whole restore runs inside one database transaction that is always
 * rolled back, so nothing it writes is ever committed: no user, scheduled job
 * or other worker can see the copy. Attachment and logo files go to a scratch
 * directory, deleted afterwards. Nobody in the bundle is matched to a user
 * here ({@see RestoreTrace::$matchUsersByEmail}): the copy belongs to a
 * throwaway user, so the restore takes no lock on a real person's account.
 *
 * The transaction does hold its locks until the rollback, so a trial restore
 * of a large organization is best run off-peak.
 */
final class TrialRestore
{
    public function __construct(
        private readonly CompanyImporter $importer,
        private readonly BundleExtractor $files,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(RestoreTrace): T  $inspect  Runs while the copy exists.
     * @return T
     */
    public function run(string $zipPath, Closure $inspect): mixed
    {
        $zipPath = (string) realpath($zipPath);

        if ($zipPath === '' || ! is_file($zipPath)) {
            throw new RuntimeException('Backup file not found.');
        }

        $suffix = Str::lower(Str::random(12));
        $scratchDisk = 'backup_verify_'.$suffix;
        $bundleDisk = 'backup_verify_bundle_'.$suffix;
        $scratchRoot = storage_path('app/private/backups/verify/scratch-'.$suffix);

        if (! @mkdir($scratchRoot, 0700, true) && ! is_dir($scratchRoot)) {
            throw new RuntimeException("Unable to create a working directory at {$scratchRoot}.");
        }

        $roles = [
            'attachments' => config('filesystems.roles.attachments'),
            'logos' => config('filesystems.roles.logos'),
        ];

        config([
            "filesystems.disks.{$scratchDisk}" => ['driver' => 'local', 'root' => $scratchRoot, 'throw' => false],
            // The bundle is read where it lies; nothing is copied.
            "filesystems.disks.{$bundleDisk}" => ['driver' => 'local', 'root' => dirname($zipPath), 'throw' => false],
            'filesystems.roles.attachments' => $scratchDisk,
            'filesystems.roles.logos' => $scratchDisk,
        ]);

        DB::beginTransaction();

        try {
            $user = User::forceCreate([
                'name' => 'Backup verification',
                'email' => 'backup-verify-'.$suffix.'@verify.invalid',
                'password' => Hash::make(Str::random(64)),
            ]);

            $restore = CompanyRestore::create([
                'requested_by_user_id' => $user->id,
                'status' => CompanyRestoreStatus::Pending,
                'disk' => $bundleDisk,
                'file_path' => basename($zipPath),
                'file_size_bytes' => (int) filesize($zipPath),
                'sha256' => (string) hash_file('sha256', $zipPath),
            ]);

            $trace = new RestoreTrace(matchUsersByEmail: false);
            $this->importer->import($restore, $trace);

            return $inspect($trace);
        } finally {
            try {
                DB::rollBack();
            } catch (Throwable) {
                // A deadlock already ended the transaction; nothing to undo.
            }

            config([
                'filesystems.roles.attachments' => $roles['attachments'],
                'filesystems.roles.logos' => $roles['logos'],
            ]);
            Storage::forgetDisk([$scratchDisk, $bundleDisk]);
            $this->files->remove($scratchRoot);

            // Permissions memoised for the throwaway user and organization;
            // SQLite reuses rolled-back ids.
            app(PermissionResolver::class)->flush();
        }
    }
}
