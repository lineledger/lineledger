<?php

namespace App\Console\Commands;

use App\Enums\CompanyBackupStatus;
use App\Models\CompanyBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Daily retention sweep for per-company backup ZIPs.
 *
 * Phase 1 backups live 7 days after creation (the queued exporter sets
 * `expires_at = now()->addDays(7)` on success). After expiry this command:
 *
 *  - deletes the ZIP on the `local` disk so we don't accumulate gigabytes of
 *    stale customer GL data on the box,
 *  - marks the row Expired with `file_path`, `file_size_bytes`, `sha256`
 *    nulled so the UI shows "expired" instead of a dead download button.
 *
 * The row itself is preserved as an audit trail of "company X requested a
 * backup on date Y" — restore is a Phase 2 concern but the history is useful
 * for support immediately.
 */
class PruneExpiredBackupsCommand extends Command
{
    protected $signature = 'backups:prune-expired';

    protected $description = 'Delete expired company backup ZIPs and mark their rows as Expired.';

    public function handle(): int
    {
        $pruned = 0;
        $bytesFreed = 0;

        CompanyBackup::query()
            ->withoutGlobalScopes()
            ->where(function ($query): void {
                $query->where(function ($q): void {
                    $q->where('status', CompanyBackupStatus::Ready)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', Carbon::now());
                })->orWhere('status', CompanyBackupStatus::Expired);
            })
            ->orderBy('id')
            ->each(function (CompanyBackup $backup) use (&$pruned, &$bytesFreed): void {
                $fileExisted = false;

                // Per row, not hoisted: archives written before a move to object
                // storage still live on the disk they were created on.
                $disk = Storage::disk($backup->storageDisk());

                if ($backup->file_path !== null && $disk->exists($backup->file_path)) {
                    $bytesFreed += (int) $backup->file_size_bytes;
                    $disk->delete($backup->file_path);
                    $fileExisted = true;
                }

                $alreadyClean = $backup->status === CompanyBackupStatus::Expired
                    && $backup->file_path === null
                    && $backup->file_size_bytes === null
                    && $backup->sha256 === null;

                if ($alreadyClean) {
                    return;
                }

                $backup->forceFill([
                    'status' => CompanyBackupStatus::Expired,
                    'file_path' => null,
                    'file_size_bytes' => null,
                    'sha256' => null,
                ])->save();

                if ($fileExisted || $backup->wasChanged()) {
                    $pruned++;
                }
            });

        $this->info(sprintf(
            'Pruned %d backup(s); freed %s bytes.',
            $pruned,
            number_format($bytesFreed),
        ));

        return self::SUCCESS;
    }
}
