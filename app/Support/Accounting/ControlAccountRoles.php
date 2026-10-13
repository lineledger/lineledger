<?php

namespace App\Support\Accounting;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\ControlAccountResolver;

/**
 * Which of a company's accounts are AR/AP control accounts, and so what a line
 * coded to one must do: name its counterparty, and carry no sales tax.
 *
 * `journal_lines.contact_id` is the AR/AP sub-ledger — the AR/AP aging reports,
 * the contact statements and the cached `ar_balance_cents` / `ap_balance_cents`
 * all read it and nothing else — so a control-account line without the right
 * contact lands in the reports' "unattributed" catch-all. And the balance it
 * settles already includes the tax its originating invoice or bill recorded, so
 * a line settling it must not be taxed again ({@see excludesTax()}).
 *
 * This map is the single definition the forms and the API both gate on.
 *
 * Matched by subtype rather than `is_system`, so per-currency control accounts
 * (see {@see ControlAccountResolver}) are covered too.
 */
final class ControlAccountRoles
{
    public const CUSTOMER = 'customer';

    public const VENDOR = 'vendor';

    /**
     * Account id => required role, for every AR/AP account of a company. Omit
     * $companyId to lean on CompanyScope (the Livewire forms always have a
     * bound company; the API form requests do too).
     *
     * @return array<int, string>
     */
    public static function map(?int $companyId = null): array
    {
        $roles = [];

        // Queried per subtype rather than compared in PHP: Larastan types
        // Account::$subtype as a plain string, so `=== AccountSubtype::X` reads as
        // always-false and has to be baselined away.
        foreach ([self::CUSTOMER => AccountSubtype::AccountsReceivable, self::VENDOR => AccountSubtype::AccountsPayable] as $role => $subtype) {
            $ids = Account::query()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->where('subtype', $subtype->value)
                ->pluck('id');

            foreach ($ids as $id) {
                $roles[(int) $id] = $role;
            }
        }

        return $roles;
    }

    /**
     * Whether a line coded to this account must not carry sales tax.
     *
     * A control account never does: the receivable or payable already includes
     * the tax its originating invoice or bill recorded, so taxing the
     * settlement a second time double-counts it. On a cheque that is not
     * cosmetic — {@see ChequePoster::expenseByAccount()} grosses a
     * non-recoverable tax into the very AR/AP leg, moving the contact's
     * sub-ledger by more than the payment, and a recoverable code adds an
     * input-tax-credit leg for a credit that was never incurred, overstating
     * the GST/HST return.
     *
     * Deliberately keyed off the same account set as {@see map()}: the two
     * rules are distinct — one names a counterparty, one forbids tax — but
     * both follow from the account being a control account, so they move
     * together.
     *
     * The account id is taken as a form holds it: an int, a numeric string, or
     * '' / null for a line with no account yet (the account picker's "—").
     *
     * @param  array<int, string>  $roles  from {@see map()}, hoisted by the caller
     */
    public static function excludesTax(array $roles, int|string|null $accountId): bool
    {
        return is_numeric($accountId) && isset($roles[(int) $accountId]);
    }

    /**
     * The `contacts` boolean a contact must have set to be valid in this role.
     */
    public static function roleColumn(string $role): string
    {
        return $role === self::CUSTOMER ? 'is_customer' : 'is_vendor';
    }
}
