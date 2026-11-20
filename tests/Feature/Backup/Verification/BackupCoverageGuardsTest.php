<?php

use App\Services\Backup\BackupScopes;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Backup\JsonlTableWriter;
use App\Services\Backup\Verification\RestoreExpectations;
use App\Services\Restore\CompanyImporter;
use App\Services\Restore\RowTransformer;
use Illuminate\Support\Facades\Schema;

/**
 * Arch guards for the backup format: each makes a new column or table an
 * explicit decision, so a backup can't quietly stop being complete.
 */
it('has decided, for every JSON column on a backed-up table, whether it holds row ids', function () {
    $undecided = [];

    foreach (BackupTableRegistry::tables() as $entry) {
        $table = $entry['table'];
        $withIds = array_column(RowTransformer::JSON_ID_PATHS[$table] ?? [], 'column');
        $withoutIds = array_keys(RowTransformer::JSON_WITHOUT_IDS[$table] ?? []);
        $quirk = $table === 'document_folders' ? ['viewer_member_ids'] : []; // RowTransformer::applyTableQuirks()

        foreach (CompanyImporter::jsonCastColumns($entry['model']) as $column) {
            if (! in_array($column, [...$withIds, ...$withoutIds, ...$quirk], true)) {
                $undecided[] = "{$table}.{$column}";
            }
        }
    }

    expect($undecided)->toBe([], 'A JSON column that holds row ids must be in RowTransformer::JSON_ID_PATHS (or a restore copies the ids of another organization\'s rows); one that does not goes in JSON_WITHOUT_IDS.');
});

it('declares only JSON columns that exist, pointing at backed-up tables', function () {
    $stale = [];

    foreach ([RowTransformer::JSON_ID_PATHS, array_map('array_keys', RowTransformer::JSON_WITHOUT_IDS)] as $declared) {
        foreach ($declared as $table => $entries) {
            $model = BackupTableRegistry::modelFor($table);
            $json = $model === null ? [] : CompanyImporter::jsonCastColumns($model);

            foreach ($entries as $entry) {
                $column = is_array($entry) ? $entry['column'] : $entry;

                if (! in_array($column, $json, true)) {
                    $stale[] = "{$table}.{$column}";
                }

                if (is_array($entry) && BackupTableRegistry::modelFor($entry['table']) === null) {
                    $stale[] = "{$table}.{$column} → {$entry['table']}";
                }
            }
        }
    }

    expect($stale)->toBe([]);
});

it('has decided, for every hidden column on a backed-up model, whether the backup carries it', function () {
    $undecided = [];

    foreach (BackupTableRegistry::tables() as $entry) {
        foreach ((new $entry['model'])->getHidden() as $column) {
            if (! array_key_exists($column, JsonlTableWriter::HIDDEN_COLUMNS[$entry['table']] ?? [])) {
                $undecided[] = "{$entry['table']}.{$column}";
            }
        }
    }

    expect($undecided)->toBe([], 'A hidden column is left out of a backup unless JsonlTableWriter::HIDDEN_COLUMNS says otherwise: decide which.');

    foreach (JsonlTableWriter::HIDDEN_COLUMNS as $table => $columns) {
        expect((new (BackupTableRegistry::modelFor($table)))->getHidden())->toContain(...array_keys($columns));
    }
});

it('scopes exactly the backed-up tables without a company_id through their parent', function () {
    $withoutCompanyId = array_values(array_filter(
        array_column(BackupTableRegistry::tables(), 'table'),
        static fn (string $table): bool => $table !== 'companies' && ! Schema::hasColumn($table, 'company_id'),
    ));

    expect(array_keys(BackupScopes::CHILD_TABLES))->toEqualCanonicalizing($withoutCompanyId);

    foreach (BackupScopes::CHILD_TABLES as $table => [$foreignKey, $parentModel]) {
        expect(Schema::hasColumn($table, $foreignKey))->toBeTrue("{$table}.{$foreignKey}")
            ->and(Schema::hasColumn((new $parentModel)->getTable(), 'company_id'))->toBeTrue($parentModel);
    }
});

it('has a dangling-reference policy only for columns that cannot be null', function () {
    foreach (RowTransformer::DANGLING_REFERENCE_POLICY as $table => $columns) {
        $definitions = collect(Schema::getColumns($table))->keyBy('name');

        foreach (array_keys($columns) as $column) {
            expect($definitions[$column]['nullable'] ?? null)->toBeFalse("{$table}.{$column}");
        }
    }
});

it('treats as personal only tables unique per organization and user', function () {
    foreach (RowTransformer::PERSONAL_TABLES as $table) {
        $unique = collect(Schema::getIndexes($table))
            ->filter(fn (array $index): bool => $index['unique'] && array_intersect(['company_id', 'user_id'], $index['columns']) === ['company_id', 'user_id']);

        expect($unique)->not->toBeEmpty($table);
    }
});

it('lists only real columns in the restore expectations', function () {
    foreach ([RestoreExpectations::RESTORE, RestoreExpectations::EXPORT] as $expectations) {
        foreach ($expectations as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                expect(Schema::hasColumn($table, $column))->toBeTrue("{$table}.{$column}");
            }
        }
    }
});

it('restores the audit trail last, after every table its rows can name', function () {
    $tables = array_column(BackupTableRegistry::tables(), 'table');

    expect(end($tables))->toBe('accounting_audit_logs');
});
