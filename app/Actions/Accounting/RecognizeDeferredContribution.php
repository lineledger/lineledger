<?php

namespace App\Actions\Accounting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Posting\JournalPoster;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Recognizes a previously deferred restricted contribution as revenue under the
 * ASNPO deferral method: DR the deferred-contribution liability / CR the matching
 * revenue account. Builds a balanced two-line entry through {@see SaveJournalEntry}
 * and posts it via {@see JournalPoster}, so balance and period-lock enforcement
 * apply. Guards that the recognized amount does not exceed the deferred balance
 * available on the liability as of the recognition date.
 */
final class RecognizeDeferredContribution
{
    public function __construct(
        protected SaveJournalEntry $saveJournalEntry,
        protected JournalPoster $poster,
        protected ReportCalculator $calculator,
    ) {}

    public function handle(
        Company $company,
        int $liabilityAccountId,
        int $revenueAccountId,
        int $amountCents,
        string $date,
        ?string $memo = null,
    ): JournalEntry {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('The amount to recognize must be greater than zero.');
        }

        $liability = $this->resolveAccount($company, $liabilityAccountId, AccountType::Liability);
        $revenue = $this->resolveAccount($company, $revenueAccountId, AccountType::Income);

        $deferredBalance = $this->calculator->balanceAsOf($liability, CarbonImmutable::parse($date));

        if ($amountCents > $deferredBalance) {
            throw new InvalidArgumentException('Cannot recognize more than the deferred balance available.');
        }

        $entry = $this->saveJournalEntry->handle([
            'entry_date' => $date,
            'memo' => $memo ?: __('Recognize deferred contribution'),
            'lines' => [
                ['account_id' => $liability->id, 'debit_cents' => $amountCents, 'credit_cents' => 0],
                ['account_id' => $revenue->id, 'debit_cents' => 0, 'credit_cents' => $amountCents],
            ],
        ]);

        return $this->poster->post($entry);
    }

    /**
     * Resolve and type-check an account that belongs to the company.
     */
    protected function resolveAccount(Company $company, int $accountId, AccountType $type): Account
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereKey($accountId)
            ->first();

        if ($account === null || $account->type !== $type) {
            throw new InvalidArgumentException("Account {$accountId} is not a valid {$type->value} account for this company.");
        }

        return $account;
    }
}
