<?php

namespace App\Actions\Companies;

use App\Models\Attachment;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Services\Backup\BackupTableRegistry;
use App\Support\Storage\StorageDisks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently destroy an already-soft-deleted company: every row it owns and
 * every file on disk. Irreversible — only the site admin portal calls this,
 * only on a trashed company, and only behind a type-the-exact-name confirmation.
 *
 * Rows are deleted table by table rather than left to the database cascade.
 * Most company_id foreign keys do cascade, but ~60 intra-company references are
 * RESTRICT/NO ACTION (journal_lines.account_id, invoices.contact_id, …), and
 * neither MySQL nor SQLite guarantees it deletes a cascade's children before it
 * checks those — so `forceDelete()` alone fails on a company with any data.
 *
 * The order comes from {@see BackupTableRegistry::tables()}, which is a
 * topological FK order for *inserts*; reversing it is therefore safe for
 * deletes, and an arch test guarantees every company-scoped table is registered
 * there (or in excludedTables()), so a new table is covered automatically.
 */
class PurgeCompany
{
    /**
     * Company-scoped tables deliberately left behind. All three are nullOnDelete
     * so the rows survive with a null company_id: the purge must not be able to
     * erase the audit trail of itself, nor a user's support history.
     *
     * @var list<string>
     */
    private const PRESERVED_TABLES = [
        'security_logs',
        'company_restores',
        'support_tickets',
    ];

    /**
     * Tables guarded by a BEFORE DELETE trigger that raises on any direct
     * DELETE (the hash-chained accounting audit log). MySQL does not fire
     * triggers for foreign-key cascades, so leaving these to the cascade from
     * the companies row is what lets a purge erase them — and only a purge,
     * which is the point of the trigger.
     *
     * @var list<string>
     */
    private const CASCADE_ONLY_TABLES = [
        'accounting_audit_logs',
    ];

    /**
     * Backup-excluded tables whose own FKs mean they must go before their
     * parents. Any other excluded table is appended after these; they are leaves
     * that only reference core tables, which the registry pass deletes later.
     *
     * @var list<string>
     */
    private const EXCLUDED_CHILDREN_FIRST = [
        'bank_statement_lines',
        'bank_statement_imports',
        'budget_lines',
        'budgets',
    ];

    public function handle(Company $company): void
    {
        $companyId = $company->id;

        // Collect every path before the rows go.
        $attachments = Attachment::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['disk', 'path'])
            ->map(fn (Attachment $a): array => ['disk' => $a->disk ?: 'local', 'path' => (string) $a->path])
            ->all();

        $backups = CompanyBackup::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('file_path')
            ->get(['disk', 'file_path'])
            ->map(fn (CompanyBackup $b): array => ['disk' => $b->storageDisk(), 'path' => (string) $b->file_path])
            ->all();

        $logoPaths = array_values(array_filter([$company->logo_path, $company->document_logo_path]));

        DB::transaction(function () use ($company, $companyId) {
            foreach ($this->purgeOrder() as $table) {
                // Line tables (invoice_lines, journal_lines, …) carry no
                // company_id — they cascade from the parent document, which the
                // reverse order deletes immediately after. Filtering on a column
                // that isn't there is a hard error on MySQL and, unhelpfully, a
                // silent no-op on SQLite.
                if (! Schema::hasColumn($table, 'company_id')) {
                    continue;
                }

                DB::table($table)->where('company_id', $companyId)->delete();
            }

            $company->forceDelete();
        });

        // Only once the delete has committed: a rolled-back purge must not have
        // already destroyed the files of a company that still exists.
        foreach ($attachments as $attachment) {
            if ($attachment['path'] !== '') {
                Storage::disk($attachment['disk'])->delete($attachment['path']);
            }
        }

        // Sweep the company's upload tree for anything the rows didn't name.
        // Every disk the rows referenced gets swept, plus the one currently
        // configured — a deployment that switched to object storage still has
        // the pre-switch tree sitting on the local disk.
        $sweepDisks = array_unique(array_merge(
            array_column($attachments, 'disk'),
            [StorageDisks::attachments()],
        ));

        foreach ($sweepDisks as $disk) {
            Storage::disk($disk)->deleteDirectory('attachments/'.$companyId);
        }

        foreach ($backups as $backup) {
            Storage::disk($backup['disk'])->delete($backup['path']);
        }

        foreach ($logoPaths as $path) {
            Storage::disk(StorageDisks::logos())->delete($path);
        }
    }

    /**
     * Every company-scoped table, children before parents.
     *
     * @return list<string>
     */
    private function purgeOrder(): array
    {
        $excluded = array_diff(
            array_column(BackupTableRegistry::excludedTables(), 'table'),
            self::PRESERVED_TABLES,
        );

        $order = array_values(array_intersect(self::EXCLUDED_CHILDREN_FIRST, $excluded));
        $order = array_merge($order, array_values(array_diff($excluded, $order)));

        // Registry order is parents-first for inserts, so reverse it. 'companies'
        // heads that list and is force-deleted separately.
        $registry = array_column(BackupTableRegistry::tables(), 'table');
        $registry = array_values(array_diff($registry, ['companies'], self::CASCADE_ONLY_TABLES));

        return array_values(array_diff(array_merge($order, array_reverse($registry)), self::CASCADE_ONLY_TABLES));
    }
}
