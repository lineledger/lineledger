<?php

namespace App\Support\Preferences;

use App\Models\Company;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Screen settings the signed-in operator chose last time — the bank account,
 * a report filter, a register toggle — so a screen reopens the way they left
 * it. Stored per user and per company in `user_preferences`, so a choice
 * survives sign-out and session expiry and follows the user across devices.
 *
 * A *convenience default only*: callers validate what comes back exactly as
 * they would a query-string value, and an explicit URL parameter or memorized
 * report still wins. With no authenticated user (console, queue) reads return
 * the default and writes are dropped.
 */
final class UserPreferences
{
    public static function get(Company $company, string $key, mixed $default = null): mixed
    {
        $userId = Auth::id();

        if ($userId === null) {
            return $default;
        }

        $row = self::query($company, (int) $userId, $key)->first();

        return $row === null ? $default : $row->value;
    }

    /**
     * Record a choice. Null forgets it, so the screen falls back to its default.
     */
    public static function put(Company $company, string $key, mixed $value): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        if ($value === null) {
            self::query($company, (int) $userId, $key)->delete();

            return;
        }

        UserPreference::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => (int) $userId, 'pref_key' => $key],
            ['value' => $value],
        );
    }

    /**
     * The company is passed explicitly rather than read from the bound
     * `current_company`, so the lookup is the same with or without the scope.
     *
     * @return Builder<UserPreference>
     */
    private static function query(Company $company, int $userId, string $key)
    {
        return UserPreference::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('user_id', $userId)
            ->where('pref_key', $key);
    }
}
