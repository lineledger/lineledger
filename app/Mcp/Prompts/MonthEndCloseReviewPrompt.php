<?php

namespace App\Mcp\Prompts;

use App\Mcp\Concerns\AnswersBusinessQuestions;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class MonthEndCloseReviewPrompt extends Prompt
{
    use AnswersBusinessQuestions;

    protected string $title = 'Month-end close review';

    protected string $description = 'Guided month-end (or period) close: pulls the P&L, balance sheet, and AR/AP for a period and asks for a concise summary with anomalies flagged.';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:30'],
        ], [
            'period.*' => 'Provide a period such as "last_month", "this_quarter", or "ytd".',
        ]);

        $period = $validated['period'] ?? 'last_month';
        $company = $this->company();

        $text = <<<TEXT
        You are reviewing the books for {$company->name} for the period "{$period}" as part of a month-end close. Use this server's read-only tools.

        Do the following, in order:
        1. Call the profit-and-loss tool with period="{$period}" and summarize income, expenses, and the resulting net profit or loss.
        2. Call the balance-sheet tool (as of the end of the period) and confirm Assets = Liabilities + Equity.
        3. Call the accounts-receivable and accounts-payable tools and note total outstanding and anything past due.

        Then give a concise close summary that:
        - States the net profit or loss for the period.
        - Flags anomalies: balances that are negative where they shouldn't be, unusually large swings versus a typical month, stale AR/AP, or accounts that normally have activity but show none.
        - Lists follow-up items a bookkeeper should investigate before locking the period.

        All figures are already in {$company->name}'s home currency and come from the posted general ledger.
        TEXT;

        return Response::text($text);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'period',
                description: 'Reporting window: this_month, last_month, this_quarter, last_quarter, this_year, last_year, or ytd. Defaults to last_month.',
                required: false,
            ),
        ];
    }
}
