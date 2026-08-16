<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Services\Reporting\ReportCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CashFlowTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Statement of Cash Flows';

    protected string $description = 'Read-only statement of cash flows for a period. Returns the operating, investing, and financing sections, the net change in cash, and the beginning and ending cash balances. All figures are in the company\'s home currency.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('accounting:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $period = $this->resolvePeriod($request);

        $cashFlow = app(ReportCalculator::class)->cashFlow(
            $this->company(),
            $period['start'],
            $period['end'],
        );

        $lines = [];
        $lines[] = "Statement of Cash Flows for {$this->company()->name} ({$period['label']})";
        $lines[] = '';

        $lines[] = 'Operating activities:';
        $lines[] = "  Net income: {$this->money($cashFlow['net_income'])}";
        foreach ($this->blockRows($cashFlow['operating']) as $row) {
            $lines[] = "  {$row['name']}: {$this->money($row['current'])}";
        }
        $lines[] = "  Net cash from operating activities: {$this->money($cashFlow['total_operating'])}";
        $lines[] = '';

        $lines[] = 'Investing activities:';
        foreach ($this->blockRows($cashFlow['investing']) as $row) {
            $lines[] = "  {$row['name']}: {$this->money($row['current'])}";
        }
        $lines[] = "  Net cash from investing activities: {$this->money($cashFlow['total_investing'])}";
        $lines[] = '';

        $lines[] = 'Financing activities:';
        foreach ($this->blockRows($cashFlow['financing']) as $row) {
            $lines[] = "  {$row['name']}: {$this->money($row['current'])}";
        }
        $lines[] = "  Net cash from financing activities: {$this->money($cashFlow['total_financing'])}";
        $lines[] = '';

        $lines[] = "Net change in cash: {$this->money($cashFlow['net_change'])}";
        $lines[] = "Cash at beginning of period: {$this->money($cashFlow['cash_beginning'])}";
        $lines[] = "Cash at end of period: {$this->money($cashFlow['cash_ending'])}";

        return Response::text(implode("\n", $lines));
    }

    /**
     * Flatten the line rows out of a section's partition blocks.
     *
     * @param  array<int, array{type: string, rows?: array<int, array{name: string, current: int}>}>  $section
     * @return array<int, array{name: string, current: int}>
     */
    protected function blockRows(array $section): array
    {
        $rows = [];

        foreach ($section as $block) {
            foreach ($block['rows'] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()
                ->description('Named period: this_month, last_month, this_quarter, last_quarter, this_year, last_year, or ytd (default).'),
            'start' => $schema->string()
                ->description('Explicit start date (ISO 8601, e.g. 2026-01-01). Overrides "period" when used with "end".'),
            'end' => $schema->string()
                ->description('Explicit end date (ISO 8601, e.g. 2026-03-31). Overrides "period" when used with "start".'),
        ];
    }
}
