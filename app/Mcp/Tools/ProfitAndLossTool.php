<?php

namespace App\Mcp\Tools;

use App\Enums\AccountType;
use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Account;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ProfitAndLossTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Profit and loss';

    protected string $description = 'An income statement for a period: income and expenses broken down by account, with the resulting net profit or loss. Accepts a friendly period (e.g. last_quarter) or explicit start/end dates. All figures are in the company\'s home currency.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $calculator = app(ReportCalculator::class);
        $period = $this->resolvePeriod($request);

        $incomeLines = $this->activityByAccount(AccountType::Income, $period['start'], $period['end'], $calculator);
        $expenseLines = $this->activityByAccount(AccountType::Expense, $period['start'], $period['end'], $calculator);

        $totalIncome = (int) $incomeLines->sum('amount');
        $totalExpense = (int) $expenseLines->sum('amount');
        $net = $totalIncome - $totalExpense;

        $out = ["Profit & loss for {$this->company()->name} ({$period['label']}):", ''];

        $out[] = 'Income';
        $out = array_merge($out, $this->renderLines($incomeLines));
        $out[] = "  Total income: {$this->money($totalIncome)}";
        $out[] = '';

        $out[] = 'Expenses';
        $out = array_merge($out, $this->renderLines($expenseLines));
        $out[] = "  Total expenses: {$this->money($totalExpense)}";
        $out[] = '';

        $out[] = ($net >= 0 ? 'Net profit: ' : 'Net loss: ').$this->money(abs($net));

        return Response::text(implode("\n", $out));
    }

    /**
     * Per-account activity for the period, skipping accounts with no movement.
     *
     * @return Collection<int, array{name: string, amount: int}>
     */
    private function activityByAccount(AccountType $type, CarbonInterface $start, CarbonInterface $end, ReportCalculator $calculator): Collection
    {
        return Account::query()
            ->where('type', $type)
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account): array => [
                'name' => $account->name,
                'amount' => $calculator->periodChange($account, $start, $end),
            ])
            ->reject(fn (array $line): bool => $line['amount'] === 0)
            ->values();
    }

    /**
     * @param  Collection<int, array{name: string, amount: int}>  $lines
     * @return array<int, string>
     */
    private function renderLines(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return ['  (no activity)'];
        }

        return $lines
            ->map(fn (array $line): string => "  {$line['name']}: {$this->money($line['amount'])}")
            ->all();
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
                ->description('Optional explicit period start (ISO date). Use with "end" instead of "period".'),
            'end' => $schema->string()
                ->description('Optional explicit period end (ISO date). Use with "start" instead of "period".'),
        ];
    }
}
