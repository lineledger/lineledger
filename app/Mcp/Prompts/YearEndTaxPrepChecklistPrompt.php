<?php

namespace App\Mcp\Prompts;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Tax\FilingProfile;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class YearEndTaxPrepChecklistPrompt extends Prompt
{
    use AnswersBusinessQuestions;

    protected string $title = 'Year-end tax-prep checklist';

    protected string $description = 'An entity-aware year-end checklist: the CRA return(s) this company files (T2 / T2125 / T5013 / T3010), plus the steps and reports needed to get the books filing-ready.';

    public function handle(Request $request): Response
    {
        // Embeds the company's filing profile, so gate on Reports (where tax
        // returns live) for OAuth-authenticated members.
        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $validated = $request->validate([
            'fiscal_year' => ['nullable', 'string', 'max:9'],
        ], [
            'fiscal_year.*' => 'Optionally provide a fiscal year label, e.g. "2025".',
        ]);

        $company = $this->company();
        $fyLabel = filled($validated['fiscal_year'] ?? null)
            ? "fiscal year {$validated['fiscal_year']}"
            : 'the most recently completed fiscal year';

        $profile = FilingProfile::for($company);
        $formsBlock = $this->formsBlock($profile);
        $gifiNote = $profile->mapsGifiCodes()
            ? "\n\nThis return's financial statements are built on GIFI lines (Schedule 100 balance sheet / Schedule 125 income statement). Read the gifi-catalog resource (lineledger://reference/gifi) and the chart-of-accounts resource (lineledger://accounts/chart), and verify every active account carries an appropriate GIFI code."
            : '';

        $text = <<<TEXT
        Prepare a year-end tax checklist for {$company->name} covering {$fyLabel}. Use this server's read-only tools and resources.

        Applicable CRA return(s) for this company:
        {$formsBlock}

        Read the company-profile resource (lineledger://company/profile) for the exact fiscal-year start and end dates, then work through:
        1. Confirm the fiscal year is complete and the books for it are (or are about to be) locked.
        2. Reconcile all bank and credit-card accounts through the fiscal year end.
        3. Call the trial-balance tool as of the fiscal year end and confirm total debits equal total credits.
        4. Call the balance-sheet and profit-and-loss tools for the fiscal year and review them for reasonableness.{$gifiNote}

        Then produce a checklist with each item marked done / needs attention based on what the tools show, and a short list of anything to resolve before filing. This is preparation only — recommend a review by an accountant before filing.
        TEXT;

        return Response::text($text);
    }

    private function formsBlock(FilingProfile $profile): string
    {
        $forms = $profile->forms();

        if ($forms === []) {
            return '- No CRA corporate/business return is on file for this company (non-Canadian, or an organization type without a CRA return). Confirm filing obligations with an accountant.';
        }

        $lines = [];
        foreach ($forms as $entry) {
            $form = $entry['form'];
            $primary = $entry['primary'] ? ' (primary return)' : '';
            $lines[] = "- {$form->code()}: {$form->label()}{$primary}\n  {$entry['note']}\n  CRA reference: {$form->craReference()}";
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'fiscal_year',
                description: 'Optional fiscal year to prepare for, e.g. "2025". Defaults to the most recently completed fiscal year.',
                required: false,
            ),
        ];
    }
}
