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
 * Proposes a manual journal entry. Validates each line account, checks that total
 * debits equal total credits, stages the cents payload, and returns a token —
 * writing NOTHING. Confirm to create (and, unless post=false, post) the entry
 * through the real SaveJournalEntry action + JournalPoster.
 */
class ProposeJournalEntryTool extends Tool
{
    use ProposesWrites;

    protected string $title = 'Propose Journal Entry';

    protected string $description = 'Stage a manual double-entry journal entry for confirmation. Provide an "entry_date" (YYYY-MM-DD) and "lines" (each with an "account" name/code and either a "debit" or a "credit" amount in dollars; optional "memo"). Total debits must equal total credits. Optional: "memo", "post" (default true — false creates a draft). This tool only proposes: it returns a token and a preview and writes nothing. Call the confirm-proposal tool with the token to commit. System/control accounts (Accounts Receivable, Accounts Payable, Retained Earnings) are rejected.';

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

        $entryDate = $request->get('entry_date');
        if (! is_string($entryDate) || $entryDate === '') {
            return Response::error('An "entry_date" (YYYY-MM-DD) is required.');
        }

        $rawLines = $request->get('lines');
        if (! is_array($rawLines) || count($rawLines) < 2) {
            return Response::error('A journal entry needs at least two lines.');
        }

        $accounts = [];
        $dataLines = [];
        $totalDebit = 0;
        $totalCredit = 0;
        $previewRows = [];

        foreach (array_values($rawLines) as $i => $line) {
            $lineNo = $i + 1;
            $account = $this->resolveAccount($line['account'] ?? $line['account_id'] ?? null);
            $accounts[$lineNo] = $account;

            $debit = $this->toCents($line['debit'] ?? null, $line['debit_cents'] ?? null) ?? 0;
            $credit = $this->toCents($line['credit'] ?? null, $line['credit_cents'] ?? null) ?? 0;

            if ($debit === 0 && $credit === 0) {
                return Response::error("Line {$lineNo}: provide a non-zero debit or credit.");
            }
            if ($debit !== 0 && $credit !== 0) {
                return Response::error("Line {$lineNo}: a line cannot have both a debit and a credit.");
            }

            $dataLines[] = [
                'account_id' => $account?->id,
                'debit_cents' => $debit,
                'credit_cents' => $credit,
                'memo' => $line['memo'] ?? null,
            ];

            $totalDebit += $debit;
            $totalCredit += $credit;

            $side = $debit !== 0 ? 'DR '.$this->money($debit) : 'CR '.$this->money($credit);
            $previewRows[] = '  - '.($account !== null ? "{$account->code} {$account->name}" : 'UNKNOWN')." {$side}"
                .(! empty($line['memo']) ? " — {$line['memo']}" : '');
        }

        if ($denied = $this->rejectSystemAccounts($accounts)) {
            return $denied;
        }

        if ($totalDebit !== $totalCredit) {
            return Response::error(
                'The entry is out of balance: debits '.$this->money($totalDebit)
                .' do not equal credits '.$this->money($totalCredit).'.'
            );
        }

        $post = $request->get('post', true) !== false;

        $payload = [
            'entry_date' => CarbonImmutable::parse($entryDate)->toDateString(),
            'memo' => $request->get('memo') ?: null,
            'lines' => $dataLines,
            '_post' => $post,
        ];

        $preview = [
            'PROPOSED JOURNAL ENTRY'.($post ? ' (will be posted on confirm)' : ' (draft only)'),
            "Entry date: {$payload['entry_date']}",
            'Lines:',
            ...$previewRows,
            'Total debits = total credits = '.$this->money($totalDebit),
        ];

        if ($post && ($warning = $this->lockWarning(CarbonImmutable::parse($entryDate)))) {
            $preview[] = $warning;
        }

        return $this->stageProposal('journal_entry', $payload, $preview);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entry_date' => $schema->string()->description('Entry date, ISO YYYY-MM-DD.'),
            'memo' => $schema->string()->description('Optional entry memo.'),
            'post' => $schema->boolean()->description('Post to the ledger on confirm (default true). False creates a draft.'),
            'lines' => $schema->array()->items(
                $schema->object([
                    'account' => $schema->string()->description('Account name or code.'),
                    'debit' => $schema->number()->description('Debit amount in dollars (omit if this is a credit line).'),
                    'credit' => $schema->number()->description('Credit amount in dollars (omit if this is a debit line).'),
                    'memo' => $schema->string()->description('Optional line memo.'),
                ])
            )->description('The journal lines; total debits must equal total credits.'),
        ];
    }
}
