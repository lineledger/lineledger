<?php

namespace App\Services\Restore;

use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyRestore;
use App\Models\Membership;
use App\Services\Access\LegacyRoleConverter;
use App\Services\Access\PredefinedRoleSeeder;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Audit\CanonicalJson;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Backup\CompanyExporter;
use App\Services\Backup\JsonlTableWriter;
use App\Services\Restore\Exceptions\BundleValidationException;
use App\Support\Encryption\EncryptedColumns;
use App\Support\Gifi\GifiCodeCorrections;
use App\Support\Storage\TemporaryLocalFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Inverse of {@see CompanyExporter}.
 *
 * Walks a Phase 1 bundle into a fresh Company on the target instance:
 *
 *  1. Extracts the ZIP to a temp directory.
 *  2. Re-validates the manifest (schema + per-table sha256).
 *  3. Builds an email-match user remap and an empty IdMapper.
 *  4. Opens a single `DB::transaction` and creates the new Company shell in
 *     it (`Company::withoutEvents` — bypass the CompanyObserver chart-seeding).
 *  5. Walks {@see BackupTableRegistry::tables()} in order inside that
 *     transaction, wrapped by `AuditMute::silence`. Rows go through
 *     `RowTransformer` (id strip, company_id swap, user remap, FK remap,
 *     polymorphic remap, per-table quirks). Every insert is via
 *     `DB::table()->insertGetId()` to skip `JournalLine::creating`'s clobber
 *     and `AccountingAuditLog`'s update/delete immutability guard uniformly.
 *     That also skips the model casts, so columns with an `encrypted` cast,
 *     which the bundle carries as plaintext, are encrypted first with this
 *     instance's key ({@see self::encryptColumns()}).
 *  6. Re-updates the Company row with account FKs that didn't exist when
 *     the shell was created.
 *  7. Imports attachment blobs onto the target disk, then the company logo.
 *  8. Verifies inserted counts against the manifest.
 *  9. Inserts the importing user's Owner Membership LAST — a half-built
 *     restore must never leave a user with an Owner card.
 *
 * On any throw the transaction rolls back (Company shell included), the
 * restore goes to Failed with `error_message`, and the temp dir is cleaned up.
 * The uploaded ZIP is intentionally retained for debugging.
 */
final class CompanyImporter
{
    /**
     * {@see EncryptedColumns::of()} per model class, worked out once per table
     * rather than once per row.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private array $encryptedColumns = [];

    /**
     * Per model class, the columns it casts to JSON. Worked out once per class.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private array $jsonColumns = [];

    /**
     * Per table, whether each column accepts null. Worked out once per table.
     *
     * @var array<string, array<string, bool>>
     */
    private array $nullableColumns = [];

    public function __construct(
        private readonly BundleInspector $inspector,
        private readonly JsonlTableReader $reader,
        private readonly UserRemapBuilder $userRemap,
        private readonly AttachmentImporter $attachments,
        private readonly LegacyRoleConverter $roleConverter,
        private readonly BundleExtractor $extractor,
    ) {}

    /**
     * Run the restore end-to-end. Returns the same row, freshly persisted.
     * Throws on failure (caller — Job or CLI — sees the failed status the
     * service has already written, plus a re-thrown exception).
     *
     * Pass a {@see RestoreTrace} to keep what the import did (the old → new id
     * map, the user map, the rows left out); the backup verifier pairs every
     * bundle row with its copy through it.
     */
    public function import(CompanyRestore $restore, ?RestoreTrace $trace = null): CompanyRestore
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

        $disk = $restore->storageDisk();

        try {
            if ($restore->file_path === null || $restore->file_path === '') {
                throw new RuntimeException("CompanyRestore #{$restore->id} has no file_path.");
            }

            if (! Storage::disk($disk)->exists($restore->file_path)) {
                throw new RuntimeException("Restore bundle missing from disk [{$disk}]: {$restore->file_path}");
            }
        } catch (Throwable $e) {
            // Without this the row would sit in Running forever: the queued
            // job's failed() hook catches it, but a synchronous CLI run doesn't.
            $restore->forceFill([
                'status' => CompanyRestoreStatus::Failed,
                'error_message' => Str::limit($e->getMessage(), 65000),
            ])->save();

            throw $e;
        }

        // ZipArchive needs a real path. On object storage that is a temporary
        // local copy, removed again however the import ends.
        return TemporaryLocalFile::with(
            $disk,
            $restore->file_path,
            fn (string $zipAbsolute) => $this->importBundle($restore, $zipAbsolute, $trace ?? new RestoreTrace),
        );
    }

    /**
     * Steps 1–9 of the class docblock, against a local copy of the bundle.
     */
    private function importBundle(CompanyRestore $restore, string $zipAbsolute, RestoreTrace $trace): CompanyRestore
    {
        $idMapper = $trace->ids;

        $tmpRoot = storage_path('app/private/restores/tmp');
        if (! is_dir($tmpRoot) && ! @mkdir($tmpRoot, 0755, true) && ! is_dir($tmpRoot)) {
            throw new RuntimeException("Unable to create restore tmp root: {$tmpRoot}");
        }

        $extractedDir = $tmpRoot.'/'.$restore->id.'-'.Str::random(8);
        if (! @mkdir($extractedDir, 0755, true) && ! is_dir($extractedDir)) {
            throw new RuntimeException("Unable to create restore extract directory: {$extractedDir}");
        }

        $previousBinding = app()->bound('current_company') ? app('current_company') : null;

        $extractRoot = $extractedDir;

        try {
            // A bundle re-zipped by macOS Finder nests every entry under a single
            // wrapping folder (plus __MACOSX cruft); the extractor returns the real
            // root so the per-table reads below resolve. The inspector handles the
            // same case against the ZIP. Cleanup removes the whole extraction.
            $extractedDir = $this->extractor->extract($zipAbsolute, $extractRoot);

            // Re-validate the manifest (mirrors the inspect-on-upload pass) — schema
            // version, user-match plan, warnings. The {@see BundleInspector} also
            // checks the manifest is well-formed; we let it throw straight through
            // BundleValidationException → RuntimeException.
            $preview = $this->inspector->inspect($zipAbsolute, $restore->requestedBy);
            $manifest = $preview['manifest'];
            $manifestTables = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];

            // A bundle from before the GIFI codes were checked against CRA's
            // guide still carries the old, mislabelled codes on its accounts.
            $correctGifiCodes = GifiCodeCorrections::bundleNeedsCorrection($manifest);

            // Every table file the manifest lists, and only those, each
            // matching its sha256, before we touch the DB.
            $this->verifyTableFiles($extractedDir, $manifestTables);
            $this->verifyTableHashes($extractedDir, $manifestTables);
            $this->verifyUsersFile($extractedDir, $manifest);

            // users.json → [oldUserId => newUserId] map.
            $bundleUsers = $this->readBundleUsers($extractedDir);
            $remap = $this->userRemap->build($bundleUsers, $restore->requestedBy, $trace->matchUsersByEmail);
            $userIdMap = $remap['map'];
            $fallbackUserIds = array_values(array_map(
                static fn (array $match): int => $match['old_id'],
                array_filter($remap['matches'], static fn (array $match): bool => $match['match'] === 'fallback'),
            ));

            $trace->userIdMap = $userIdMap;
            $trace->fallbackUserIds = $fallbackUserIds;
            $trace->importingUserId = (int) $restore->requested_by_user_id;

            // Read the original (untransformed) Company row from the bundle once;
            // we'll need it both for the shell create AND for the post-loop account-id
            // update.
            $rawCompanyRow = $this->readCompaniesFirstRow($extractedDir);
            if ($rawCompanyRow === null) {
                throw new BundleValidationException(
                    'Bundle data/companies.jsonl is empty — no source company row to restore.'
                );
            }

            // Create the shell with a temporary transformer (no IdMapper entries yet
            // — account FKs in the row will be null-or-unchanged for now; we'll
            // overwrite them after the table loop populates IdMapper).
            $shellTransformer = new RowTransformer(
                idMapper: $idMapper,
                userIdMap: $userIdMap,
                importingUserId: (int) $restore->requested_by_user_id,
                newCompanyId: 0,
                fallbackUserIds: $fallbackUserIds,
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

            // A copy never inherits its source's scheduled purge
            // (companies:purge-deleted) or its deletion: a backup of an
            // organization pending purge restores as a live organization.
            $shellRow['purge_at'] = null;
            $shellRow['deleted_at'] = null;

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

            $shellPayload = $this->prepareRowForInsert(Company::class, $this->encryptColumns(Company::class, $shellRow));

            $stepResults = [];
            $deferredFkUpdates = [];
            $orphanedRefs = [];
            $jsonIdRows = [];

            $newCompany = DB::transaction(function () use (
                $extractedDir,
                $correctGifiCodes,
                $shellPayload,
                $oldCompanyId,
                $userIdMap,
                $idMapper,
                $manifestTables,
                $rawCompanyRow,
                $restore,
                &$stepResults,
                &$deferredFkUpdates,
                &$orphanedRefs,
                &$jsonIdRows,
                $trace,
                $fallbackUserIds,
            ): Company {
                // The shell goes in inside the transaction, so a failure at
                // any later step rolls it back with everything else.
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

                $trace->companyId = (int) $newCompany->id;

                $rowTransformer = new RowTransformer(
                    idMapper: $idMapper,
                    userIdMap: $userIdMap,
                    importingUserId: (int) $restore->requested_by_user_id,
                    newCompanyId: (int) $newCompany->id,
                    fallbackUserIds: $fallbackUserIds,
                );

                AuditMute::silence(function () use (
                    $extractedDir,
                    $correctGifiCodes,
                    $rowTransformer,
                    $idMapper,
                    $newCompany,
                    $manifestTables,
                    $rawCompanyRow,
                    &$stepResults,
                    &$deferredFkUpdates,
                    &$orphanedRefs,
                    &$jsonIdRows,
                    $trace,
                ): void {
                    foreach (BackupTableRegistry::tables() as $entry) {
                        $tableName = $entry['table'];
                        $modelClass = $entry['model'];

                        // The companies row was already used to build the shell.
                        // We deferred its account-FK columns to a post-loop UPDATE
                        // (those parent ids don't exist yet).
                        if ($tableName === 'companies') {
                            $stepResults[$tableName] = ['rows' => 1, 'inserted' => 0, 'skipped' => 0];

                            continue;
                        }

                        $jsonlPath = $extractedDir.'/data/'.$tableName.'.jsonl';

                        if (! is_file($jsonlPath)) {
                            // A table the bundle predates (verifyTableFiles has
                            // already refused a file the manifest lists but the
                            // ZIP lacks).
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

                            if (! $result['skip']) {
                                $result['skip'] = $this->applyDanglingPolicy($tableName, $result);
                            }

                            if ($result['skip']) {
                                $skipped++;

                                if ($result['old_id'] !== null) {
                                    $trace->skipped[$tableName][] = $result['old_id'];
                                }

                                continue;
                            }

                            if ($tableName === 'accounts' && $correctGifiCodes && isset($result['row']['gifi_code'])) {
                                $result['row']['gifi_code'] = GifiCodeCorrections::correct((string) $result['row']['gifi_code']);
                            }

                            $payload = $this->prepareRowForInsert($modelClass, $this->encryptColumns($modelClass, $result['row']));

                            // A deferred reference rides as null until the
                            // post-loop pass patches it. A column that can't be
                            // null (attachments.attachable_id: polymorphic, so no
                            // FK constraint to trip) holds 0 meanwhile.
                            foreach ($result['deferred'] as $column => $_reference) {
                                if (($payload[$column] ?? null) === null && ! $this->isNullable($tableName, $column)) {
                                    $payload[$column] = 0;
                                }
                            }

                            foreach ($result['orphans'] as $column => $reference) {
                                $orphanedRefs[] = [
                                    'table' => $tableName,
                                    'column' => $column,
                                    'id' => $reference['id'],
                                    'table_referenced' => $reference['table'],
                                ];
                            }

                            if ($tableName === 'accounting_audit_logs') {
                                [$payload, $auditPrevHash] = $this->rebuildAuditChainEntry($payload, $auditPrevHash);
                            }

                            $newId = (int) DB::table($tableName)->insertGetId($payload);

                            if ($result['old_id'] !== null) {
                                $idMapper->set($tableName, $result['old_id'], $newId);
                            }

                            // Ids inside JSON columns are remapped once every
                            // table is in; keep what that pass needs.
                            if (isset(RowTransformer::JSON_ID_PATHS[$tableName])) {
                                $jsonIdRows[] = [
                                    'table' => $tableName,
                                    'new_id' => $newId,
                                    'row' => array_intersect_key($result['row'], $this->jsonIdColumns($tableName)),
                                ];
                            }

                            // Park every reference to a row not restored yet for
                            // the post-loop UPDATE: cross-cycle FKs (an estimate's
                            // converted_invoice_id), self-references (a voided
                            // entry's reversed_by_entry_id) and polymorphic sources
                            // restored later (a journal entry's invoice).
                            foreach ($result['deferred'] as $column => $reference) {
                                $deferredFkUpdates[] = [
                                    'table' => $tableName,
                                    'new_id' => $newId,
                                    'column' => $column,
                                    'parent_table' => $reference['table'],
                                    'old_parent_id' => $reference['id'],
                                ];
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
                    $companyUpdateRow = array_replace(
                        $companyUpdate['row'],
                        $rowTransformer->remapJsonIds('companies', $companyUpdate['row']),
                    );
                    $updateRow = $this->prepareRowForInsert(Company::class, $this->encryptColumns(Company::class, $companyUpdateRow));

                    // Don't overwrite the freshly-generated slug or the (about to
                    // be set) logo_path — both are handled separately — nor the
                    // purge date the shell cleared. Also drop the bundle's
                    // id/created_at; updated_at refreshes to now.
                    unset(
                        $updateRow['slug'],
                        $updateRow['logo_path'],
                        $updateRow['purge_at'],
                        $updateRow['deleted_at'],
                        $updateRow['id'],
                        $updateRow['created_at'],
                    );
                    $updateRow['updated_at'] = now();

                    DB::table('companies')
                        ->where('id', $newCompany->id)
                        ->update($updateRow);

                    // ---- Deferred FK pass ----
                    // References that couldn't be set on the primary insert because
                    // the row they name hadn't been restored yet. Now that the whole
                    // table loop has populated IdMapper, look up the new ids and
                    // patch the rows.
                    $deferredApplied = 0;
                    $deferredOrphaned = 0;
                    $patches = [];
                    foreach ($deferredFkUpdates as $update) {
                        $newParentId = $idMapper->get($update['parent_table'], $update['old_parent_id']);

                        if ($newParentId === null) {
                            // The bundle names a row it doesn't hold. A nullable
                            // column stays null; one that can't be null follows
                            // its dangling-reference policy, and its placeholder
                            // never survives the restore otherwise.
                            $orphanedRefs[] = [
                                'table' => $update['table'],
                                'column' => $update['column'],
                                'id' => $update['old_parent_id'],
                                'table_referenced' => $update['parent_table'],
                            ];
                            $deferredOrphaned++;

                            if (! $this->isNullable($update['table'], $update['column'])) {
                                $this->applyDeferredDanglingPolicy($update, $trace);
                            }

                            continue;
                        }

                        $patches[$update['table']][$update['column']][$update['new_id']] = $newParentId;
                        $deferredApplied++;
                    }

                    // Nearly every journal entry is patched (its source document
                    // restores later), so the patches go in batches, not a
                    // statement per row.
                    foreach ($patches as $patchTable => $columns) {
                        foreach ($columns as $column => $values) {
                            $this->patchColumn($patchTable, $column, $values);
                        }
                    }
                    $stepResults['_deferred_fks'] = [
                        'applied' => $deferredApplied,
                        'orphaned' => $deferredOrphaned,
                    ];

                    // ---- JSON id pass ----
                    // Ids held inside JSON columns (FIFO layers an issue consumed,
                    // lines a reconciliation marked, a saved report's account), now
                    // that every table they can point at is in.
                    $jsonUpdated = 0;
                    foreach ($jsonIdRows as $jsonRow) {
                        $changes = $rowTransformer->remapJsonIds($jsonRow['table'], $jsonRow['row']);

                        if ($changes === []) {
                            continue;
                        }

                        $modelForTable = BackupTableRegistry::modelFor($jsonRow['table']);

                        DB::table($jsonRow['table'])
                            ->where('id', $jsonRow['new_id'])
                            ->update($this->prepareRowForInsert($modelForTable, $changes));
                        $jsonUpdated++;
                    }
                    $stepResults['_json_ids'] = ['updated' => $jsonUpdated];

                    // References to rows the bundle doesn't hold, restored as
                    // null. A source id is never copied through: on this
                    // instance it names another organization's row, or none.
                    $trace->orphans = $orphanedRefs;
                    $stepResults['_orphaned_refs'] = [
                        'count' => count($orphanedRefs),
                        'samples' => array_slice($orphanedRefs, 0, 20),
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

                // ---- Roles: a bundle made before roles existed carries none.
                //      Give the copy its predefined roles and convert its
                //      members, exactly as `access:sync-roles` does on upgrade. ----
                if (! DB::table('roles')->where('company_id', $newCompany->id)->exists()) {
                    $this->roleConverter->convertCompany($newCompany);
                }
                app(PredefinedRoleSeeder::class)->seed($newCompany);

                // ---- Owner Membership: dead last so a failed restore never
                //      leaves the user holding a partial company. ----
                Membership::create([
                    'company_id' => $newCompany->id,
                    'user_id' => $restore->requested_by_user_id,
                    'role' => CompanyRole::Owner,
                ]);

                return $newCompany;
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
            $this->extractor->remove($extractRoot);

            if ($previousBinding !== null) {
                app()->instance('current_company', $previousBinding);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }

    /**
     * The bundle holds a data file for every table its manifest lists, and no
     * table file the manifest doesn't list. The exporter writes a file for each
     * table even with no rows, so a missing one is a damaged bundle (a restore
     * would otherwise go ahead without that table), and an unlisted one has no
     * hash to check it against.
     *
     * @param  array<string, mixed>  $manifestTables
     */
    private function verifyTableFiles(string $extractedDir, array $manifestTables): void
    {
        foreach (array_keys($manifestTables) as $tableName) {
            if (BackupTableRegistry::modelFor((string) $tableName) === null) {
                continue; // a table this version doesn't restore (the inspector warns)
            }

            if (! is_file($extractedDir.'/data/'.$tableName.'.jsonl')) {
                throw new BundleValidationException(sprintf(
                    'The backup is damaged: its manifest lists table %s, but data/%s.jsonl is missing.',
                    $tableName,
                    $tableName,
                ));
            }
        }

        foreach (glob($extractedDir.'/data/*.jsonl') ?: [] as $file) {
            $tableName = basename($file, '.jsonl');

            if (! array_key_exists($tableName, $manifestTables)) {
                throw new BundleValidationException(sprintf(
                    'The backup is damaged: data/%s.jsonl is not listed in its manifest.',
                    $tableName,
                ));
            }
        }
    }

    /**
     * `users.json` matches the hash the manifest recorded for it. A bundle from
     * before the manifest recorded one passes.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function verifyUsersFile(string $extractedDir, array $manifest): void
    {
        $expected = $manifest['users']['sha256'] ?? null;

        if (! is_string($expected) || $expected === '') {
            return;
        }

        $path = $extractedDir.'/users.json';
        $actual = is_file($path) ? hash_file('sha256', $path) : false;

        if ($actual === false || ! hash_equals($expected, $actual)) {
            throw new BundleValidationException(sprintf(
                'Integrity check failed for users.json (expected sha256 %s, got %s).',
                $expected,
                $actual === false ? 'missing' : $actual,
            ));
        }
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
     * A column the model casts to JSON is encoded whatever the PHP type of its
     * value: `user_preferences.value` holds bare scalars, which bound raw would
     * land as `0`/`1` for a boolean or as invalid JSON for a string.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function prepareRowForInsert(string $modelClass, array $row): array
    {
        $this->jsonColumns[$modelClass] ??= self::jsonCastColumns($modelClass);

        foreach ($row as $column => $value) {
            if ($value !== null && in_array($column, $this->jsonColumns[$modelClass], true)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $row[$column] = $encoded === false ? null : $encoded;

                continue;
            }

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
     * The columns `$modelClass` casts to JSON (`array`, `json`, `object`,
     * `collection`, or an `As*` JSON cast class). Encrypted casts are left out:
     * {@see self::encryptColumns()} has already turned those into ciphertext.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    public static function jsonCastColumns(string $modelClass): array
    {
        $columns = [];

        foreach ((new $modelClass)->getCasts() as $column => $cast) {
            $base = strtolower(explode(':', $cast, 2)[0]);

            if (in_array($base, ['array', 'json', 'object', 'collection'], true)
                || str_contains($cast, 'AsArrayObject')
                || str_contains($cast, 'AsCollection')
            ) {
                $columns[] = (string) $column;
            }
        }

        return $columns;
    }

    /**
     * Decide what happens to a row with a reference to a row the bundle
     * doesn't hold. A nullable column simply restores null. One that can't be
     * null follows {@see RowTransformer::DANGLING_REFERENCE_POLICY}: `zero`
     * keeps the row pointing at nothing, `skip` leaves the row out (returns
     * true). Anything else fails the restore.
     *
     * @param  array{row: array<string, mixed>, old_id: ?int, skip: bool, deferred: array<string, array{table: string, id: int}>, orphans: array<string, array{table: string, id: int}>}  $result  Mutated: a `zero` column is set to 0.
     */
    private function applyDanglingPolicy(string $table, array &$result): bool
    {
        foreach ($result['orphans'] as $column => $reference) {
            if ($this->isNullable($table, $column)) {
                continue;
            }

            $policy = RowTransformer::DANGLING_REFERENCE_POLICY[$table][$column] ?? null;

            if ($policy === 'zero') {
                $result['row'][$column] = 0;

                continue;
            }

            if ($policy === 'skip') {
                return true;
            }

            throw new RuntimeException(sprintf(
                '%s #%d: %s names %s #%d, which is not in the backup.',
                $table,
                (int) $result['old_id'],
                $column,
                $reference['table'],
                $reference['id'],
            ));
        }

        return false;
    }

    /**
     * {@see self::applyDanglingPolicy()} for a deferred reference that never
     * resolved: the row is already in, holding the placeholder 0.
     *
     * @param  array{table: string, new_id: int, column: string, parent_table: string, old_parent_id: int}  $update
     */
    private function applyDeferredDanglingPolicy(array $update, RestoreTrace $trace): void
    {
        $policy = RowTransformer::DANGLING_REFERENCE_POLICY[$update['table']][$update['column']] ?? null;

        if ($policy === 'zero') {
            return;
        }

        if ($policy === 'skip') {
            DB::table($update['table'])->where('id', $update['new_id'])->delete();

            $oldId = array_search($update['new_id'], $trace->ids->table($update['table']), true);

            if ($oldId !== false) {
                $trace->ids->forget($update['table'], (int) $oldId);
                $trace->skipped[$update['table']][] = (int) $oldId;
            }

            return;
        }

        throw new RuntimeException(sprintf(
            '%s #%d: %s names %s #%d, which is not in the backup.',
            $update['table'],
            $update['new_id'],
            $update['column'],
            $update['parent_table'],
            $update['old_parent_id'],
        ));
    }

    /**
     * The columns {@see RowTransformer::remapJsonIds()} reads for `$table`:
     * its JSON columns holding ids, and any its `when` conditions test.
     *
     * @return array<string, true>
     */
    private function jsonIdColumns(string $table): array
    {
        $columns = [];

        foreach (RowTransformer::JSON_ID_PATHS[$table] ?? [] as $spec) {
            $columns[$spec['column']] = true;

            foreach (array_keys($spec['when'] ?? []) as $condition) {
                $columns[$condition] = true;
            }
        }

        return $columns;
    }

    /**
     * Set `$table.$column` on many rows at once: `$values` is row id => value,
     * all integers (restored ids), so they go straight into a CASE.
     *
     * @param  array<int, int>  $values
     */
    private function patchColumn(string $table, string $column, array $values): void
    {
        foreach (array_chunk($values, 500, true) as $chunk) {
            $cases = implode(' ', array_map(
                static fn (int $id, int $value): string => "WHEN {$id} THEN {$value}",
                array_keys($chunk),
                $chunk,
            ));

            DB::table($table)
                ->whereIn('id', array_keys($chunk))
                ->update([$column => DB::raw("CASE id {$cases} END")]);
        }
    }

    /**
     * Whether `$table.$column` accepts null.
     */
    private function isNullable(string $table, string $column): bool
    {
        if (! isset($this->nullableColumns[$table])) {
            $this->nullableColumns[$table] = [];

            foreach (Schema::getColumns($table) as $definition) {
                $this->nullableColumns[$table][$definition['name']] = (bool) $definition['nullable'];
            }
        }

        return $this->nullableColumns[$table][$column] ?? true;
    }

    /**
     * Encrypt the row's encrypted-cast columns with this instance's key.
     *
     * The bundle carries them as plaintext ({@see JsonlTableWriter::ENCRYPTED_COLUMN_ENCODING};
     * BundleInspector refuses any other encoding before the import starts), and
     * the raw insert skips the model's cast. Without this the column would hold
     * the plaintext: unreadable through the cast, and unencrypted at rest. The
     * source instance's key never comes into it, so a bundle restores the same
     * way onto any instance. A bundle from before the manifest recorded the
     * encoding holds plaintext too, since `toArray()` decrypted through the cast.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function encryptColumns(string $modelClass, array $row): array
    {
        $this->encryptedColumns[$modelClass] ??= EncryptedColumns::of($modelClass);

        foreach ($this->encryptedColumns[$modelClass] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = EncryptedColumns::encrypt($modelClass, $row[$column]);
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
}
