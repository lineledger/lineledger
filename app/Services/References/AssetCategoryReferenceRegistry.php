<?php

namespace App\Services\References;

/**
 * Every database column that references `asset_categories.id`. An asset category a fixed asset still belongs to. A delete is
 * refused while one does (see {@see RecordReferences}).
 *
 * Guarded by tests/Unit/References/ListReferenceRegistryTest.php: every
 * live-schema column matching `asset_category_id`, and every FK targeting `asset_categories`,
 * must appear in columns() or excludedColumns().
 */
final class AssetCategoryReferenceRegistry
{
    /**
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            ['table' => 'assets', 'column' => 'asset_category_id'],
        ];
    }

    /**
     * @return list<array{table: string, column: string, reason: string}>
     */
    public static function excludedColumns(): array
    {
        return [];
    }
}
