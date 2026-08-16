<?php

namespace App\Mcp\Tools;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Account;
use App\Models\Contact;
use App\Services\Reporting\ReportCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class FinancialSummaryTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Financial summary';

    protected string $description = 'A plain-language snapshot of how the business is doing: income and expenses for the period, net income for the fiscal year to date, cash in the bank, and total money owed to and by the company. All figures are in the company\'s home currency.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $company = $this->company();
        $calculator = app(ReportCalculator::class);
        $period = $this->resolvePeriod($request);

        $income = $calculator->totalForType($company, AccountType::Income, $period['start'], $period['end']);
        $expense = $calculator->totalForType($company, AccountType::Expense, $period['start'], $period['end']);
        $periodNet = $income - $expense;

        $netIncomeYtd = $calculator->netIncomeYtd($company, $period['end']);

        $cashOnHand = Account::query()
            ->where('subtype', AccountSubtype::Bank)
            ->get()
            ->sum(fn (Account $account): int => $calculator->balanceAsOf($account, $period['end']));

        $totalAr = (int) Contact::query()->where('is_customer', true)->sum('ar_balance_cents');
        $totalAp = (int) Contact::query()->where('is_vendor', true)->sum('ap_balance_cents');

        $lines = [
            "Financial summary for {$company->name} ({$period['label']}):",
            '',
            "• Income: {$this->money($income)}",
            "• Expenses: {$this->money($expense)}",
            '• '.($periodNet >= 0 ? 'Profit' : 'Loss').' for the period: '.$this->money(abs($periodNet)),
            "• Net income, fiscal year to date: {$this->money($netIncomeYtd)}",
            "• Cash in the bank: {$this->money((int) $cashOnHand)}",
            "• Owed to you (accounts receivable): {$this->money($totalAr)}",
            "• You owe (accounts payable): {$this->money($totalAp)}",
        ];

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()
                ->description('A friendly reporting window: this_month, last_month, this_quarter, last_quarter, this_year, last_year, or ytd (default).'),
            'start' => $schema->string()
                ->description('Optional explicit period start (ISO date, e.g. 2026-01-01). Use with "end" instead of "period".'),
            'end' => $schema->string()
                ->description('Optional explicit period end (ISO date, e.g. 2026-03-31). Use with "start" instead of "period".'),
        ];
    }
}
