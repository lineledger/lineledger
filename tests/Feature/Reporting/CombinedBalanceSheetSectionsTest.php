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
    $this->cashLine = $this->group->lines()->where('name', 'Cash')->firstOrFail(); // asset / bank
});

it('nests a line under its subtype section with a per-company subtotal, leaving the type total unchanged', function () {
    $section = $this->group->sections()->create(['statement' => 'balance_sheet', 'group_key' => 'bank', 'name' => 'Operating Cash', 'sort_order' => 1]);
    $this->cashLine->update(['report_group_section_id' => $section->id]);

    $report = app(CombinedReportCalculator::class)->balanceSheet(
        $this->group,
        CarbonImmutable::create(2026, 4, 1),
    );

    $group = $report['assets']['bank'];
    $block = collect($group['blocks'])->firstWhere('type', 'section');

    expect($group['label'])->toBe('Bank')
        ->and($block)->not->toBeNull()
        ->and($block['name'])->toBe('Operating Cash')
        ->and($block['subtotal'])->toBe(100000)                          // $700 + $300
        ->and($block['by_company'][$this->scenario['a']->id])->toBe(70000)
        ->and($block['by_company'][$this->scenario['b']->id])->toBe(30000)
        ->and($report['total_assets'])->toBe(100000)                     // unchanged
        ->and($report['total_le'])->toBe($report['total_assets']);       // still balances
});

it('renders the subtype section subtotal and exports', function () {
    $section = $this->group->sections()->create(['statement' => 'balance_sheet', 'group_key' => 'bank', 'name' => 'Operating Cash', 'sort_order' => 1]);
    $this->cashLine->update(['report_group_section_id' => $section->id]);

    $component = Livewire::test('pages::report-groups.balance-sheet', ['reportGroup' => $this->group])
        ->set('asOf', '2026-04-01')
        ->assertOk()
        ->assertSee('Operating Cash')
        ->assertSeeHtml('data-test="cbs-section-subtotal-'.$section->id.'"');

    $response = $component->instance()->exportCsv();
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Operating Cash')
        ->and($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});
