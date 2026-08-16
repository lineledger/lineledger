<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\JurisdictionCapability;
use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Services\Reporting\Form1099Calculator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class Form1099Tool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Form 1099 Vendor Totals (US)';

    protected string $description = 'Summarize US Form 1099-NEC (Box 1) vendor payment totals for a calendar year: vendors flagged for 1099 tracking and their yearly payment totals, noting the $600 reporting threshold. All figures are in the company\'s home currency. This tool is read-only and never modifies data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('purchases:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $company = $this->company();

        if (! $company->supports(JurisdictionCapability::Form1099)) {
            return Response::text('Form 1099 reporting applies only to US-based companies. This company is not based in the United States, so no 1099 vendor totals are available.');
        }

        $year = (int) ($request->get('year') ?? $company->currentDateTime()->year);

        $start = CarbonImmutable::create($year, 1, 1);
        $end = CarbonImmutable::create($year, 12, 31)->endOfDay();

        $rows = app(Form1099Calculator::class)->rows($company, $start, $end);

        if (empty($rows)) {
            return Response::text("No vendors are flagged for 1099 tracking, so there are no Form 1099 totals to report for {$year}.");
        }

        $threshold = $this->money(Form1099Calculator::THRESHOLD_CENTS);

        $lines = [
            "Form 1099-NEC (Box 1) vendor totals for {$year} (reporting threshold {$threshold}):",
        ];

        foreach ($rows as $row) {
            $name = $row['name'] !== '' ? $row['name'] : 'Unnamed vendor';
            $marker = $row['meets_threshold'] ? ' (meets threshold)' : ' (below threshold)';
            $lines[] = "- {$name}: {$this->money($row['total_cents'])}{$marker}";
        }

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'year' => $schema->integer()
                ->description('Calendar year to report 1099 totals for (e.g. 2025). Defaults to the company\'s current year.'),
        ];
    }
}
