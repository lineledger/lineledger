<?php

namespace App\Mcp\Prompts;

use App\Mcp\Concerns\AnswersBusinessQuestions;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class SalesTaxFilingPrepPrompt extends Prompt
{
    use AnswersBusinessQuestions;

    protected string $title = 'Sales tax filing prep';

    protected string $description = 'Prepares a sales-tax filing: the tax collected, paid, and net owed per agency for a period, with the rates and codes behind the figures and what to remit.';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:30'],
        ], [
            'period.*' => 'Provide a filing period such as "this_quarter" or "last_quarter".',
        ]);

        $period = $validated['period'] ?? 'this_quarter';
        $company = $this->company();
        $taxLabel = $company->jurisdiction->taxLabel();

        $text = <<<TEXT
        Prepare the {$taxLabel} filing for {$company->name} for the period "{$period}". Use this server's read-only tools and resources.

        1. Call the sales-tax tool with period="{$period}" to get tax collected, paid, and net owed per agency.
        2. Read the tax-codes resource (lineledger://tax/codes) to see each agency's codes, rates, and registration numbers.

        Then produce a filing-prep summary:
        - Net {$taxLabel} owed (or refundable) per agency, and the total.
        - The rates/codes that produced those figures, so the numbers are explainable.
        - A short remittance checklist (which agency, how much, and the registration number to file under).

        All figures are already in {$company->name}'s home currency and come from the posted general ledger. This is preparation only — it does not file anything; remind the user to verify against the agency's portal before filing.
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
                description: 'The filing period: this_month, last_month, this_quarter, last_quarter, this_year, last_year, or ytd. Defaults to this_quarter.',
                required: false,
            ),
        ];
    }
}
