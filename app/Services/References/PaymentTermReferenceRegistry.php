<?php

namespace App\Services\References;

/**
 * Every database column that references `payment_terms.id`. Deleting terms
 * one of these still names would, through the FKs' SET NULL, erase the terms
 * a document was issued on, so the delete is refused while one does (see
 * {@see RecordReferences}).
 *
 * Guarded by tests/Unit/References/ListReferenceRegistryTest.php.
 */
final class PaymentTermReferenceRegistry
{
    /**
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            ['table' => 'invoices', 'column' => 'terms_id'],
            ['table' => 'bills', 'column' => 'terms_id'],
            ['table' => 'estimates', 'column' => 'terms_id'],
            ['table' => 'sales_orders', 'column' => 'terms_id'],
            ['table' => 'purchase_orders', 'column' => 'terms_id'],
            ['table' => 'recurring_documents', 'column' => 'terms_id'],
            ['table' => 'contacts', 'column' => 'default_terms_id'],
            ['table' => 'membership_levels', 'column' => 'default_terms_id'],
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
