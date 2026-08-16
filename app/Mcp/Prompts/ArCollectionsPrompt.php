<?php

namespace App\Mcp\Prompts;

use App\Mcp\Concerns\AnswersBusinessQuestions;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class ArCollectionsPrompt extends Prompt
{
    use AnswersBusinessQuestions;

    protected string $title = 'AR collections — who owes me';

    protected string $description = 'Builds a prioritized accounts-receivable collections worklist: the oldest and largest overdue customers, with a suggested follow-up for each.';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:30'],
        ], [
            'period.*' => 'Provide a period such as "ytd" or "this_quarter".',
        ]);

        $period = $validated['period'] ?? 'ytd';
        $company = $this->company();

        $text = <<<TEXT
        Help collect outstanding receivables for {$company->name}. Use this server's read-only tools.

        1. Call the accounts-receivable tool (period="{$period}") to get customer balances and aging.
        2. Identify customers who are overdue, prioritizing by how long they have been outstanding and then by amount.
        3. For the top overdue customers, call the find-contact tool to pull their recent activity and contact details.

        Then produce a collections worklist:
        - A ranked table: customer, amount overdue, age of the oldest overdue item.
        - For each, a short, polite follow-up message the owner could send.
        - A one-line total of overdue receivables and how many customers it spans.

        All figures are already in {$company->name}'s home currency and come from the posted general ledger. Do not assume payment terms you cannot see — ask the user if terms matter.
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
                description: 'Reporting window for receivables activity (e.g. this_quarter, ytd). Defaults to ytd.',
                required: false,
            ),
        ];
    }
}
