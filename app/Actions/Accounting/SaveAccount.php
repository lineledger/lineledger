<?php

namespace App\Actions\Accounting;

use App\Enums\AccountSubtype;
use App\Enums\CashFlowActivity;
use App\Enums\JurisdictionCapability;
use App\Models\Account;
use App\Support\Gifi\GifiCatalog;
use App\Support\Reporting\CashFlowBucket;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a chart-of-accounts account. Shared by the Livewire
 * Chart of Accounts page and the API. Pure CRUD — there is no poster.
 *
 * System accounts (is_system) and accounts that already carry journal lines
 * keep their subtype/type/normal_balance fixed; their code and name remain
 * editable. The caller is responsible for rejecting subtype changes (the API
 * does so with a 422). This Action never overwrites the protected derivations.
 *
 * Expected $data shape:
 *   code:           string
 *   name:           string
 *   subtype:            string   (AccountSubtype value; ignored for system accounts)
 *   gifi_code:          ?string  (GIFI line code; cleared when unrecognised)
 *   default_tax_code_id: ?int    (default tax code for Income / Expense accounts;
 *                                 falsy values clear it)
 *   parent_id:          ?int
 *   description:        ?string
 *   is_active:          ?bool
 *   cash_flow_activity: ?string  (CashFlowActivity value; ignored for accounts
 *                                 that are not their own cash-flow activity line)
 */
final class SaveAccount
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Account $account = null): Account
    {
        return DB::transaction(function () use ($data, $account): Account {
            $subtype = AccountSubtype::from($data['subtype']);

            // Subtype/type/normal_balance are frozen on system accounts and on any
            // account that already carries journal lines (draft or posted) — retyping
            // a transacted account would silently rewrite history on every report.
            $typeLocked = $account !== null
                && ($account->is_system || $account->journalLines()->exists());

            $attributes = [
                'name' => $data['name'],
                'parent_id' => $data['parent_id'] ?? null,
                'description' => $data['description'] ?? null,
            ];

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = (bool) $data['is_active'];
            }

            // Code is editable everywhere; subtype/type derivations stay protected on system accounts.
            $attributes['code'] = $data['code'];

            // GIFI line mapping (Canadian CRA reporting). Persist only recognised
            // codes; anything unknown clears the mapping rather than storing junk.
            if (array_key_exists('gifi_code', $data) && app('current_company')->supports(JurisdictionCapability::GifiCodeMapping)) {
                $attributes['gifi_code'] = ($data['gifi_code'] && GifiCatalog::find((string) $data['gifi_code']) !== null)
                    ? (string) $data['gifi_code']
                    : null;
            }
            // Optional account-level default tax code (used to pre-fill
            // transaction lines). Falsy values clear it.
            if (array_key_exists('default_tax_code_id', $data)) {
                $attributes['default_tax_code_id'] = $data['default_tax_code_id'] ?: null;
            }

            if (! $typeLocked) {
                $attributes['subtype'] = $subtype;
                $attributes['type'] = $subtype->type();
                $attributes['normal_balance'] = $subtype->type()->normalBalance();
            }

            // Only Bank / Credit Card accounts may be foreign-denominated, and the
            // currency is fixed once the account carries any posted activity.
            if (array_key_exists('currency_code', $data) && $this->currencyEligible($subtype, $account)) {
                $attributes['currency_code'] = $this->normalizeCurrency($data['currency_code']);
            }

            // The cash-flow activity override only sticks for accounts that are
            // already their own activity line; Bank / P&L accounts (null default)
            // never carry one, so we clear it to keep the column clean.
            if (array_key_exists('cash_flow_activity', $data)) {
                $effectiveType = $typeLocked ? $account->type : $subtype->type();
                $effectiveSubtype = $typeLocked ? $account->subtype : $subtype;
                $default = CashFlowBucket::forValues($effectiveType, $effectiveSubtype);

                $attributes['cash_flow_activity'] = ($default !== null && $data['cash_flow_activity'])
                    ? CashFlowActivity::from($data['cash_flow_activity'])
                    : null;
            }

            if ($account && $account->exists) {
                $account->update($attributes);

                return $account;
            }

            return Account::create($attributes + [
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    private function currencyEligible(AccountSubtype $subtype, ?Account $account): bool
    {
        // Foreign denomination is only meaningful once the company has enabled
        // multi-currency, and only on Bank / Credit Card accounts.
        if (! app('current_company')->isMulticurrencyEnabled()) {
            return false;
        }

        if (! in_array($subtype, [AccountSubtype::Bank, AccountSubtype::CreditCard], true)) {
            return false;
        }

        // Lock the currency once the account has posted lines.
        return $account === null || ! $account->journalLines()->exists();
    }

    private function normalizeCurrency(?string $code): ?string
    {
        $code = $code !== null ? mb_strtoupper(trim($code)) : null;

        if ($code === null || $code === '' || app('current_company')->isHomeCurrency($code)) {
            return null;
        }

        return $code;
    }
}
