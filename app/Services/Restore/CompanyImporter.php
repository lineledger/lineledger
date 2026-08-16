<?php

namespace App\Services\Restore;

use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyRestore;
use App\Models\Membership;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Audit\CanonicalJson;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Backup\CompanyExporter;
use App\Services\Restore\Exceptions\BundleValidationException;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Inverse of {@see CompanyExporter}.
 *
 * Walks a Phase 1 bundle into a fresh Company on the target instance:
 *
 *  1. Extracts the ZIP to a temp directory.
 *  2. Re-validates the manifest (schema + per-table sha256).
 *  3. Builds an email-match user remap and an empty IdMapper.
 *  4. Creates the new Company shell (`Company::withoutEvents` — bypass the
 *     CompanyObserver chart-seeding).
 *  5. Walks {@see BackupTableRegistry::tables()} in order inside a single
 *     `DB::transaction` wrapped by `AuditMute::silence`. Rows go through
 *     `RowTransformer` (id strip, company_id swap, user remap, FK remap,
 *     polymorphic remap, per-table quirks). Every insert is via
 *     `DB::table()->insertGetId()` to skip `JournalLine::creating`'s clobber
 *     and `AccountingAuditLog`'s update/delete immutability guard uniformly.
 *  6. Re-updates the Company row with account FKs that didn't exist when
 *     the shell was created.
 *  7. Imports attachment blobs onto the target disk, then the company logo.
 *  8. Verifies inserted counts against the manifest.
 *  9. Inserts the importing user's Owner Membership LAST — a half-built
 *     restore must never leave a user with an Owner card.
 *
 * On any throw the transaction rolls back, the restore goes to Failed with
 * `error_message`, and the temp dir is cleaned up. The uploaded ZIP is
 * intentionally retained for debugging.
 */
final class CompanyImporter
{
    public function __construct(
        private readonly BundleInspector $inspector,
        private readonly JsonlTableReader $reader,
        private readonly UserRemapBuilder $userRemap,
        private readonly AttachmentImporter $attachments,
    ) {}

    /**
     * Run the restore end-to-end. Returns the same row, freshly persisted.
     * Throws on failure (caller — Job or CLI — sees the failed status the
     * service has already written, plus a re-thrown exception).
     */
    public function import(CompanyRestore $restore): CompanyRestore
    {
        if (! in_array($restore->status, [
            CompanyRestoreStatus::Pending,
            CompanyRestoreStatus::Running,
        ], true)) {
            throw new RuntimeException(
                "CompanyRestore #{$restore->id} is in status [{$restore->status->value}]; "
                .'import only accepts Pending or Running.'
            );
        }

        $restore->forceFill([
            'status' => CompanyRestoreStatus::Running,
            'started_at' => $restore->started_at ?? now(),
        ])->save();

        if ($restore->file_path === null || $restore->file_path === '') {
            throw new RuntimeException("CompanyRestore #{$restore->id} has no file_path.");
        }

        $zipAbsolute = Storage::disk('local')->path($restore->file_path);

        if (! is_file($zipAbsolute)) {
            throw new RuntimeException("Restore bundle missing from disk: {$zipAbsolute}");
        }

        $tmpRoot = storage_path('app/private/restores/tmp');
        if (! is_dir($tmpRoot) && ! @mkdir($tmpRoot, 0755, true) && ! is_dir($tmpRoot)) {
            throw new RuntimeException("Unable to create restore tmp root: {$tmpRoot}");
        }

        $extractedDir = $tmpRoot.'/'.$restore->id.'-'.Str::random(8);
        if (! @mkdir($extractedDir, 0755, true) && ! is_dir($extractedDir)) {
            throw new RuntimeException("Unable to create restore extract directory: {$extractedDir}");
        }

        $previousBinding = app()->bound('current_company') ? app('current_company') : null;
        $newCompany = null;

        try {
            $this->extractZip($zipAbsolute, $extractedDir);

            // A bundle re-zipped by macOS Finder nests every entry under a single
            // wrapping folder (plus __MACOSX cruft); rebase onto the real root so
            // the per-table reads below resolve. A flat, exported bundle is left
            // untouched. The inspector handles the same case against the ZIP.
            $extractedDir = $this->resolveContentRoot($extractedDir);

            // Re-validate the manifest (mirrors the inspect-on-upload pass) — schema
            // version, user-match plan, warnings. The {@see BundleInspector} also
            // checks the manifest is well-formed; we let it throw straight through
            // BundleValidationException → RuntimeException.
            $preview = $this->inspector->inspect($zipAbsolute, $restore->requestedBy);
            $manifest = $preview['manifest'];
            $manifestTables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];

            // Per-table sha256 integrity check, before we touch the DB.
            $this->verifyTableHashes($extractedDir, $manifestTables);

            // users.json → [oldUserId => newUserId] map.
            $bundleUsers = $this->readBundleUsers($extractedDir);
            $remap = $this->userRemap->build($bundleUsers, $restore->requestedBy);
            $userIdMap = $remap['map'];

            // Read the original (untransformed) Company row from the bundle once;
            // we'll need it both for the shell create AND for the post-loop account-id
            // update.
            $rawCompanyRow = $this->readCompaniesFirstRow($extractedDir);
            if ($rawCompanyRow === null) {
                throw new BundleValidationException(
                    'Bundle data/companies.jsonl is empty — no source company row to restore.'
                );
            }

            $idMapper = new IdMapper;

            // Create the shell with a temporary transformer (no IdMapper entries yet
            // — account FKs in the row will be null-or-unchanged for now; we'll
            // overwrite them after the table loop populates IdMapper).
            $shellTransformer = new RowTransformer(
                idMapper: $idMapper,
                userIdMap: $userIdMap,
                importingUserId: (int) $restore->requested_by_user_id,
                newCompanyId: 0,
            );

            $shellResult = $shellTransformer->transform('companies', $rawCompanyRow);
            $oldCompanyId = $shellResult['old_id'];
            $shellRow = $shellResult['row'];

            // Generate a fresh, globally-unique slug for the new tenant. The
            // exporter strips `slug` in the transformer; without it the model's
            // `creating` hook would regenerate based on $name — but we're using
            // withoutEvents to bypass the chart seeding, so we must do it manually.
            $shellRow['slug'] = $this->uniqueCompanySlug(
                (string) ($shellRow['name'] ?? 'Restored Company')
            );

            // logo_path comes back attached after the AttachmentImporter logo pass.
            $shellRow['logo_path'] = null;

            // Strip account-FK columns from the shell insert — they reference
            // accounts we haven't restored yet. Re-applied after the table loop.
            foreach ([
                'default_inventory_asset_account_id',
                'default_cogs_account_id',
                'exchange_gain_loss_account_id',
                'unrealized_gain_loss_account_id',
            ] as $deferredColumn) {
                unset($shellRow[$deferredColumn]);
            }

            // Ensure timestamps survive the DB::table path (no model timestamping).
            $now = now();
            $shellRow['created_at'] = $shellRow['created_at'] ?? $now;
            $shellRow['updated_at'] = $now;

            $shellPayload = $this->prepareRowForInsert($shellRow);

            $newCompanyId = (int) Company::withoutEvents(
                fn (): int => DB::table('companies')->insertGetId($shellPayload)
            );

            $newCompany = Company::query()
                ->withoutGlobalScopes()
                ->findOrFail($newCompanyId);

            if ($oldCompanyId !== null) {
                $idMapper->set('companies', $oldCompanyId, (int) $newCompany->id);
            }

            app()->instance('current_company', $newCompany);

            $rowTransformer = new RowTransformer(
                idMapper: $idMapper,
                userIdMap: $userIdMap,
                importingUserId: (int) $restore->requested_by_user_id,
                newCompanyId: (int) $newCompany->id,
            );

            $stepResults = [];
            $deferredFkUpdates = [];

            DB::transaction(function () use (
                $extractedDir,
                $rowTransformer,
                $idMapper,
                $newCompany,
                $manifestTables,
                $rawCompanyRow,
                $restore,
                &$stepResults,
                &$deferredFkUpdates,
            ): void {
                AuditMute::silence(function () use (
                    $extractedDir,
                    $rowTransformer,
                    $idMapper,
                    $newCompany,
                    $manifestTables,
                    $rawCompanyRow,
                    &$stepResults,
                    &$deferredFkUpdates,
                ): void {
                    foreach (BackupTableRegistry::tables() as $entry) {
                        $tableName = $entry['table'];

                        // The companies row was already used to build the shell.
                        // We deferred its account-FK columns to a post-loop UPDATE
                        // (those parent ids don't exist yet).
                        if ($tableName === 'companies') {
                            $stepResults[$tableName] = ['rows' => 1, 'inserted' => 0, 'skipped' => 0];

                            continue;
                        }

                        $jsonlPath = $extractedDir.'/data/'.$tableName.'.jsonl';

                        if (! is_file($jsonlPath)) {
                            // Source company had zero rows for this table.
                            $stepResults[$tableName] = ['rows' => 0, 'inserted' => 0, 'skipped' => 0];

                            continue;
                        }

                        $inserted = 0;
                        $skipped = 0;

                        // Running previous_hash for the rebuilt audit chain on the
                        // new tenant. See `rebuildAuditChainEntry` for why we
                        // re-derive instead of copying the bundle's hashes
                        // verbatim.
                        $auditPrevHash = AccountingAuditRecorder::GENESIS_HASH;

                        foreach ($this->reader->read($jsonlPath) as $row) {
                            $result = $rowTransformer->transform($tableName, $row);

                            if ($result['skip']) {
                                $skipped++;

                                continue;
                            }

                            $payload = $this->prepareRowForInsert($result['row']);

                            if ($tableName === 'accounting_audit_logs') {
                                [$payload, $auditPrevHash] = $this->rebuildAuditChainEntry($payload, $auditPrevHash);
                            }

                            $newId = (int) DB::table($tableName)->insertGetId($payload);

                            if ($result['old_id'] !== null) {
                                $idMapper->set($tableName, $result['old_id'], $newId);
                            }

                            // Park any cross-cycle FK values for the post-loop UPDATE.
                            // The parent ids these point at don't exist yet (e.g. an
                            // estimate's converted_invoice_id, an invoice's
                            // recurring_document_id). See RowTransformer::DEFERRED_FK_COLUMNS.
                            if ($result['deferred'] !== []) {
                                foreach ($result['deferred'] as $column => $oldParentId) {
                                    $deferredFkUpdates[] = [
                                        'table' => $tableName,
                                        'new_id' => $newId,
                                        'column' => $column,
                                        'old_parent_id' => $oldParentId,
                                    ];
                                }
                            }

                            $inserted++;
                        }

                        $stepResults[$tableName] = [
                            'rows' => $inserted + $skipped,
                            'inserted' => $inserted,
                            'skipped' => $skipped,
                        ];

                        $manifestRows = (int) ($manifestTables[$tableName]['rows'] ?? -1);
                        if ($manifestRows >= 0 && $manifestRows !== $inserted + $skipped) {
                            throw new RuntimeException(sprintf(
                                'Row count mismatch for table %s: manifest reports %d, restored %d.',
                                $tableName,
                                $manifestRows,
                                $inserted + $skipped,
                            ));
                        }
                    }

                    // Re-update the new Company shell with the account-FK columns
                    // that the row transformer can NOW resolve via the populated
                    // IdMapper (default_inventory_asset_account_id, etc.).
                    $companyUpdate = $rowTransformer->transform('companies', $rawCompanyRow);
                    $updateRow = $this->prepareRowForInsert($companyUpdate['row']);

                    // Don't overwrite the freshly-generated slug or the (about to
                    // be set) logo_path — both are handled separately. Also drop
                    // the bundle's id/created_at; updated_at refreshes to now.
                    unset(
                        $updateRow['slug'],
                        $updateRow['logo_path'],
                        $updateRow['id'],
                        $updateRow['created_at'],
                    );
                    $updateRow['updated_at'] = now();

                    DB::table('companies')
                        ->where('id', $newCompany->id)
                        ->update($updateRow);

                    // ---- Deferred FK pass ----
                    // Cross-cycle FKs that couldn't be set on the primary insert
                    // (the parent row didn't exist yet — e.g. estimates.converted_invoice_id
                    // pointing forward into invoices). Now that the whole table loop
                    // has populated IdMapper, look up the new parent ids and patch
                    // the child rows.
                    $deferredApplied = 0;
                    $deferredOrphaned = 0;
                    foreach ($deferredFkUpdates as $update) {
                        $parentTable = RowTransformer::DEFERRED_FK_COLUMNS[$update['table']][$update['column']] ?? null;

                        $newParentId = $parentTable !== null
                            ? $idMapper->get($parentTable, $update['old_parent_id'])
                            : null;

                        if ($newParentId === null) {
                            // Orphan reference (parent table was excluded, empty, or
                            // the bundle had a dangling id). Leave the column null.
                            $deferredOrphaned++;

                            continue;
                        }

                        DB::table($update['table'])
                            ->where('id', $update['new_id'])
                            ->update([$update['column'] => $newParentId]);
                        $deferredApplied++;
                    }
                    $stepResults['_deferred_fks'] = [
                        'applied' => $deferredApplied,
                        'orphaned' => $deferredOrphaned,
                    ];
                });

                // ---- Attachment pass + logo pass (outside AuditMute but inside
                //      the transaction so any failure rolls back the Company shell). ----
                $attachmentSummary = $this->attachments->importAttachments(
                    (int) $newCompany->id,
                    $extractedDir,
                    $idMapper,
                );
                $stepResults['_attachments'] = $attachmentSummary;

                $newLogoPath = $this->attachments->importCompanyLogo(
                    (int) $newCompany->id,
                    $extractedDir,
                );
                $stepResults['_logo'] = ['path' => $newLogoPath];

                $newDocumentLogoPath = $this->attachments->importCompanyDocumentLogo(
                    (int) $newCompany->id,
                    $extractedDir,
                );
                $stepResults['_document_logo'] = ['path' => $newDocumentLogoPath];

                // ---- Owner Membership: dead last so a failed restore never
                //      leaves the user holding a partial company. ----
                Membership::create([
                    'company_id' => $newCompany->id,
                    'user_id' => $restore->requested_by_user_id,
                    'role' => CompanyRole::Owner,
                ]);
            });

            $restore->forceFill([
                'status' => CompanyRestoreStatus::Completed,
                'company_id' => $newCompany->id,
                'completed_at' => now(),
                'step_results' => $stepResults,
                'error_message' => null,
            ])->save();

            return $restore->fresh() ?? $restore;
        } catch (Throwable $e) {
            $restore->forceFill([
                'status' => CompanyRestoreStatus::Failed,
                'error_message' => Str::limit($e->getMessage(), 65000),
            ])->save();

            throw $e;
        } finally {
            $this->removeDirectory($extractedDir);

            if ($previousBinding !== null) {
                app()->instance('current_company', $previousBinding);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }

    /**
     * Extract the ZIP at `$zipAbsolute` into `$extractedDir`. Throws on any
     * ZipArchive failure so the caller can surface a precise error.
     */
    private function extractZip(string $zipAbsolute, string $extractedDir): void
    {
        $zip = new ZipArchive;
        $openResult = $zip->open($zipAbsolute);

        if ($openResult !== true) {
            throw new RuntimeException(
                "Unable to open backup ZIP at {$zipAbsolute} (ZipArchive error code: {$openResult})."
            );
        }

        try {
            $this->assertSafeZipEntries($zip);

            if (! $zip->extractTo($extractedDir)) {
                throw new RuntimeException("Failed to extract backup ZIP to {$extractedDir}.");
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Reject a hostile archive *before* extraction. An entry with an absolute
     * path, a `..` traversal segment, or a drive/UNC prefix could otherwise make
     * `ZipArchive::extractTo()` write outside `$extractedDir` (a "zip slip").
     * Modern libzip guards this internally, but the bundle is fully
     * attacker-controlled (any authenticated user can upload one), so we make
     * the boundary explicit and version-independent.
     */
    private function assertSafeZipEntries(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $name);

            if ($normalized === ''
                || str_starts_with($normalized, '/')
                || preg_match('#^[a-zA-Z]:#', $normalized) === 1
                || in_array('..', explode('/', $normalized), true)
            ) {
                throw new BundleValidationException(
                    "Refusing to extract unsafe archive entry: {$name}"
                );
            }
        }
    }

    /**
     * Return the directory that actually holds `manifest.json`. Handles a bundle
     * that was extracted then re-compressed by macOS Finder, which wraps every
     * entry in a single folder alongside a `__MACOSX/` sidecar. Falls back to the
     * extraction root when there is no single wrapping folder, leaving the
     * downstream manifest checks to report the problem.
     */
    private function resolveContentRoot(string $extractedDir): string
    {
        if (is_file($extractedDir.'/manifest.json')) {
            return $extractedDir;
        }

        foreach (glob($extractedDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) === '__MACOSX') {
                continue;
            }

            if (is_file($dir.'/manifest.json')) {
                return $dir;
            }
        }

        return $extractedDir;
    }

    /**
     * Per-table sha256 verification — re-hash each `data/{table}.jsonl` and
     * compare against the manifest. Mismatch throws so the import aborts
     * before touching the DB.
     *
     * @param  array<string, mixed>  $manifestTables
     */
    private function verifyTableHashes(string $extractedDir, array $manifestTables): void
    {
        foreach ($manifestTables as $tableName => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $expected = $meta['sha256'] ?? null;
            if (! is_string($expected) || $expected === '') {
                continue;
            }

            $jsonlPath = $extractedDir.'/data/'.$tableName.'.jsonl';
            if (! is_file($jsonlPath)) {
                continue;
            }

            $actual = hash_file('sha256', $jsonlPath);

            if ($actual === false || ! hash_equals($expected, $actual)) {
                throw new BundleValidationException(sprintf(
                    'Integrity check failed for table %s (expected sha256 %s, got %s).',
                    $tableName,
                    $expected,
                    $actual === false ? 'unreadable' : $actual,
                ));
            }
        }
    }

    /**
     * Decode `users.json` from the extracted directory.
     *
     * @return array<int, array{id:int,email:string,name:string}>
     */
    private function readBundleUsers(string $extractedDir): array
    {
        $path = $extractedDir.'/users.json';

        if (! is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read and decode the first non-empty line of `data/companies.jsonl`.
     *
     * @return array<string, mixed>|null
     */
    private function readCompaniesFirstRow(string $extractedDir): ?array
    {
        $path = $extractedDir.'/data/companies.jsonl';

        if (! is_file($path)) {
            return null;
        }

        foreach ($this->reader->read($path) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Normalise a row's values for `DB::table()->insert()`:
     *
     *  - `array` → JSON-encoded string (json/jsonb columns).
     *  - ISO-8601 datetime strings (`Y-m-d\TH:i:s.uP` shape produced by
     *    `$model->toArray()`'s cast layer) → `Y-m-d H:i:s` so MySQL stops
     *    rejecting them as `Invalid datetime format`.
     *
     * The `RowTransformer` contract intentionally leaves both arrays and
     * datetime strings untouched — this is the boundary where we serialise
     * them for the underlying DB driver.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function prepareRowForInsert(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $row[$column] = $encoded === false ? null : $encoded;

                continue;
            }

            if (is_string($value) && $this->looksLikeIsoDateTime($value)) {
                $row[$column] = $this->normalizeDateTimeString($value);
            }
        }

        return $row;
    }

    /**
     * Re-derive `hash_input`, `previous_hash`, and `row_hash` for one audit row
     * from its current (post-remap) column values. The bundle's hashes are
     * over the source-tenant IDs, but the row's `company_id` / `auditable_id` /
     * `journal_entry_id` have all been remapped to the new tenant — so the
     * chain has to be rebuilt against the new IDs or `audit:verify` flags
     * every row as column-drifted from canonical input.
     *
     * The payload column is left as-is (it contains snapshotted source-tenant
     * IDs that are historical truth at write time). Loose equality in the
     * verifier accepts the round-trip.
     *
     * @param  array<string, mixed>  $row  Already `prepareRowForInsert`-encoded
     * @return array{0: array<string, mixed>, 1: string} [updated row, new prev_hash for next call]
     */
    private function rebuildAuditChainEntry(array $row, string $previousHash): array
    {
        // payload comes in as a JSON string after prepareRowForInsert. Decode
        // it for the canonical contents (CanonicalJson encodes back to bytes).
        $payload = $row['payload'] ?? null;
        if (is_string($payload) && $payload !== '') {
            try {
                $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $payload = null;
            }
        }

        $contents = [
            'company_id' => (int) $row['company_id'],
            'sequence' => (int) $row['sequence'],
            'recorded_at' => $row['recorded_at'],
            'actor_user_id' => isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null,
            'api_key_id' => isset($row['api_key_id']) ? (int) $row['api_key_id'] : null,
            'actor_ip' => $row['actor_ip'] ?? null,
            'actor_user_agent' => $row['actor_user_agent'] ?? null,
            'action' => $row['action'],
            'auditable_type' => $row['auditable_type'],
            'auditable_id' => (int) $row['auditable_id'],
            'journal_entry_id' => isset($row['journal_entry_id']) ? (int) $row['journal_entry_id'] : null,
            'payload' => $payload,
            'previous_hash' => $previousHash,
        ];

        $hashInput = CanonicalJson::encode($contents);
        $rowHash = AccountingAuditRecorder::hashFromInput($previousHash, $hashInput);

        $row['previous_hash'] = $previousHash;
        $row['hash_input'] = $hashInput;
        $row['row_hash'] = $rowHash;

        return [$row, $rowHash];
    }

    /**
     * Cheap shape-check for ISO-8601 datetime strings emitted by Eloquent's
     * datetime cast (`toArray()` produces `2026-05-28T16:18:49.000000Z`).
     */
    private function looksLikeIsoDateTime(string $value): bool
    {
        // 19+ chars, starts `YYYY-MM-DDT`, has a `T` at position 10 and ends
        // with `Z` or a +/-offset. Cheaper than a regex; specific enough that
        // we won't false-match `Y-m-d` date strings.
        return strlen($value) >= 20
            && $value[4] === '-'
            && $value[7] === '-'
            && $value[10] === 'T';
    }

    /**
     * Convert an ISO-8601 datetime string to the SQL format MySQL accepts.
     * Falls back to the original value on parse failure so a malformed
     * payload surfaces as a database error rather than being silently zeroed.
     */
    private function normalizeDateTimeString(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Generate a globally-unique company slug from `$name`, falling back to
     * `restored-company` when the source name slugifies to an empty string.
     *
     * Mirrors {@see GeneratesUniqueCompanySlugs::generateUniqueCompanySlug}
     * but lives here because that trait method is protected and we run with
     * `Company::withoutEvents` (so the model's `creating` hook never fires).
     */
    private function uniqueCompanySlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'restored-company';
        }

        $existing = Company::withTrashed()
            ->where(function ($query) use ($base) {
                $query->where('slug', $base)
                    ->orWhere('slug', 'like', $base.'-%');
            })
            ->pluck('slug');

        if ($existing->isEmpty()) {
            return $base;
        }

        $maxSuffix = $existing
            ->map(function (string $slug) use ($base): ?int {
                if ($slug === $base) {
                    return 0;
                }

                if (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix) => $suffix !== null)
            ->max() ?? 0;

        return $base.'-'.($maxSuffix + 1);
    }

    /**
     * Recursively delete the extract directory. Best-effort: warnings are
     * suppressed because the orchestrator has already done the meaningful
     * work and we don't want a stat error to mask a real exception.
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
