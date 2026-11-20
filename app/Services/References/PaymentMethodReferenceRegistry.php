<?php

namespace App\Services\References;

/**
 * Every database column that references `payment_methods.id`. Deleting a
 * method one of these still names would, through the FKs' SET NULL, erase how
 * a recorded payment was made, so the delete is refused while one does (see
 * {@see RecordReferences}).
 *
 * Guarded by tests/Unit/References/ListReferenceRegistryTest.php.
 */
final class PaymentMethodReferenceRegistry
{
    /**
     * @return list<array{table: string, column: string}>
     */
    public static function columns(): array
    {
        return [
            ['table' => 'customer_receipts', 'column' => 'payment_method_id'],
            ['table' => 'sales_receipts', 'column' => 'payment_method_id'],
            ['table' => 'bill_payments', 'column' => 'payment_method_id'],
            ['table' => 'expenses', 'column' => 'payment_method_id'],
            ['table' => 'tax_return_payments', 'column' => 'payment_method_id'],
            ['table' => 'contacts', 'column' => 'preferred_payment_method_id'],
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
