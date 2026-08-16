<?php

namespace App\Jobs;

use App\Enums\CompanyBackupStatus;
use App\Models\CompanyBackup;
use App\Services\Backup\CompanyExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued wrapper around {@see CompanyExporter::export()}.
 *
 * Flips the backup row to Running, binds `current_company` so model
 * accessors that read it during the export still work inside the worker,
 * and delegates the actual orchestration to the service. On failure the
 * service has already persisted the Failed status; {@see failed()} is a
 * belt-and-suspenders guard in case the worker is killed mid-export.
 */
class ExportCompanyDataJob implements ShouldQueue
{
    use Queueable;

    /**
     * Big companies' GL replays can take a while; the export is dominated by
     * journal_lines and stock_movements. 30 minutes covers the largest known
     * tenants by a wide margin without hitting the default queue timeout.
     */
    public int $timeout = 1800;

    /**
     * Backups are idempotent on success but expensive on failure (a half-
     * written ZIP gets cleaned up by the service's finally block). Don't
     * silently retry — let the user retry from the UI if they want.
     */
    public int $tries = 1;

    public function __construct(public CompanyBackup $backup) {}

    public function handle(CompanyExporter $exporter): void
    {
        // Defensive: BelongsToCompany's automatic company_id stamping on
        // creating() looks at the bound `current_company`. The export is
        // read-only against tenant rows, but services we pass through (e.g.
        // model boot callbacks) may still touch it.
        app()->instance('current_company', $this->backup->company);

        $this->backup->forceFill(['status' => CompanyBackupStatus::Running])->save();

        $exporter->export($this->backup);
    }

    public function failed(Throwable $e): void
    {
        // The exporter already records Failed + error_message before
        // re-throwing. This catches the rare case where the worker dies
        // before the exporter's catch block runs (timeout, OOM, etc.).
        $this->backup->forceFill([
            'status' => CompanyBackupStatus::Failed,
            'error_message' => Str::limit($e->getMessage(), 65000),
        ])->save();
    }
}
