<?php

use App\Services\References\AssetCategoryReferenceRegistry;
use App\Services\References\ClassificationReferenceRegistry;
use App\Services\References\FundReferenceRegistry;
use App\Services\References\ItemReferenceRegistry;
use App\Services\References\LocationReferenceRegistry;
use App\Services\References\PaymentMethodReferenceRegistry;
use App\Services\References\PaymentTermReferenceRegistry;
use App\Services\References\TaxCodeReferenceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// Unit tests are not bound to Laravel's TestCase by Pest.php; the schema scan
// needs the container and migrations.
uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array<string, array{0: class-string, 1: string, 2: callable(string): bool}> registry, target table, column-name matcher
 */
function listReferenceRegistryCases(): array
{
    return [
        'tax codes' => [
            TaxCodeReferenceRegistry::class,
            'tax_codes',
            fn (string $column): bool => $column === 'tax_code_id' || str_ends_with($column, '_tax_code_id'),
        ],
        'payment methods' => [
            PaymentMethodReferenceRegistry::class,
            'payment_methods',
            fn (string $column): bool => $column === 'payment_method_id' || str_ends_with($column, '_payment_method_id'),
        ],
        'items' => [
            ItemReferenceRegistry::class,
            'items',
            fn (string $column): bool => $column === 'item_id' || str_ends_with($column, '_item_id'),
        ],
        'classes' => [
            ClassificationReferenceRegistry::class,
            'classifications',
            fn (string $column): bool => $column === 'class_id' || str_ends_with($column, '_class_id'),
        ],
        'locations' => [
            LocationReferenceRegistry::class,
            'locations',
            fn (string $column): bool => $column === 'location_id' || str_ends_with($column, '_location_id'),
        ],
        'funds' => [
            FundReferenceRegistry::class,
            'funds',
            fn (string $column): bool => $column === 'fund_id' || str_ends_with($column, '_fund_id'),
        ],
        'asset categories' => [
            AssetCategoryReferenceRegistry::class,
            'asset_categories',
            fn (string $column): bool => $column === 'asset_category_id' || str_ends_with($column, '_asset_category_id'),
        ],
        'payment terms' => [
            PaymentTermReferenceRegistry::class,
            'payment_terms',
            fn (string $column): bool => $column === 'terms_id' || str_ends_with($column, '_terms_id'),
        ],
    ];
}

/**
 * @param  class-string  $registry
 * @return list<string> "table.column" entries from columns() + excludedColumns()
 */
function knownListReferenceColumns(string $registry): array
{
    return collect($registry::columns())
        ->merge($registry::excludedColumns())
        ->map(fn (array $entry): string => $entry['table'].'.'.$entry['column'])
        ->all();
}

test('every column named like a reference is registered or excluded', function () {
    foreach (listReferenceRegistryCases() as $label => [$registry, , $matches]) {
        $known = knownListReferenceColumns($registry);
        $missing = [];

        foreach (collect(Schema::getTables())->pluck('name') as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if ($matches($column) && ! in_array($table.'.'.$column, $known, true)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }

        expect($missing)->toBe([], sprintf(
            'Columns that look like %s references are missing from %s: %s. Register each one, or exclude it with a reason.',
            $label,
            class_basename($registry),
            implode(', ', $missing),
        ));
    }
});

test('every foreign key into the target table is registered or excluded', function () {
    foreach (listReferenceRegistryCases() as [$registry, $targetTable]) {
        $known = knownListReferenceColumns($registry);
        $missing = [];
        $seen = 0;

        foreach (collect(Schema::getTables())->pluck('name') as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (($fk['foreign_table'] ?? null) !== $targetTable) {
                    continue;
                }

                foreach ($fk['columns'] as $column) {
                    $seen++;

                    if (! in_array($table.'.'.$column, $known, true)) {
                        $missing[] = $table.'.'.$column;
                    }
                }
            }
        }

        expect($missing)->toBe([], sprintf(
            'Foreign keys into %s are missing from %s: %s.',
            $targetTable,
            class_basename($registry),
            implode(', ', $missing),
        ));

        expect($seen)->toBeGreaterThan(0, "Schema::getForeignKeys() found no FK into {$targetTable}; the pass is not running.");
    }
});

test('every registry entry exists in the live schema, once', function () {
    foreach (listReferenceRegistryCases() as [$registry]) {
        $entries = collect($registry::columns())->merge($registry::excludedColumns());

        expect($entries->map(fn (array $e): string => $e['table'].'.'.$e['column'])->duplicates()->values()->all())->toBe([]);

        foreach ($entries as $entry) {
            expect(Schema::hasColumn($entry['table'], $entry['column']))->toBeTrue(sprintf(
                '%s names column [%s.%s], which does not exist.',
                class_basename($registry),
                $entry['table'],
                $entry['column'],
            ));
        }
    }
});
