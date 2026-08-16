<?php

use App\Services\Merge\AccountReferenceRegistry;
use App\Services\Merge\ContactReferenceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Unit tests are not auto-bound to Laravel's TestCase by Pest.php (only Feature is),
// so we opt-in here because we need both the application container (for Schema) and
// migrations (so the schema is populated regardless of local DB state / CI driver).
uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: class-string, 1: string, 2: callable(string): bool} registry, target table, column-name matcher
 */
function mergeRegistryCases(): array
{
    return [
        'accounts' => [
            AccountReferenceRegistry::class,
            'accounts',
            fn (string $column): bool => $column === 'account_id' || str_ends_with($column, '_account_id'),
        ],
        'contacts' => [
            ContactReferenceRegistry::class,
            'contacts',
            fn (string $column): bool => $column === 'contact_id' || str_ends_with($column, '_contact_id'),
        ],
    ];
}

/**
 * @return list<string> "table.column" entries from columns() + excludedColumns()
 */
function knownMergeColumns(string $registry): array
{
    $known = collect($registry::columns())
        ->merge($registry::excludedColumns())
        ->map(fn (array $entry): string => $entry['table'].'.'.$entry['column'])
        ->all();

    return $known;
}

test('every column matching the FK name pattern is in the registry or explicitly excluded', function () {
    foreach (mergeRegistryCases() as $target => [$registry, $targetTable, $matches]) {
        $known = knownMergeColumns($registry);

        $missing = [];
        foreach (collect(Schema::getTables())->pluck('name') as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if ($matches($column) && ! in_array($table.'.'.$column, $known, true)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        expect($missing)->toBe([], sprintf(
            'Columns matching the %s reference pattern are missing from %s::columns() AND excludedColumns(): %s. '.
            'Add each one to the repoint list or exclude it with a reason.',
            $target,
            class_basename($registry),
            implode(', ', $missing)
        ));
    }
});

test('every foreign key targeting accounts/contacts is in the registry or explicitly excluded', function () {
    foreach (mergeRegistryCases() as $target => [$registry, $targetTable]) {
        $known = knownMergeColumns($registry);

        $missing = [];
        $fkCount = 0;
        foreach (collect(Schema::getTables())->pluck('name') as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (($fk['foreign_table'] ?? null) !== $targetTable) {
                    continue;
                }

                foreach ($fk['columns'] as $column) {
                    $fkCount++;
                    if (! in_array($table.'.'.$column, $known, true)) {
                        $missing[] = $table.'.'.$column;
                    }
                }
            }
        }

        expect($missing)->toBe([], sprintf(
            'Foreign keys targeting %s are missing from %s::columns() AND excludedColumns(): %s.',
            $targetTable,
            class_basename($registry),
            implode(', ', $missing)
        ));

        // The FK scan must actually have seen something — if the driver returns
        // no FK metadata this whole pass would silently assert nothing.
        expect($fkCount)->toBeGreaterThan(0, "Schema::getForeignKeys() returned no FKs targeting {$targetTable}; the FK pass is not running.");
    }
});

test('odd-named reference columns the name-pattern pass cannot see are registered', function () {
    // Belt-and-braces for the FK pass: these reference columns do NOT match the
    // `*_account_id` / `*_contact_id` name patterns, so if Schema::getForeignKeys()
    // ever degrades on a driver, this hard-coded list still guards them.
    $accountColumns = collect(AccountReferenceRegistry::columns())
        ->map(fn (array $entry): string => $entry['table'].'.'.$entry['column']);

    expect($accountColumns)->toContain('accounts.parent_id');

    $contactColumns = collect(ContactReferenceRegistry::columns())
        ->map(fn (array $entry): string => $entry['table'].'.'.$entry['column']);

    expect($contactColumns)
        ->toContain('time_entries.customer_id')
        ->toContain('invoices.sales_rep_id')
        ->toContain('estimates.sales_rep_id')
        ->toContain('sales_orders.sales_rep_id')
        ->toContain('credit_memos.sales_rep_id');
});

test('no column appears in both the repoint list and the excluded list', function () {
    foreach (mergeRegistryCases() as [$registry]) {
        $repoint = collect($registry::columns())
            ->map(fn (array $entry): string => $entry['table'].'.'.$entry['column']);

        $excluded = collect($registry::excludedColumns())
            ->map(fn (array $entry): string => $entry['table'].'.'.$entry['column']);

        $both = $repoint->intersect($excluded)->values()->all();

        expect($both)->toBe([], sprintf(
            '%s lists these columns as both repoint and excluded: %s',
            class_basename($registry),
            implode(', ', $both)
        ));

        // And neither list may carry duplicates.
        expect($repoint->duplicates()->values()->all())->toBe([]);
        expect($excluded->duplicates()->values()->all())->toBe([]);
    }
});

test('every registry entry exists in the live schema', function () {
    foreach (mergeRegistryCases() as [$registry]) {
        $entries = collect($registry::columns())->merge($registry::excludedColumns());

        if (method_exists($registry, 'morphTables')) {
            foreach ($registry::morphTables() as $morph) {
                $entries->push(['table' => $morph['table'], 'column' => $morph['typeColumn']]);
                $entries->push(['table' => $morph['table'], 'column' => $morph['idColumn']]);
            }
        }

        foreach ($entries as $entry) {
            expect(Schema::hasTable($entry['table']))->toBeTrue(sprintf(
                '%s references table [%s] which does not exist.',
                class_basename($registry),
                $entry['table']
            ));

            expect(Schema::hasColumn($entry['table'], $entry['column']))->toBeTrue(sprintf(
                '%s references column [%s.%s] which does not exist (typo or stale row?).',
                class_basename($registry),
                $entry['table'],
                $entry['column']
            ));
        }
    }
});
