<?php

namespace App\Mcp\Tools\Write;

use App\Enums\Section;
use App\Mcp\Concerns\ProposesWrites;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Proposes a simple cash/bank expense — money already paid out, recorded directly
 * to the ledger as a two-line journal entry (debit the expense account, credit the
 * account it was paid from). No accounts-payable bill is created. Validates both
 * accounts read-only, stages the payload, and returns a token — writing NOTHING.
 * Confirm to commit through SaveJournalEntry + JournalPoster.
 */
class ProposeExpenseTool extends Tool
{
    use ProposesWrites;

    protected string $title = 'Propose Expense';

    protected string $description = 'Stage a simple paid expense (money already spent, no vendor bill). Provide an "expense_account" (the expense category, name or code), a "paid_from" account (the bank/cash/credit-card account, name or code), an "amount" in dollars, and a "date" (YYYY-MM-DD). Optional: "memo", "post" (default true — false creates a draft). It records a two-line journal entry: debit the expense, credit the paid-from account. This tool only proposes: it returns a token and a preview and writes nothing. Call the confirm-proposal tool with the token to commit. System/control accounts are rejected.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAgenticWritesEnabled()) {
            return $denied;
        }
        if ($denied = $this->requireAbility('journal-entries:write')) {
            return $denied;
        }
        if ($denied = $this->requireSection(Section::Accounting)) {
            return $denied;
        }

        $date = $request->get('date');
        if (! is_string($date) || $date === '') {
            return Response::error('A "date" (YYYY-MM-DD) is required.');
        }

        $expense = $this->resolveAccount($request->get('expense_account') ?? $request->get('expense_account_id'));
        $paidFrom = $this->resolveAccount($request->get('paid_from') ?? $request->get('paid_from_account_id'));

        $amountCents = $this->toCents($request->get('amount'), $request->get('amount_cents'));
        if ($amountCents === null || $amountCents <= 0) {
            return Response::error('A positive "amount" in dollars is required.');
        }

        if ($denied = $this->rejectSystemAccounts([1 => $expense, 2 => $paidFrom])) {
            return $denied;
        }

        $post = $request->get('post', true) !== false;
        $memo = $request->get('memo') ?: null;

        $payload = [
            'entry_date' => CarbonImmutable::parse($date)->toDateString(),
            'memo' => $memo,
            'lines' => [
                ['account_id' => $expense->id, 'debit_cents' => $amountCents, 'credit_cents' => 0, 'memo' => $memo],
                ['account_id' => $paidFrom->id, 'debit_cents' => 0, 'credit_cents' => $amountCents, 'memo' => $memo],
            ],
            '_post' => $post,
        ];

        $preview = [
            'PROPOSED EXPENSE'.($post ? ' (will be posted on confirm)' : ' (draft only)'),
            "Date: {$payload['entry_date']}",
            'Amount: '.$this->money($amountCents),
            "Debit (expense):  {$expense->code} {$expense->name}",
            "Credit (paid from): {$paidFrom->code} {$paidFrom->name}",
            ...($memo !== null ? ["Memo: {$memo}"] : []),
        ];

        if ($post && ($warning = $this->lockWarning(CarbonImmutable::parse($date)))) {
            $preview[] = $warning;
        }

        return $this->stageProposal('expense', $payload, $preview);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_account' => $schema->string()->description('The expense category account name or code.'),
            'paid_from' => $schema->string()->description('The bank/cash/credit-card account the money was paid from (name or code).'),
            'amount' => $schema->number()->description('Amount paid, in dollars.'),
            'date' => $schema->string()->description('Date of the expense, ISO YYYY-MM-DD.'),
            'memo' => $schema->string()->description('Optional memo / description.'),
            'post' => $schema->boolean()->description('Post to the ledger on confirm (default true). False creates a draft.'),
        ];
    }
}
