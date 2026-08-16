<?php

namespace App\Actions\Banking;

use App\Actions\Purchasing\SaveExpense;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\BankStatementLine;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\ExpensePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Posts one bank statement line split across several categories: an inflow
 * becomes a multi-line Deposit, an outflow a multi-line Expense, picked purely
 * by the line's sign. The split amounts must add up to the transaction's exact
 * total. Reuses the existing Save + Poster pipelines, then stamps the line so it
 * reads as Created and ties to its entry, exactly like a single-category "Add".
 */
final class SplitStatementLine
{
    public function __construct(
        private readonly SaveDeposit $saveDeposit,
        private readonly DepositPoster $depositPoster,
        private readonly SaveExpense $saveExpense,
        private readonly ExpensePoster $expensePoster,
    ) {}

    /**
     * @param  array<int, array{account_id: int, contact_id?: int|null, amount_cents: int, description?: string|null}>  $splits
     */
    public function handle(BankStatementLine $line, array $splits): void
    {
        if ($line->created_journal_entry_id !== null) {
            throw new PostingValidationException(__('This line has already been added — undo it before splitting.'));
        }

        $splits = array_values($splits);
        $total = array_sum(array_map(fn (array $s): int => (int) $s['amount_cents'], $splits));
        $target = abs((int) $line->amount_cents);

        if ($splits === [] || $total !== $target) {
            throw new PostingValidationException(__('Split amounts must add up to the transaction total.'));
        }

        if (array_filter($splits, fn (array $s): bool => (int) $s['amount_cents'] <= 0) !== []) {
            throw new PostingValidationException(__('Each split amount must be greater than zero.'));
        }

        DB::transaction(function () use ($line, $splits): void {
            $account = $line->account()->firstOrFail();
            $date = CarbonImmutable::parse($line->txn_date)->toDateString();
            $memo = ($line->description !== null && $line->description !== '') ? $line->description : null;

            if ((int) $line->amount_cents >= 0) {
                $deposit = $this->saveDeposit->handle([
                    'bank_account_id' => $account->id,
                    'deposit_no' => null,
                    'deposit_date' => $date,
                    'memo' => $memo,
                    'lines' => array_map(fn (array $s): array => [
                        'account_id' => $s['account_id'],
                        'contact_id' => $s['contact_id'] ?? null,
                        'amount_cents' => (int) $s['amount_cents'],
                        'description' => $s['description'] ?? $memo,
                    ], $splits),
                ]);
                $entry = $this->depositPoster->post($deposit);
            } else {
                $expense = $this->saveExpense->handle([
                    'payment_account_id' => $account->id,
                    'expense_date' => $date,
                    'payee_name' => $memo ?? __('Bank transaction'),
                    'memo' => $memo,
                    'lines' => array_map(fn (array $s): array => [
                        'account_id' => $s['account_id'],
                        'amount_cents' => (int) $s['amount_cents'],
                        'description' => $s['description'] ?? $memo,
                    ], $splits),
                ]);
                $entry = $this->expensePoster->post($expense);
            }

            $bankLine = $entry->lines()->where('account_id', $account->id)->firstOrFail();

            $line->forceFill([
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $bankLine->id,
                'match_status' => StatementLineMatchStatus::Created->value,
            ])->save();
        });
    }
}
