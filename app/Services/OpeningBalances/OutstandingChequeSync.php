<?php

namespace App\Services\OpeningBalances;

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\OpeningBalanceState;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Posting\ChequePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Outstanding (uncashed) cheques carried over from the previous system. Each
 * one is a REAL posted Cheque at its original date with a single line to
 * Opening Balance Equity — DR OBE / CR Bank — so the bank journal line exists
 * uncleared and a future reconciliation can tick it when it finally cashes.
 *
 * The maintained opening entry's netting then lands the bank's as-of GL on the
 * book target automatically (its own line becomes the statement-side balance),
 * which is why every mutation here ends with an apply.
 */
class OutstandingChequeSync
{
    public function __construct(
        protected ChequePoster $chequePoster,
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
        protected OpeningBalanceJournalSynchronizer $synchronizer,
    ) {}

    /**
     * @param  array{bank_account_id: int, cheque_no: string, cheque_date: CarbonImmutable, payee_name: string, amount_cents: int, memo?: ?string}  $data
     * @param  bool  $apply  Pass false from bulk imports and apply once at the end.
     */
    public function create(OpeningBalanceState $state, array $data, bool $apply = true): Cheque
    {
        $bank = $this->bankAccountOrFail($state, (int) $data['bank_account_id']);
        $obe = $this->openingBalanceAccounts->resolveOrFail((int) $state->company_id);

        if ((int) $data['amount_cents'] <= 0) {
            throw new RuntimeException('Cheque amount must be greater than zero.');
        }

        // Friendlier than the unique-index violation it prevents. The index
        // spans voided and soft-deleted rows too, so check the same way.
        $duplicate = Cheque::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $state->company_id)
            ->where('bank_account_id', $bank->id)
            ->where('cheque_no', $data['cheque_no'])
            ->exists();

        if ($duplicate) {
            throw new RuntimeException("Cheque number {$data['cheque_no']} is already used on {$bank->name}.");
        }

        $cheque = DB::transaction(function () use ($state, $data, $bank, $obe): Cheque {
            $cheque = Cheque::create([
                'company_id' => $state->company_id,
                'bank_account_id' => $bank->id,
                'cheque_no' => $data['cheque_no'],
                'cheque_date' => $data['cheque_date'],
                'payee_name' => $data['payee_name'],
                'memo' => $data['memo'] ?? 'Outstanding at conversion — carried over',
                'status' => ChequeStatus::Draft,
                'is_opening_balance' => true,
            ]);

            $cheque->lines()->create([
                'account_id' => $obe->id,
                'description' => 'Opening balance — outstanding cheque',
                'amount_cents' => (int) $data['amount_cents'],
                'line_order' => 0,
            ]);

            $this->chequePoster->post($cheque->fresh());

            return $cheque->refresh();
        });

        if ($apply) {
            $this->synchronizer->applyQuietly($state);
        }

        return $cheque;
    }

    /**
     * @param  array{cheque_no?: string, cheque_date?: CarbonImmutable, payee_name?: string, amount_cents?: int, memo?: ?string}  $data
     */
    public function update(OpeningBalanceState $state, Cheque $cheque, array $data): Cheque
    {
        $this->guardOpening($cheque);

        DB::transaction(function () use ($cheque, $data): void {
            $cheque->fill(array_intersect_key($data, array_flip(['cheque_no', 'cheque_date', 'payee_name', 'memo'])))->save();

            if (array_key_exists('amount_cents', $data)) {
                if ((int) $data['amount_cents'] <= 0) {
                    throw new RuntimeException('Cheque amount must be greater than zero.');
                }

                $cheque->lines()->update(['amount_cents' => (int) $data['amount_cents']]);
            }

            $this->chequePoster->repost($cheque->refresh());
        });

        $this->synchronizer->applyQuietly($state);

        return $cheque->refresh();
    }

    public function remove(OpeningBalanceState $state, Cheque $cheque): void
    {
        $this->guardOpening($cheque);

        $original = CarbonImmutable::parse($cheque->cheque_date);
        $voidDate = $state->company->isLockedFor($original) ? null : $original;

        $this->chequePoster->void($cheque, $voidDate);

        $this->synchronizer->applyQuietly($state);
    }

    protected function guardOpening(Cheque $cheque): void
    {
        if (! $cheque->is_opening_balance) {
            throw new RuntimeException('Not an opening-balance cheque — edit it from the cheques screen.');
        }

        if ($cheque->voided_at !== null) {
            throw new RuntimeException('Cheque is already voided.');
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

        // v1 is home-currency only: a foreign bank's opening items need an
        // as-of exchange rate story this workspace does not have yet.
        if ($account->currency_code !== null && ! $state->company->isHomeCurrency($account->currency_code)) {
            throw new RuntimeException('Foreign-currency bank accounts are not supported here yet — record that opening balance with a journal entry at the correct exchange rate.');
        }

        return $account;
    }
}
