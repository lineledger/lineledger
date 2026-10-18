<?php

namespace App\Support\Translation;

/**
 * Seeded payment-method names (Canadian, American and generic defaults),
 * registered so lang/fr.json can translate them on display while the stored
 * name stays English.
 */
final class PaymentMethodCopy
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            __('ACH'),
            __('BACS'),
            __('Bank Transfer'),
            __('Cash'),
            __('Check'),
            __('Cheque'),
            __('Credit card'),
            __('Direct Debit'),
            __('E-transfer'),
            __('EFT'),
            __('Faster Payments'),
            __('Wire'),
        ];
    }
}
