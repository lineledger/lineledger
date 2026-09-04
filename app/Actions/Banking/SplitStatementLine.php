<?php

namespace App\Actions\Banking;

use App\Actions\Purchasing\SaveExpense;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Models\TaxCode;
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
 *
 * A payee applies to the whole split: the expense's payee (one per expense —
 * expense lines carry no contact), or the default contact on each deposit line
 * (a line's own contact_id still wins). Split parts may carry purchase tax
 * codes; the part's amount is then the tax-inclusive gross, exactly as a
 * single-line Add.
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
     * @param  array<int, array{account_id: int, contact_id?: int|null, amount_cents: int, description?: string|null, tax_code_id?: int|null, secondary_tax_code_id?: int|null}>  $splits
     */
    public function handle(BankStatementLine $line, array $splits, ?int $payeeContactId = null): void
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

        DB::transaction(function () use ($line, $splits, $payeeContactId): void {
            $account = $line->account()->firstOrFail();
            $date = CarbonImmutable::parse($line->txn_date)->toDateString();
            $memo = ($line->description !== null && $line->description !== '') ? $line->description : null;
            $payee = $payeeContactId !== null ? $this->resolvePayee($line, $payeeContactId) : null;
            $taxCodes = $this->resolveTaxCodes($line, $splits);

            if ((int) $line->amount_cents >= 0) {
                $deposit = $this->saveDeposit->handle([
                    'bank_account_id' => $account->id,
                    'deposit_no' => null,
                    'deposit_date' => $date,
                    'memo' => $memo,
                    'lines' => array_map(fn (array $s): array => [
                        'account_id' => $s['account_id'],
                        'contact_id' => $s['contact_id'] ?? $payee?->id,
                        'amount_cents' => (int) $s['amount_cents'],
                        'description' => $s['description'] ?? $memo,
                    ], $splits),
                ]);
                $entry = $this->depositPoster->post($deposit);
            } else {
                $expense = $this->saveExpense->handle([
                    'payment_account_id' => $account->id,
                    'expense_date' => $date,
                    'payee_contact_id' => $payee?->id,
                    'payee_name' => $payee->display_name ?? ($memo ?? __('Bank transaction')),
                    'memo' => $memo,
                    'lines' => array_map(fn (array $s): array => [
                        'account_id' => $s['account_id'],
                        'amount_cents' => (int) $s['amount_cents'], // the gross for this part
                        'description' => $s['description'] ?? $memo,
                        'tax_code_id' => $taxCodes[(int) ($s['tax_code_id'] ?? 0)] ?? null,
                        'secondary_tax_code_id' => $taxCodes[(int) ($s['secondary_tax_code_id'] ?? 0)] ?? null,
                        'amount_includes_tax' => isset($taxCodes[(int) ($s['tax_code_id'] ?? 0)]),
                    ], $splits),
                ]);
                $entry = $this->expensePoster->post($expense);
            }

            $bankLine = $entry->lines()->where('account_id', $account->id)->firstOrFail();

            $line->forceFill([
                'suggested_contact_id' => $payee->id ?? $line->suggested_contact_id,
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $bankLine->id,
                'match_status' => StatementLineMatchStatus::Created->value,
            ])->save();
        });
    }

    private function resolvePayee(BankStatementLine $line, int $contactId): Contact
    {
        $contact = Contact::query()
            ->where('company_id', $line->company_id)
            ->whereKey($contactId)
            ->first();

        if ($contact === null) {
            throw new PostingValidationException(__('That contact no longer exists — choose another.'));
        }

        return $contact;
    }

    /**
     * Every tax code the parts reference, validated as active purchase codes of
     * this company, keyed by id. A part naming the same code twice is refused.
     *
     * @param  array<int, array<string, mixed>>  $splits
     * @return array<int, int> id => id
     */
    private function resolveTaxCodes(BankStatementLine $line, array $splits): array
    {
        $ids = [];

        foreach ($splits as $split) {
            $primary = isset($split['tax_code_id']) && $split['tax_code_id'] !== '' ? (int) $split['tax_code_id'] : null;
            $secondary = isset($split['secondary_tax_code_id']) && $split['secondary_tax_code_id'] !== '' ? (int) $split['secondary_tax_code_id'] : null;

            if ($primary !== null && $primary === $secondary) {
                throw new PostingValidationException(__('Choose two different tax codes.'));
            }

            foreach ([$primary, $secondary] as $id) {
                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $found = TaxCode::query()
            ->where('company_id', $line->company_id)
            ->usableForPurchases()
            ->whereIn('id', array_values($ids))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($found) !== count($ids)) {
            throw new PostingValidationException(__('That tax code is no longer available for purchases — choose another.'));
        }

        return array_combine($found, $found);
    }
}
