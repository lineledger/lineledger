<?php

namespace App\Services\Migration;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lets a user confirm or re-point the app's required control accounts onto
 * imported QuickBooks accounts during the setup wizard's import flow.
 *
 * The posting engine looks up control accounts by subtype + is_system (and, for
 * a couple of roles, by exact name). When importing a QB chart, the seeded
 * system accounts remain authoritative unless the user explicitly re-points a
 * role here. Re-pointing demotes the previous account and promotes the chosen
 * one inside a single transaction so there is never a window with two is_system
 * accounts of the same subtype (posting takes the first match, unordered).
 *
 * The chosen account may be of any subtype — the user can point a role at any
 * imported account. On promotion it is re-typed to the role's subtype so the
 * lookup finds it (e.g. a QB "Retained Earnings" equity account becomes the
 * RetainedEarnings system account).
 */
class SystemAccountMapper
{
    /**
     * @return list<SystemAccountRole>
     */
    public function roles(): array
    {
        return [
            new SystemAccountRole('accounts_receivable', 'Accounts Receivable', 'Where unpaid customer invoices land.', AccountSubtype::AccountsReceivable, 'is_system'),
            new SystemAccountRole('accounts_payable', 'Accounts Payable', 'Where unpaid vendor bills land.', AccountSubtype::AccountsPayable, 'is_system'),
            new SystemAccountRole('undeposited_funds', 'Undeposited Funds', 'Holds received payments before they are deposited.', AccountSubtype::UndepositedFunds, 'is_system'),
            new SystemAccountRole('tax_payable', 'Sales Tax Payable', 'Where sales tax collected accumulates.', AccountSubtype::TaxPayable, 'is_system'),
            new SystemAccountRole('employee_reimbursements', 'Employee Reimbursements Payable', 'Owed to employees for out-of-pocket expenses.', AccountSubtype::CurrentLiability, 'is_system+name', 'Employee Reimbursements Payable'),
            new SystemAccountRole('inventory', 'Inventory Asset', 'Value of stock on hand.', AccountSubtype::Inventory, 'is_system', companyColumn: 'default_inventory_asset_account_id'),
            new SystemAccountRole('cogs', 'Cost of Goods Sold', 'Cost of inventory sold.', AccountSubtype::CostOfGoodsSold, 'is_system', companyColumn: 'default_cogs_account_id'),
            new SystemAccountRole('retained_earnings', 'Retained Earnings', 'Accumulated prior-year earnings.', AccountSubtype::RetainedEarnings, 'is_system'),
            new SystemAccountRole('opening_balance_equity', 'Opening Balance Equity', 'Balancing account for opening balances.', AccountSubtype::Equity, 'name', Account::OPENING_BALANCE_EQUITY_NAME, acceptedNames: Account::OPENING_BALANCE_NAMES),
        ];
    }

    /**
     * The account currently fulfilling each role, keyed by role key (null if none).
     *
     * @return array<string, ?Account>
     */
    public function currentMapping(Company $company): array
    {
        $mapping = [];

        foreach ($this->roles() as $role) {
            $query = $this->companyAccounts($company)->where('subtype', $role->subtype->value);

            if ($role->usesIsSystem()) {
                $query->where('is_system', true);
            }

            if ($role->usesName() && $role->matchNames() !== []) {
                $query->whereIn('name', $role->matchNames());
            }

            $mapping[$role->key] = $query->orderBy('code')->first();
        }

        return $mapping;
    }

    /**
     * Accounts the user may point each role at. Every company account is offered
     * (not just same-subtype ones) so an imported account of any subtype can be
     * chosen — e.g. pointing Retained Earnings at a QuickBooks equity account.
     * commit() re-types the chosen account to fit the role.
     *
     * @return array<string, Collection<int, Account>>
     */
    public function candidates(Company $company): array
    {
        $all = $this->companyAccounts($company)->orderBy('code')->get();

        $candidates = [];

        foreach ($this->roles() as $role) {
            $candidates[$role->key] = $all;
        }

        return $candidates;
    }

    /**
     * Apply a chosen mapping atomically. Idempotent — choosing the account that
     * already fulfils a role is a no-op.
     *
     * @param  array<string, int>  $roleToAccountId  role key => chosen account id
     *
     * @throws InvalidArgumentException on cross-role conflicts or incompatible accounts
     */
    public function commit(Company $company, array $roleToAccountId): void
    {
        $this->assertNoDuplicateAccounts($roleToAccountId);

        $roles = collect($this->roles())->keyBy('key');
        $current = $this->currentMapping($company);

        DB::transaction(function () use ($company, $roleToAccountId, $roles, $current) {
            foreach ($roleToAccountId as $roleKey => $accountId) {
                $role = $roles->get($roleKey);

                if ($role === null) {
                    continue;
                }

                $chosen = $this->companyAccounts($company)->whereKey($accountId)->first();

                if ($chosen === null) {
                    throw new InvalidArgumentException("Account [{$accountId}] not found for role [{$roleKey}].");
                }

                $existing = $current[$roleKey] ?? null;

                if ($existing !== null && $existing->getKey() === $chosen->getKey()) {
                    continue; // already fulfils the role
                }

                // Demote the previous account first so there is never a window
                // with two is_system accounts of the same subtype.
                if ($existing !== null) {
                    if ($role->usesIsSystem()) {
                        $existing->forceFill(['is_system' => false]);
                    }

                    if ($role->usesName() && in_array($existing->name, $role->matchNames(), true)) {
                        $existing->forceFill(['name' => $existing->name.' (replaced)']);
                    }

                    $existing->save();
                }

                // Promote the chosen account, re-typing it to the role's subtype
                // so the posting engine's (subtype, is_system) lookup finds it.
                if ($chosen->subtype !== $role->subtype) {
                    $chosen->forceFill([
                        'subtype' => $role->subtype,
                        'type' => $role->subtype->type(),
                        'normal_balance' => $role->subtype->type()->normalBalance(),
                    ]);
                }

                if ($role->usesIsSystem()) {
                    $chosen->forceFill(['is_system' => true]);
                }

                if ($role->usesName() && $role->requiredName !== null) {
                    $chosen->forceFill(['name' => $role->requiredName]);
                }

                $chosen->save();

                if ($role->companyColumn !== null) {
                    $company->forceFill([$role->companyColumn => $chosen->getKey()])->saveQuietly();
                }

                if ($roleKey === 'tax_payable') {
                    TaxAgency::withoutGlobalScopes()
                        ->where('company_id', $company->id)
                        ->update(['payable_account_id' => $chosen->getKey()]);
                }
            }
        });
    }

    /**
     * @return Builder<Account>
     */
    protected function companyAccounts(Company $company)
    {
        return Account::withoutGlobalScopes()->where('company_id', $company->id);
    }

    /**
     * @param  array<string, int>  $roleToAccountId
     */
    protected function assertNoDuplicateAccounts(array $roleToAccountId): void
    {
        $counts = array_count_values($roleToAccountId);
        $dupes = array_keys(array_filter($counts, fn (int $n) => $n > 1));

        if ($dupes !== []) {
            throw new InvalidArgumentException('The same account cannot fulfil more than one control role.');
        }
    }
}
