<?php

use App\Services\Backup\BackupTableRegistry;
use App\Services\Restore\RowTransformer;

/**
 * Arch test: every parent FK declared in {@see RowTransformer::PARENT_FK_MAP}
 * must reference a table that comes EARLIER in {@see BackupTableRegistry::tables()}.
 *
 * Without this invariant, the importer's first-pass `DB::table()->insert()` on
 * the child table fails with a FOREIGN KEY constraint violation because the
 * referenced row hasn't been restored yet (e.g., `tax_agencies.payable_account_id`
 * → `accounts.id` requires `accounts` to load before `tax_agencies`).
 *
 * Self-references (e.g., `accounts.parent_id` → `accounts.id`) are allowed
 * because parent ids in the bundle precede children by export order.
 */
it('every parent FK references a table earlier in BackupTableRegistry order', function () {
    $tables = BackupTableRegistry::tables();
    $orderIndex = [];
    foreach ($tables as $i => $entry) {
        $orderIndex[$entry['table']] = $i;
    }

    // The companies row's own account-FK columns are handled by an explicit
    // post-loop UPDATE in CompanyImporter::import() (predates DEFERRED_FK_COLUMNS).
    // They're legitimately deferred — the shell-create strips them.
    $companiesDeferred = [
        'default_inventory_asset_account_id',
        'default_cogs_account_id',
        'exchange_gain_loss_account_id',
        'unrealized_gain_loss_account_id',
    ];

    $violations = [];

    foreach (RowTransformer::PARENT_FK_MAP as $childTable => $fkColumns) {
        $childIdx = $orderIndex[$childTable] ?? null;
        if ($childIdx === null) {
            continue;
        }

        foreach ($fkColumns as $column => $parentTable) {
            if ($parentTable === $childTable) {
                continue;
            }

            // Allowed exceptions: explicit cross-cycle deferred FKs and the
            // companies-row's pre-existing deferred defaults.
            if (isset(RowTransformer::DEFERRED_FK_COLUMNS[$childTable][$column])) {
                continue;
            }
            if ($childTable === 'companies' && in_array($column, $companiesDeferred, true)) {
                continue;
            }

            $parentIdx = $orderIndex[$parentTable] ?? null;
            if ($parentIdx === null) {
                continue;
            }

            if ($parentIdx >= $childIdx) {
                $violations[] = sprintf(
                    '%s.%s -> %s: parent at index %d is not earlier than child at index %d.',
                    $childTable,
                    $column,
                    $parentTable,
                    $parentIdx,
                    $childIdx,
                );
            }
        }
    }

    expect($violations)->toBe([], "Registry FK-ordering violations:\n  - ".implode("\n  - ", $violations));
});
