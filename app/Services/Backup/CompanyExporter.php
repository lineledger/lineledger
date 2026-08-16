<?php

namespace App\Services\Backup;

use App\Enums\CompanyBackupStatus;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\CompanyBackup;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Estimate;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesReceipt;
use App\Models\StockAdjustment;
use App\Models\TaxReturn;
use App\Models\VendorCredit;
use App\Support\Storage\StorageDisks;
use Carbon\CarbonImmutable;
use Closure;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Orchestrates a per-company backup: composes the four writer services
 * (JsonlTableWriter, AttachmentExporter, ReferencedUsersExporter,
 * ManifestBuilder) and produces a single signed ZIP on the local disk.
 *
 * The exporter is the synchronous unit of work — {@see ExportCompanyDataJob}
 * is a thin queue wrapper around it. Callable from CLI, queue, or tests.
 *
 * Tenancy: every table is filtered explicitly by `company_id`, either via
 * the writer's built-in branch (for tables that have a `company_id` column)
 * or via a caller-supplied scope closure that filters child tables through
 * their parent's company id. The writer queries already include
 * `withoutGlobalScopes()`, so this class does not depend on the
 * `current_company` binding for read correctness — but {@see ExportCompanyDataJob}
 * still binds it defensively because some model accessors (formatting,
 * cast hooks) may reach for it.
 */
final class CompanyExporter
{
    public function __construct(
        private readonly JsonlTableWriter $jsonl,
        private readonly AttachmentExporter $attachments,
        private readonly ReferencedUsersExporter $users,
        private readonly ManifestBuilder $manifest,
    ) {}

    /**
     * Run the export end-to-end and update the backup row. Returns the same
     * backup, freshly persisted. Throws on failure (the caller — Job or CLI —
     * is responsible for translating the exception into the Failed status).
     */
    public function export(CompanyBackup $backup): CompanyBackup
    {
        if (! in_array($backup->status, [CompanyBackupStatus::Pending, CompanyBackupStatus::Running], true)) {
            throw new RuntimeException(
                "CompanyBackup #{$backup->id} is in status [{$backup->status->value}]; "
                .'export only accepts Pending or Running.'
            );
        }

        $company = $backup->company;
        $companyId = (int) $backup->company_id;

        $previousBinding = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        $tmpRoot = storage_path('app/private/backups/tmp');
        if (! is_dir($tmpRoot) && ! @mkdir($tmpRoot, 0755, true) && ! is_dir($tmpRoot)) {
            throw new RuntimeException("Unable to create backup tmp root: {$tmpRoot}");
        }

        $workDir = $tmpRoot.'/'.Str::uuid()->toString();
        if (! @mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            throw new RuntimeException("Unable to create backup working directory: {$workDir}");
        }

        $this->ensureDirectory($workDir.'/data');
        $this->ensureDirectory($workDir.'/files');

        try {
            // 1. Files first — gives us the path-rewrite map for attachments
            //    and the relative logo path for the companies row transform.
            $attachmentResult = $this->attachments->exportAttachments($companyId, $workDir);
            $logoRelativePath = $this->attachments->exportCompanyLogo($company, $workDir);
            $documentLogoRelativePath = $this->attachments->exportCompanyDocumentLogo($company, $workDir);

            // 2. Walk every registered table in dependency order, writing JSONL.
            $tablesIndex = [];

            foreach (BackupTableRegistry::tables() as $entry) {
                $table = $entry['table'];
                $model = $entry['model'];

                $rowTransform = $this->rowTransformFor($table, $attachmentResult, $logoRelativePath, $documentLogoRelativePath);
                $scope = $this->scopeFor($table, $companyId);

                $tablesIndex[$table] = $this->jsonl->write(
                    modelClass: $model,
                    companyId: $companyId,
                    workDir: $workDir,
                    tableName: $table,
                    rowTransform: $rowTransform,
                    scope: $scope,
                );
            }

            // 3. users.json
            $usersResult = $this->users->exportReferencedUsers($companyId, $workDir);

            // 4. manifest.json — composed from everything above.
            $exportedBy = $backup->requestedBy ?? $company->owner();
            if ($exportedBy === null) {
                throw new RuntimeException(
                    "CompanyBackup #{$backup->id} has no requested_by_user_id and the company has no Owner member; "
                    .'cannot stamp manifest.exported_by.'
                );
            }

            $manifestResult = $this->manifest->write([
                'company' => $company,
                'exportedBy' => $exportedBy,
                'tables' => $tablesIndex,
                'files' => [
                    'count' => $attachmentResult['files_count'],
                    'total_bytes' => $attachmentResult['bytes'],
                    'missing' => $attachmentResult['missing'],
                ],
                'users' => [
                    'count' => $usersResult['count'],
                    'sha256' => $usersResult['sha256'],
                ],
            ], $workDir);

            unset($manifestResult); // Result captured inside manifest.json; not needed by caller.

            // 5. ZIP it.
            $zipRelativePath = sprintf(
                'backups/%d/%d-%s.zip',
                $companyId,
                $backup->id,
                CarbonImmutable::now('UTC')->format('Ymd-His'),
            );
            // ZipArchive writes to a real filesystem path, and hash_file()/filesize()
            // need one too, so the archive is always assembled locally. Only the
            // finished artifact is handed to the configured backup disk.
            $zipAbsolutePath = Storage::disk('local')->path($zipRelativePath);
            $this->ensureDirectory(dirname($zipAbsolutePath));

            $this->zipDirectory($workDir, $zipAbsolutePath);

            $sha256 = hash_file('sha256', $zipAbsolutePath);
            $bytes = filesize($zipAbsolutePath);

            $backupDisk = StorageDisks::backups();
            $this->publishArchive($zipAbsolutePath, $zipRelativePath, $backupDisk);

            $rowCounts = [];
            foreach ($tablesIndex as $tableName => $info) {
                $rowCounts[$tableName] = (int) $info['rows'];
            }

            $backup->forceFill([
                'status' => CompanyBackupStatus::Ready,
                'disk' => $backupDisk,
                'file_path' => $zipRelativePath,
                'file_size_bytes' => $bytes !== false ? (int) $bytes : null,
                'sha256' => $sha256 !== false ? $sha256 : null,
                'row_counts' => $rowCounts,
                'app_version' => (string) config('version.app'),
                'schema_version' => (int) config('version.schema'),
                'error_message' => null,
                'expires_at' => CarbonImmutable::now()->addDays(7),
            ])->save();

            return $backup->fresh() ?? $backup;
        } catch (Throwable $e) {
            $backup->forceFill([
                'status' => CompanyBackupStatus::Failed,
                'error_message' => Str::limit($e->getMessage(), 65000),
            ])->save();

            throw $e;
        } finally {
            $this->removeDirectory($workDir);

            if ($previousBinding !== null) {
                app()->instance('current_company', $previousBinding);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }

    /**
     * Returns the row-transform closure for a given table, or null when no
     * transform is needed. Used for stripping secrets, rewriting attachment
     * paths, and replacing the live logo path with the in-bundle relative path.
     *
     * @param  array{attachments: array<int, string>, files_count: int, bytes: int, missing: list<int>}  $attachmentResult
     */
    private function rowTransformFor(
        string $table,
        array $attachmentResult,
        ?string $logoRelativePath,
        ?string $documentLogoRelativePath = null,
    ): ?Closure {
        return match ($table) {
            'companies' => function (array $row) use ($logoRelativePath, $documentLogoRelativePath): array {
                foreach (array_keys($row) as $key) {
                    if (is_string($key) && str_starts_with($key, 'stripe_')) {
                        unset($row[$key]);
                    }
                }

                if ($logoRelativePath !== null) {
                    $row['logo_path'] = $logoRelativePath;
                }

                if ($documentLogoRelativePath !== null) {
                    $row['document_logo_path'] = $documentLogoRelativePath;
                }

                return $row;
            },
            'attachments' => function (array $row) use ($attachmentResult): array {
                $id = isset($row['id']) ? (int) $row['id'] : 0;

                if ($id !== 0 && isset($attachmentResult['attachments'][$id])) {
                    $row['path'] = $attachmentResult['attachments'][$id];
                }

                return $row;
            },
            'company_api_keys' => static function (array $row): array {
                // Plaintext tokens are unrecoverable (only hashed + last_four exist),
                // but null any token-shaped column defensively so a future column
                // rename doesn't leak a secret in a backup bundle.
                foreach (['token_hash', 'token', 'plaintext_token'] as $col) {
                    if (array_key_exists($col, $row)) {
                        $row[$col] = null;
                    }
                }

                return $row;
            },
            default => null,
        };
    }

    /**
     * Returns the query-scope closure for child tables that do not carry a
     * `company_id` column themselves. Filters via a subquery against the
     * parent model's company-scoped ids.
     *
     * Returns null for any table the writer can resolve itself (the
     * `companies` row-id branch, or any table whose schema has `company_id`).
     */
    private function scopeFor(string $table, int $companyId): ?Closure
    {
        return match ($table) {
            'journal_lines' => fn (Builder $q) => $q->whereIn(
                'journal_entry_id',
                $this->parentIds(JournalEntry::class, $companyId),
            ),
            'invoice_lines' => fn (Builder $q) => $q->whereIn(
                'invoice_id',
                $this->parentIds(Invoice::class, $companyId),
            ),
            'item_components' => fn (Builder $q) => $q->whereIn(
                'item_id',
                $this->parentIds(Item::class, $companyId),
            ),
            'sales_receipt_lines' => fn (Builder $q) => $q->whereIn(
                'sales_receipt_id',
                $this->parentIds(SalesReceipt::class, $companyId),
            ),
            'estimate_lines' => fn (Builder $q) => $q->whereIn(
                'estimate_id',
                $this->parentIds(Estimate::class, $companyId),
            ),
            'sales_order_lines' => fn (Builder $q) => $q->whereIn(
                'sales_order_id',
                $this->parentIds(SalesOrder::class, $companyId),
            ),
            'credit_memo_lines' => fn (Builder $q) => $q->whereIn(
                'credit_memo_id',
                $this->parentIds(CreditMemo::class, $companyId),
            ),
            'bill_lines' => fn (Builder $q) => $q->whereIn(
                'bill_id',
                $this->parentIds(Bill::class, $companyId),
            ),
            'purchase_order_lines' => fn (Builder $q) => $q->whereIn(
                'purchase_order_id',
                $this->parentIds(PurchaseOrder::class, $companyId),
            ),
            'vendor_credit_lines' => fn (Builder $q) => $q->whereIn(
                'vendor_credit_id',
                $this->parentIds(VendorCredit::class, $companyId),
            ),
            'cheque_lines' => fn (Builder $q) => $q->whereIn(
                'cheque_id',
                $this->parentIds(Cheque::class, $companyId),
            ),
            'expense_lines' => fn (Builder $q) => $q->whereIn(
                'expense_id',
                $this->parentIds(Expense::class, $companyId),
            ),
            'tax_return_lines' => fn (Builder $q) => $q->whereIn(
                'tax_return_id',
                $this->parentIds(TaxReturn::class, $companyId),
            ),
            'stock_adjustment_lines' => fn (Builder $q) => $q->whereIn(
                'stock_adjustment_id',
                $this->parentIds(StockAdjustment::class, $companyId),
            ),
            'receipt_applications' => fn (Builder $q) => $q->whereIn(
                'customer_receipt_id',
                $this->parentIds(CustomerReceipt::class, $companyId),
            ),
            'bill_payment_applications' => fn (Builder $q) => $q->whereIn(
                'bill_payment_id',
                $this->parentIds(BillPayment::class, $companyId),
            ),
            'deposit_lines' => fn (Builder $q) => $q->whereIn(
                'deposit_id',
                $this->parentIds(Deposit::class, $companyId),
            ),
            // recurring_document_lines and recurring_journal_entry_lines DO have
            // their own company_id — let the writer resolve them via the default branch.
            default => null,
        };
    }

    /**
     * Sub-builder of parent-table ids scoped to a company id, with global
     * scopes disabled so the company filter is the only tenancy clause.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function parentIds(string $modelClass, int $companyId): Builder
    {
        return $modelClass::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->select('id');
    }

    /**
     * Zip the contents of `$sourceDir` into `$destZip`. File entries inside
     * the archive are stored relative to `$sourceDir` so that the unzipped
     * tree mirrors the bundle layout (manifest.json at the root, data/, files/).
     */
    /**
     * Move the finished archive from the local staging path onto the configured
     * backup disk. A no-op when backups already live locally.
     *
     * The upload is streamed rather than buffered — a backup ZIP is unbounded in
     * size and `get()`-ing it would pull the whole thing into memory. The local
     * copy is only removed once the write has been confirmed, so a failed upload
     * leaves the archive recoverable rather than destroying it.
     */
    private function publishArchive(string $absolutePath, string $relativePath, string $disk): void
    {
        if ($disk === 'local') {
            return;
        }

        $stream = fopen($absolutePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to read the staged backup archive at {$absolutePath}.");
        }

        try {
            if (Storage::disk($disk)->writeStream($relativePath, $stream) === false) {
                throw new RuntimeException("Unable to upload the backup archive to disk [{$disk}].");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        @unlink($absolutePath);
    }

    private function zipDirectory(string $sourceDir, string $destZip): void
    {
        $zip = new ZipArchive;

        $opened = $zip->open($destZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException("Failed to open ZIP for write at {$destZip} (code {$opened}).");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $sourceLen = strlen($sourceDir.DIRECTORY_SEPARATOR);

        foreach ($iterator as $item) {
            $absolute = $item->getPathname();
            $relative = substr($absolute, $sourceLen);

            // Normalise to forward slashes inside the ZIP for portability.
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($item->isDir()) {
                $zip->addEmptyDir($relative);

                continue;
            }

            $zip->addFile($absolute, $relative);
        }

        if (! $zip->close()) {
            throw new RuntimeException("Failed to finalise ZIP at {$destZip}.");
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create directory: {$dir}");
        }
    }

    /**
     * Recursively remove a directory and everything inside it. Used to clean
     * up the temp work dir whether the export succeeded or failed.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
