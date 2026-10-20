<?php

namespace App\Support\Banking;

use App\Models\Account;
use App\Models\Company;
use App\Support\Preferences\UserPreferences;
use Illuminate\Support\Collection;

/**
 * Remembers the bank / credit-card account the operator last worked in so every
 * banking screen — the register, statement import, reconcile, and the cheque,
 * deposit, expense and transfer forms — reopens on that account instead of
 * snapping back to the lowest-numbered one.
 *
 * Stored as a {@see UserPreferences} row, so it is per user and per company
 * and survives sign-out and session expiry. It is a *convenience default
 * only*: {@see self::recall()} hands back an id only when it is in the
 * caller's own allowed set, so a remembered
 * account can never widen what a screen would otherwise offer, and each caller
 * keeps its existing fallback for the first visit.
 */
final class LastBankAccount
{
    private const KEY = 'banking.last_account';

    /**
     * Record the account the operator just chose. A null/zero id (the
     * "— Select —" placeholder) clears the memory rather than storing a
     * meaningless default.
     */
    public static function remember(Company $company, int|string|null $accountId): void
    {
        $id = (int) $accountId;

        UserPreferences::put($company, self::KEY, $id > 0 ? $id : null);
    }

    /**
     * The remembered account, or null when nothing is remembered or the
     * remembered account is not one this screen offers (deactivated, wrong
     * subtype, or another company's).
     *
     * @param  iterable<int, Account|int|string>  $allowed  ids or Account models
     */
    public static function recall(Company $company, iterable $allowed): ?int
    {
        $remembered = (int) UserPreferences::get($company, self::KEY);

        if ($remembered <= 0) {
            return null;
        }

        $ids = Collection::make($allowed)
            ->map(fn ($account) => (int) ($account instanceof Account ? $account->id : $account))
            ->all();

        return in_array($remembered, $ids, true) ? $remembered : null;
    }
}
