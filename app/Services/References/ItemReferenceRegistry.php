<?php

namespace App\Services\References;

/**
 * Every database column that references `items.id`. Deleting an item that any of these still names would strip it from document lines, stock history and bundles. A delete is
 * refused while one does (see {@see RecordReferences}).
 *
 * Guarded by tests/Unit/References/ListReferenceRegistryTest.php: every
 * live-schema column matching `item_id` / `*_item_id`, and every FK targeting `items`,
 * must appear in columns() or excludedColumns().
 */
final class ItemReferenceRegistry
{
    /**
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            ['table' => 'bill_lines', 'column' => 'item_id'],
            ['table' => 'credit_memo_lines', 'column' => 'item_id'],
            ['table' => 'estimate_lines', 'column' => 'item_id'],
            ['table' => 'invoice_lines', 'column' => 'item_id'],
            ['table' => 'invoice_template_lines', 'column' => 'item_id'],
            ['table' => 'item_components', 'column' => 'component_item_id'],
            ['table' => 'item_components', 'column' => 'item_id'],
            ['table' => 'purchase_order_lines', 'column' => 'item_id'],
            ['table' => 'recurring_document_lines', 'column' => 'item_id'],
            ['table' => 'sales_order_lines', 'column' => 'item_id'],
            ['table' => 'sales_receipt_lines', 'column' => 'item_id'],
            ['table' => 'stock_adjustment_lines', 'column' => 'item_id'],
            ['table' => 'stock_layers', 'column' => 'item_id'],
            ['table' => 'stock_movements', 'column' => 'item_id'],
            ['table' => 'time_entries', 'column' => 'item_id'],
            ['table' => 'vendor_credit_lines', 'column' => 'item_id'],
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
