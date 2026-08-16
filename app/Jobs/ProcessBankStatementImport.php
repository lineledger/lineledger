<?php

namespace App\Jobs;

use App\Enums\BankStatementImportStatus;
use App\Models\BankStatementImport;
use App\Models\Company;
use App\Services\Banking\Import\DTO\ColumnMapping;
use App\Services\Banking\Import\StatementImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Parses and auto-matches an uploaded bank statement off the request cycle (PDF
 * extraction and any AI mapping can be slow). Binds `current_company` so tenant
 * scoping and company_id assignment behave exactly as in a request. The wizard
 * polls the import's status and advances when it reaches Ready / NeedsMapping.
 */
class ProcessBankStatementImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>|null  $mapping  a confirmed column mapping from the wizard
     */
    public function __construct(
        public int $importId,
        public ?array $mapping = null,
    ) {}

    public function handle(StatementImportProcessor $processor): void
    {
        $import = BankStatementImport::withoutGlobalScopes()->findOrFail($this->importId);
        $company = Company::query()->findOrFail($import->company_id);

        // Save any caller-bound tenant so the sync queue (tests / sync-mode prod) does
        // not strip the request's current_company; restore it when we are done.
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            $mapping = $this->mapping !== null ? ColumnMapping::fromArray($this->mapping) : null;
            $processor->process($import, $mapping);
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }

    public function failed(Throwable $e): void
    {
        BankStatementImport::withoutGlobalScopes()
            ->whereKey($this->importId)
            ->update([
                'status' => BankStatementImportStatus::Failed->value,
                'error_message' => $e->getMessage(),
            ]);
    }
}
