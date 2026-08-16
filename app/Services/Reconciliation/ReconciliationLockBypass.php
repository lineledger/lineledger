<?php

namespace App\Services\Reconciliation;

/**
 * Thread-local bypass used by the reconciliation service when it must post or
 * void its own service-charge / interest entries during undo — those entries
 * are dated on the still-completed reconciliation's statement date, which the
 * BankReconciliationLockGuard would otherwise block.
 */
class ReconciliationLockBypass
{
    protected static int $depth = 0;

    public static function silence(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
