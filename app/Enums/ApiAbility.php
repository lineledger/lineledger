<?php

namespace App\Enums;

/**
 * Coarse-grained API key scopes, expressed as `{domain}:{action}`. A `write`
 * scope implies the matching `read` (resolved at check time in
 * CompanyApiKey::hasAbility). A key with no abilities has full access.
 */
enum ApiAbility: string
{
    case SalesRead = 'sales:read';
    case SalesWrite = 'sales:write';
    case PurchasesRead = 'purchases:read';
    case PurchasesWrite = 'purchases:write';
    case BankingRead = 'banking:read';
    case BankingWrite = 'banking:write';
    case AccountingRead = 'accounting:read';
    case AccountingWrite = 'accounting:write';
    case InventoryRead = 'inventory:read';
    case InventoryWrite = 'inventory:write';
    case TaxRead = 'tax:read';
    case TaxWrite = 'tax:write';
    case SettingsRead = 'settings:read';
    case SettingsWrite = 'settings:write';

    /**
     * Human-friendly label for the settings UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::SalesRead => 'Sales — read',
            self::SalesWrite => 'Sales — write',
            self::PurchasesRead => 'Purchases — read',
            self::PurchasesWrite => 'Purchases — write',
            self::BankingRead => 'Banking — read',
            self::BankingWrite => 'Banking — write',
            self::AccountingRead => 'Accounting — read',
            self::AccountingWrite => 'Accounting — write',
            self::InventoryRead => 'Inventory — read',
            self::InventoryWrite => 'Inventory — write',
            self::TaxRead => 'Tax — read',
            self::TaxWrite => 'Tax — write',
            self::SettingsRead => 'Settings — read',
            self::SettingsWrite => 'Settings — write',
        };
    }

    /**
     * The domain portion (e.g. "sales").
     */
    public function domain(): string
    {
        return explode(':', $this->value)[0];
    }

    /**
     * Whether this scope grants write access.
     */
    public function isWrite(): bool
    {
        return str_ends_with($this->value, ':write');
    }

    /**
     * All scope values as a flat list (useful for validation).
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $a): string => $a->value, self::cases());
    }
}
