<?php

namespace App\Enums;

/**
 * What a role may do within one activity (QuickBooks Desktop Enterprise's
 * "Partial" access): see it, add to it, change it, remove or void it, print or
 * send it, and — for activities that show money held — see balances.
 *
 * Any granted action implies View: a role that may create invoices may also
 * open them.
 */
enum PermissionAction: string
{
    case View = 'view';
    case Create = 'create';
    case Modify = 'modify';
    case Delete = 'delete';
    case Print = 'print';
    case ViewBalance = 'view_balance';

    /**
     * The action's bit in a grant's mask.
     */
    public function bit(): int
    {
        return match ($this) {
            self::View => 1,
            self::Create => 2,
            self::Modify => 4,
            self::Delete => 8,
            self::Print => 16,
            self::ViewBalance => 32,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::View => __('View'),
            self::Create => __('Create'),
            self::Modify => __('Modify'),
            self::Delete => __('Delete'),
            self::Print => __('Print'),
            self::ViewBalance => __('View balance'),
        };
    }

    /**
     * Every action's bit, combined.
     */
    public static function allBits(): int
    {
        return array_reduce(self::cases(), fn (int $mask, self $action): int => $mask | $action->bit(), 0);
    }
}
