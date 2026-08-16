<?php

namespace App\Enums;

enum TaxAppliesTo: string
{
    case SaleOnly = 'sale_only';
    case PurchaseOnly = 'purchase_only';
    case Both = 'both';

    public function appliesToSales(): bool
    {
        return $this === self::SaleOnly || $this === self::Both;
    }

    public function appliesToPurchases(): bool
    {
        return $this === self::PurchaseOnly || $this === self::Both;
    }
}
