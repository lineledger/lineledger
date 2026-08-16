<?php

namespace App\Enums;

/**
 * QuickBooks-style product/service typing. Inventory is the only type that tracks
 * quantity on hand and posts COGS; the others are flat sell-through lines. Bundle
 * is a group of other items sold together (expanded into component lines on a sale).
 */
enum ItemType: string
{
    case Service = 'service';
    case NonInventory = 'non_inventory';
    case OtherCharge = 'other_charge';
    case Inventory = 'inventory';
    case Bundle = 'bundle';

    public function label(): string
    {
        return match ($this) {
            self::Service => __('Service'),
            self::NonInventory => __('Non-inventory'),
            self::OtherCharge => __('Other charge'),
            self::Inventory => __('Inventory'),
            self::Bundle => __('Bundle'),
        };
    }

    public function tracksInventory(): bool
    {
        return $this === self::Inventory;
    }
}
