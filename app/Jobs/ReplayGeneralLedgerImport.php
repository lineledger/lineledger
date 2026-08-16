<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Services\Migration\ContactLinkBackfiller;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\GeneralLedgerReplayImporter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Replays one or more (potentially large) QuickBooks Desktop transaction exports
 * into the general ledger off the request cycle, writing live progress into the
 * run's step_results so the wizard can poll it. Transactions keep their own dates
 * so the ledger is chronological regardless of file order, and shared idempotency
 * dedupes overlapping files. Source files live on the local disk for the duration
 * of the job and are deleted when it finishes.
 */
class ReplayGeneralLedgerImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /**
     * @param  list<string>  $storedPaths
     * @param  ?string  $accountTypesPath  stored path to a QuickBooks Account Listing CSV used to type auto-created accounts (CSV replays only)
     */
    public function __construct(
        public int $companyId,
        public int $runId,
        public array $storedPaths,
        public string $sourceFormat,
        public bool $autoCreateAccounts,
        public bool $linkContactNames,
        public bool $reconstructDocuments = false,
        public ?string $accountTypesPath = null,
    ) {}

    public function handle(GeneralLedgerReplayImporter $importer, ContactLinkBackfiller $backfiller): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        $run = DataMigrationRun::withoutGlobalScopes()->findOrFail($this->runId);

        $absolutePaths = array_map(fn (string $p) => Storage::disk('local')->path($p), $this->storedPaths);

        $this->patchProgress($run, ['status' => 'running', 'progress' => 0]);

        $ctx = new ImportContext(
            company: $company,
            run: $run,
            conversionDate: CarbonImmutable::parse($run->conversion_date),
            sourceFormat: $this->sourceFormat,
            autoCreateAccounts: $this->autoCreateAccounts,
            linkContactNames: $this->linkContactNames,
            reconstructDocuments: $this->reconstructDocuments,
            accountTypesPath: $this->accountTypesPath !== null
                ? Storage::disk('local')->path($this->accountTypesPath)
                : null,
        );

        try {
            $result = $importer->commit(
                $absolutePaths,
                $ctx,
                onProgress: fn (int $processed) => $this->patchProgress($run, ['status' => 'running', 'progress' => $processed]),
            );
        } catch (Throwable $e) {
            $this->patchProgress($run, ['status' => 'failed', 'message' => $e->getMessage()]);
            $this->cleanup();

            throw $e;
        }

        $this->cleanup();

        $committed = (int) ($result->summary['committed'] ?? 0);

        // Nothing imported at all → a real failure the user must fix.
        if ($committed === 0 && $result->hasErrors()) {
            $this->patchProgress($run, [
                'status' => 'failed',
                'progress' => 0,
                'errors' => array_slice($result->errors, 0, 100),
                'summary' => $result->summary,
            ]);

            return;
        }

        // Reconstruction resolves a document's customer/vendor separately from the GL replay's
        // name match, so a control-account line can be left untagged. Tag those from the document
        // they belong to, so each customer's GL-driven statement matches their aging.
        if ($this->reconstructDocuments) {
            $backfiller->backfill($this->companyId);
        }

        // Some transactions imported. Complete the step even if a few rows were
        // skipped (e.g. a truncated file's last entry) — surface those as warnings.
        $run->recordStepResult('general_ledger', array_merge($result->summary, [
            'status' => 'done',
            'warnings' => array_slice($result->errors, 0, 100),
        ]));
    }

    protected function cleanup(): void
    {
        foreach ($this->storedPaths as $path) {
            Storage::disk('local')->delete($path);
        }

        if ($this->accountTypesPath !== null) {
            Storage::disk('local')->delete($this->accountTypesPath);
        }
    }

    /**
     * Merge a patch into step_results['general_ledger'] without marking the step complete.
     *
     * @param  array<string, mixed>  $patch
     */
    protected function patchProgress(DataMigrationRun $run, array $patch): void
    {
        $run->refresh();
        $results = $run->step_results ?? [];
        $results['general_ledger'] = array_merge($results['general_ledger'] ?? [], $patch);
        $run->forceFill(['step_results' => $results])->save();
    }
}
