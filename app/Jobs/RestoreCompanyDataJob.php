<?php

namespace App\Jobs;

use App\Enums\CompanyRestoreStatus;
use App\Models\CompanyRestore;
use App\Services\Restore\CompanyImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued wrapper around {@see CompanyImporter::import()}.
 *
 * Flips the restore row to Running and delegates to the service. The importer
 * itself owns the transaction, temp-dir lifecycle, and AuditMute scope; this
 * job is purposefully thin so failures bubble up to {@see failed()}.
 */
class RestoreCompanyDataJob implements ShouldQueue
{
    use Queueable;

    /**
     * Restores touch every per-company table and can copy hundreds of
     * attachments — 30 minutes covers the largest known tenants. Matches
     * the export job's timeout for symmetry.
     */
    public int $timeout = 1800;

    /**
     * No silent retries: a partial restore is worse than a failed one, and
     * the importer's transaction guarantees nothing was committed if we
     * reach the catch block. The user can re-upload to retry.
     */
    public int $tries = 1;

    public function __construct(public CompanyRestore $restore) {}

    public function handle(CompanyImporter $importer): void
    {
        // The importer also flips Running, but doing it here means the UI
        // poll sees the transition immediately — without waiting for the
        // extractor to finish.
        $this->restore->forceFill([
            'status' => CompanyRestoreStatus::Running,
            'started_at' => $this->restore->started_at ?? now(),
        ])->save();

        $importer->import($this->restore);
    }

    public function failed(Throwable $e): void
    {
        // Belt-and-suspenders: the importer's catch block already writes
        // Failed + error_message before re-throwing, but if the worker is
        // killed (timeout, OOM) we still flip the row here.
        $this->restore->forceFill([
            'status' => CompanyRestoreStatus::Failed,
            'error_message' => Str::limit($e->getMessage(), 65000),
        ])->save();
    }
}
