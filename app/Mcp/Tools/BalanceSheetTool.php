<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\AccountType;
use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class BalanceSheetTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Balance Sheet';

    protected string $description = 'Reports total assets, liabilities, and equity as of a date, with the accounting equation (Assets = Liabilities + Equity) shown. Equity includes prior retained earnings and current-year net income exactly as the standard report computes them. This tool is read-only and never changes any data. All figures are in the company\'s home currency.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $asOfInput = $request->get('as_of');
        $asOf = is_string($asOfInput) && trim($asOfInput) !== ''
            ? CarbonImmutable::parse($asOfInput)->startOfDay()
            : $this->company()->currentDateTime()->startOfDay();

        $company = $this->company();
        $calc = app(ReportCalculator::class);

        $assets = $calc->totalForTypeAsOf($company, AccountType::Asset, $asOf);
        $liabilities = $calc->totalForTypeAsOf($company, AccountType::Liability, $asOf);
        $equityPosted = $calc->totalForTypeAsOf($company, AccountType::Equity, $asOf);
        $priorRetainedEarnings = $calc->priorRetainedEarnings($company, $asOf);
        $netIncome = $calc->netIncomeYtd($company, $asOf);

        $totalEquity = $equityPosted + $priorRetainedEarnings + $netIncome;
        $liabilitiesPlusEquity = $liabilities + $totalEquity;

        $asOfLabel = $asOf->toDateString();

        $lines = [
            "Balance sheet as of {$asOfLabel}:",
            '',
            'Total Assets: '.$this->money($assets),
            '',
            'Total Liabilities: '.$this->money($liabilities),
            '',
            'Equity:',
            '  Equity accounts: '.$this->money($equityPosted),
            '  Retained earnings (prior years): '.$this->money($priorRetainedEarnings),
            '  Net income (current year): '.$this->money($netIncome),
            '  Total Equity: '.$this->money($totalEquity),
            '',
            'Liabilities + Equity: '.$this->money($liabilitiesPlusEquity),
            '',
            'Accounting equation: Assets = Liabilities + Equity',
            $this->money($assets).' = '.$this->money($liabilities).' + '.$this->money($totalEquity),
        ];

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->string()
                ->description('Optional as-of date (ISO, e.g. 2026-12-31). Defaults to the company current date.'),
        ];
    }
}
