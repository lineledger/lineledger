<?php

namespace App\Services\OpeningBalances;

use App\Enums\AccountSubtype;
use App\Enums\DepositStatus;
use App\Models\Account;
use App\Models\Deposit;
use App\Models\OpeningBalanceState;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\DocumentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deposits in transit (recorded in the previous system, not yet on a bank
 * statement) — the mirror of {@see OutstandingChequeSync}. Each is a REAL
 * posted Deposit at its original date with one "other" line to Opening
 * Balance Equity — DR Bank / CR OBE — leaving an uncleared bank line for a
 * future reconciliation.
 */
class DepositInTransitSync
{
    public function __construct(
        protected DepositPoster $depositPoster,
        protected DocumentNumberGenerator $numbers,
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
        protected OpeningBalanceJournalSynchronizer $synchronizer,
    ) {}

    /**
     * @param  array{bank_account_id: int, deposit_date: CarbonImmutable, description?: ?string, amount_cents: int, memo?: ?string}  $data
     * @param  bool  $apply  Pass false from bulk imports and apply once at the end.
     */
    public function create(OpeningBalanceState $state, array $data, bool $apply = true): Deposit
    {
        $bank = $this->bankAccountOrFail($state, (int) $data['bank_account_id']);
        $obe = $this->openingBalanceAccounts->resolveOrFail((int) $state->company_id);

        if ((int) $data['amount_cents'] <= 0) {
            throw new RuntimeException('Deposit amount must be greater than zero.');
        }

        $deposit = DB::transaction(function () use ($state, $data, $bank, $obe): Deposit {
            $deposit = Deposit::create([
                'company_id' => $state->company_id,
                'bank_account_id' => $bank->id,
                'deposit_no' => $this->numbers->next($state->company, Deposit::class, 'deposit_no', 'OBD'),
                'deposit_date' => $data['deposit_date'],
                'memo' => $data['memo'] ?? 'In transit at conversion — carried over',
                'status' => DepositStatus::Draft,
                'is_opening_balance' => true,
            ]);

            $deposit->lines()->create([
                'account_id' => $obe->id,
                'description' => $data['description'] ?? 'Opening balance — deposit in transit',
                'amount_cents' => (int) $data['amount_cents'],
                'line_order' => 0,
            ]);

            $this->depositPoster->post($deposit->fresh());

            return $deposit->refresh();
        });

        if ($apply) {
            $this->synchronizer->applyQuietly($state);
        }

        return $deposit;
    }

    /**
     * @param  array{deposit_date?: CarbonImmutable, description?: ?string, amount_cents?: int, memo?: ?string}  $data
     */
    public function update(OpeningBalanceState $state, Deposit $deposit, array $data): Deposit
    {
        $this->guardOpening($deposit);

        DB::transaction(function () use ($deposit, $data): void {
            $deposit->fill(array_intersect_key($data, array_flip(['deposit_date', 'memo'])))->save();

            if (array_key_exists('amount_cents', $data) || array_key_exists('description', $data)) {
                if (array_key_exists('amount_cents', $data) && (int) $data['amount_cents'] <= 0) {
                    throw new RuntimeException('Deposit amount must be greater than zero.');
                }

                $lineUpdates = array_filter([
                    'amount_cents' => $data['amount_cents'] ?? null,
                    'description' => $data['description'] ?? null,
                ], fn ($v) => $v !== null);

                if ($lineUpdates !== []) {
                    $deposit->lines()->update($lineUpdates);
                }
            }

            $this->depositPoster->repost($deposit->refresh());
        });

        $this->synchronizer->applyQuietly($state);

        return $deposit->refresh();
    }

    public function remove(OpeningBalanceState $state, Deposit $deposit): void
    {
        $this->guardOpening($deposit);

        $original = CarbonImmutable::parse($deposit->deposit_date);
        $voidDate = $state->company->isLockedFor($original) ? null : $original;

        $this->depositPoster->void($deposit, $voidDate);

        $this->synchronizer->applyQuietly($state);
    }

    protected function guardOpening(Deposit $deposit): void
    {
        if (! $deposit->is_opening_balance) {
            throw new RuntimeException('Not an opening-balance deposit — edit it from the deposits screen.');
        }

        if ($deposit->voided_at !== null) {
            throw new RuntimeException('Deposit is already voided.');
        }
    }

    protected function bankAccountOrFail(OpeningBalanceState $state, int $accountId): Account
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $state->company_id)
            ->where('subtype', AccountSubtype::Bank->value)
            ->find($accountId);

        if (! $account) {
            throw new RuntimeException('Choose a bank account.');
        }

        if ($account->currency_code !== null && ! $state->company->isHomeCurrency($account->currency_code)) {
            throw new RuntimeException('Foreign-currency bank accounts are not supported here yet — record that opening balance with a journal entry at the correct exchange rate.');
        }

        return $account;
    }
}
