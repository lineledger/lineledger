<?php

namespace App\Support;

use App\Models\User;

/**
 * Who an operator alert (security anomalies, ledger integrity, failed jobs,
 * stale exchange rates) is emailed to.
 *
 * The address configured for that alert wins. When it is set but blank — as a
 * one-click install leaves it, since nobody's address is known at first boot —
 * the alert goes to every active site admin instead, so it reaches whoever runs
 * the install rather than nobody.
 */
final class AlertRecipients
{
    /**
     * @return list<string> empty when there is nobody to tell
     */
    public static function for(string $configKey): array
    {
        $configured = config($configKey);

        if (is_string($configured) && trim($configured) !== '') {
            return [trim($configured)];
        }

        return User::query()
            ->where('site_admin', true)
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->pluck('email')
            ->filter(fn (mixed $email): bool => is_string($email) && $email !== '')
            ->values()
            ->all();
    }
}
