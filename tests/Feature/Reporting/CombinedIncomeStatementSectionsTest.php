<?php

use App\Enums\CompanyRole;
use App\Services\Reporting\CombinedReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Reuses combinedScenario() from CombinedReportsTest.

beforeEach(function () {
    $this->scenario = combinedScenario();
    $this->group = $this->scenario['group'];

    // The group creator must belong to every member company to view the report.
    $this->scenario['a']->members()->attach($this->scenario['user'], ['role' => CompanyRole::Owner->value]);
    $this->scenario['b']->members()->attach($this->scenario['user'], ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->scenario['user']);
    $this->expenseLine = $this->group->lines()->where('name', 'Expenses')->firstOrFail();
});

it('nests an assigned line under its section with a per-company subtotal', function () {
    $section = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expenseLine->update(['report_group_section_id' => $section->id]);

    $report = app(CombinedReportCalculator::class)->incomeStatement(
        $this->group,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    $block = collect($report['expense'])->firstWhere('type', 'section');

    expect($block)->not->toBeNull()
        ->and($block['name'])->toBe('Operating')
        ->and($block['id'])->toBe($section->id)
        ->and($block['subtotal'])->toBe(50000)                          // $300 + $200
        ->and($block['by_company'][$this->scenario['a']->id])->toBe(30000)
        ->and($block['by_company'][$this->scenario['b']->id])->toBe(20000)
        ->and($report['total_expense'])->toBe(50000);                   // unchanged
});

it('renders the section header and subtotal and exports', function () {
    $section = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expenseLine->update(['report_group_section_id' => $section->id]);

    $component = Livewire::test('pages::report-groups.income-statement', ['reportGroup' => $this->group])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertOk()
        ->assertSee('Operating')
        ->assertSeeHtml('data-test="cis-section-subtotal-'.$section->id.'"');

    $response = $component->instance()->exportCsv();
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Operating')
        ->and($csv)->toContain('Total Operating')
        ->and($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});
