<?php

namespace App\Actions\Portal;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\PaymentMethod;

/**
 * Ensures a company that connects Stripe has the ledger accounts and payment
 * method the portal needs, created lazily so companies that never collect cards
 * keep a clean chart:
 *   - "Stripe Clearing" (current asset): receives the full invoice amount; the
 *     payout to the bank later clears it.
 *   - "Merchant Processing Fees" (expense): the per-charge Stripe fee.
 *   - "Card (Stripe)" payment method: tags portal receipts.
 *
 * Idempotent: matches existing rows by name within the company.
 *
 * @phpstan-type StripeAccounts array{clearing: Account, fees: Account, method: PaymentMethod}
 */
final class EnsureStripeAccounts
{
    private const CLEARING_NAME = 'Stripe Clearing';

    private const FEES_NAME = 'Merchant Processing Fees';

    private const METHOD_NAME = 'Card (Stripe)';

    /**
     * @return StripeAccounts
     */
    public function handle(Company $company): array
    {
        return [
            'clearing' => $this->account($company, self::CLEARING_NAME, AccountSubtype::CurrentAsset, '1250'),
            'fees' => $this->account($company, self::FEES_NAME, AccountSubtype::Expense, '6015'),
            'method' => $this->paymentMethod($company),
        ];
    }

    private function account(Company $company, string $name, AccountSubtype $subtype, string $preferredCode): Account
    {
        $existing = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Account::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => $this->freeCode($company, $preferredCode),
            'name' => $name,
            'type' => $subtype->type(),
            'subtype' => $subtype,
            'normal_balance' => $subtype->type()->normalBalance(),
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    /**
     * Find the first unused account code at or after the preferred one within the
     * company, so seeding never collides with the existing chart.
     */
    private function freeCode(Company $company, string $preferredCode): string
    {
        $taken = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->pluck('code')
            ->all();

        $code = (int) $preferredCode;

        while (in_array((string) $code, $taken, true)) {
            $code++;
        }

        return (string) $code;
    }

    private function paymentMethod(Company $company): PaymentMethod
    {
        return PaymentMethod::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $company->id, 'name' => self::METHOD_NAME],
            ['is_cheque' => false, 'is_active' => true],
        );
    }
}
