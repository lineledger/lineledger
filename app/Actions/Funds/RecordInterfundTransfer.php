<?php

namespace App\Actions\Funds;

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Services\Posting\JournalPoster;
use InvalidArgumentException;

/**
 * Moves net assets between two funds under the restricted fund method. Posts a
 * four-line, self-balancing entry: within each fund debits equal credits, so each
 * fund stays its own balanced set of accounts, while the "Interfund Transfers"
 * equity account nets to zero company-wide and the cash simply shifts from one
 * fund column to the other.
 *
 *   Fund A:  DR Interfund Transfers   X  [fund A]   CR Cash   X  [fund A]
 *   Fund B:  DR Cash                  X  [fund B]   CR Interfund Transfers  X  [fund B]
 */
final class RecordInterfundTransfer
{
    public function __construct(
        protected SaveJournalEntry $saveJournalEntry,
        protected JournalPoster $poster,
    ) {}

    public function handle(
        Company $company,
        int $fromFundId,
        int $toFundId,
        int $amountCents,
        string $date,
        ?int $cashAccountId = null,
        ?string $memo = null,
    ): JournalEntry {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('The transfer amount must be greater than zero.');
        }

        if ($fromFundId === $toFundId) {
            throw new InvalidArgumentException('A transfer must be between two different funds.');
        }

        $this->assertFund($company, $fromFundId);
        $this->assertFund($company, $toFundId);

        $cash = $this->resolveCashAccount($company, $cashAccountId);
        $interfund = $this->resolveInterfundAccount($company);

        $entry = $this->saveJournalEntry->handle([
            'entry_date' => $date,
            'memo' => $memo ?: __('Interfund transfer'),
            'lines' => [
                ['account_id' => $interfund->id, 'debit_cents' => $amountCents, 'credit_cents' => 0, 'fund_id' => $fromFundId],
                ['account_id' => $cash->id, 'debit_cents' => 0, 'credit_cents' => $amountCents, 'fund_id' => $fromFundId],
                ['account_id' => $cash->id, 'debit_cents' => $amountCents, 'credit_cents' => 0, 'fund_id' => $toFundId],
                ['account_id' => $interfund->id, 'debit_cents' => 0, 'credit_cents' => $amountCents, 'fund_id' => $toFundId],
            ],
        ]);

        return $this->poster->post($entry);
    }

    protected function assertFund(Company $company, int $fundId): void
    {
        $exists = Fund::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->whereKey($fundId)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException("Fund {$fundId} is not an active fund for this company.");
        }
    }

    protected function resolveCashAccount(Company $company, ?int $cashAccountId): Account
    {
        $query = Account::withoutGlobalScopes()->where('company_id', $company->id);

        $account = $cashAccountId !== null
            ? (clone $query)->whereKey($cashAccountId)->first()
            : (clone $query)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

        if ($account === null) {
            throw new InvalidArgumentException('No cash account is available for the transfer.');
        }

        return $account;
    }

    protected function resolveInterfundAccount(Company $company): Account
    {
        return $company->accounts()->firstOrCreate(
            ['code' => '3950'],
            [
                'name' => 'Interfund Transfers',
                'type' => AccountType::Equity->value,
                'subtype' => AccountSubtype::Equity->value,
                'normal_balance' => NormalBalance::Credit->value,
                'is_active' => true,
            ],
        );
    }
}
