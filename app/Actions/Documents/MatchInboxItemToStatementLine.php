<?php

namespace App\Actions\Documents;

use App\Enums\StatementLineMatchStatus;
use App\Models\BankStatementLine;
use App\Models\Expense;
use App\Models\InboxItem;
use App\Services\Posting\ExpensePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Matches a reviewed inbox receipt to an imported bank/credit-card transaction.
 *
 * Rather than open a second posting path (and risk double-counting the charge),
 * this reuses the expense pipeline: it promotes the receipt to an expense PAID
 * FROM the bank line's own account — so the GST splits out as an ITC exactly as
 * everywhere else — posts it through {@see ExpensePoster}, then stamps the bank
 * line as Created against that entry. The single posted entry both records the
 * expense and clears the statement line; the receipt rides along on the expense
 * (carried by {@see PromoteInboxItem}).
 */
final class MatchInboxItemToStatementLine
{
    public function __construct(
        private readonly PromoteInboxItem $promote,
        private readonly ExpensePoster $poster,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  the same shape PromoteInboxItem takes
     */
    public function handle(InboxItem $item, BankStatementLine $line, array $overrides): Expense
    {
        if ($line->created_journal_entry_id !== null) {
            throw new RuntimeException('That bank transaction has already been recorded.');
        }

        return DB::transaction(function () use ($item, $line, $overrides): Expense {
            // The bank line dictates which account the money left and when — pay the
            // expense from it, dated to the transaction, so posting credits that
            // account and the legs reconcile against the statement.
            $overrides['document_type'] = 'expense';
            $overrides['payment_account_id'] = $line->account_id;
            $overrides['date'] = CarbonImmutable::parse($line->txn_date)->toDateString();

            /** @var Expense $expense */
            $expense = $this->promote->handle($item, $overrides);

            $entry = $this->poster->post($expense);

            // The bank-account leg of the new entry is what the statement line clears.
            $bankLeg = $entry->lines()->where('account_id', $line->account_id)->first();

            $line->forceFill([
                'suggested_account_id' => $line->suggested_account_id,
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $bankLeg?->id,
                'match_status' => StatementLineMatchStatus::Created->value,
            ])->save();

            return $expense->refresh();
        });
    }
}
