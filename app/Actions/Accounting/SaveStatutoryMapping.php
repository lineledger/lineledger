<?php

namespace App\Actions\Accounting;

use App\Enums\JurisdictionCapability;
use App\Models\Account;
use App\Support\Statutory\UkStatutoryCatalog;

/**
 * Maps an account onto the UK statutory accounts: the line it presents on and
 * the caption it groups under. Used by the statutory accounts report page.
 *
 * Expected $data shape (leave a key out to keep its value):
 *   statutory_line:   ?string  (a UkStatutoryCatalog line; anything else clears
 *                              it, so the account falls back to its default)
 *   statutory_label:  ?string  (trimmed to 120 characters; blank clears it)
 *
 * Only UK companies prepare these accounts; for any other the mapping is left
 * alone, as SaveAccount does with GIFI codes outside Canada.
 */
final class SaveStatutoryMapping
{
    /** The width of accounts.statutory_label. */
    public const LABEL_MAX = 120;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Account $account, array $data): Account
    {
        if (! $account->company->supports(JurisdictionCapability::UkStatutoryAccounts)) {
            return $account;
        }

        $attributes = [];

        if (array_key_exists('statutory_line', $data)) {
            $line = $data['statutory_line'];
            $attributes['statutory_line'] = is_string($line) && UkStatutoryCatalog::exists($line) ? $line : null;
        }

        if (array_key_exists('statutory_label', $data)) {
            $label = trim(mb_substr(trim((string) $data['statutory_label']), 0, self::LABEL_MAX));
            $attributes['statutory_label'] = $label === '' ? null : $label;
        }

        $account->forceFill($attributes)->save();

        return $account;
    }
}
