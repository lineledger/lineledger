<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\ReportGroup;
use App\Models\ReportGroupAccountMap;
use App\Models\ReportGroupLine;
use App\Services\Reporting\CombinedReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Reuses combinedScenario(), postEntry() and acctOfType() from CombinedReportsTest.

/**
 * Add an unmapped $400 fixed-asset purchase to Alpha (2026-04-01) and map it onto
 * a new "Equipment" line (Asset / Fixed Asset → investing by default).
 */
function combinedEquipmentLine(array $scenario): ReportGroupLine
{
    $bankA = acctOfType($scenario['a'], AccountType::Asset);
    $fixedAssetA = Account::withoutGlobalScopes()
        ->where('company_id', $scenario['a']->id)
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->orderBy('code')
        ->firstOrFail();

    postEntry($scenario['a'], '2026-04-01', [
        ['account' => $fixedAssetA, 'debit' => 40000],
        ['account' => $bankA, 'credit' => 40000],
    ]);

    $line = ReportGroupLine::create([
        'report_group_id' => $scenario['group']->id,
        'name' => 'Equipment',
        'type' => AccountType::Asset,
        'subtype' => AccountSubtype::FixedAsset,
        'sort_order' => 5,
    ]);
    ReportGroupAccountMap::create([
        'report_group_id' => $scenario['group']->id,
        'report_group_line_id' => $line->id,
        'company_id' => $scenario['a']->id,
        'account_id' => $fixedAssetA->id,
    ]);

    return $line;
}

/**
 * The combined cash flow for calendar 2026.
 */
function combinedCashFlowFor(ReportGroup $group): array
{
    return app(CombinedReportCalculator::class)->cashFlow(
        $group,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );
}

beforeEach(function () {
    $this->scenario = combinedScenario();
    $this->group = $this->scenario['group'];
    $this->a = $this->scenario['a'];
    $this->b = $this->scenario['b'];

    $this->a->members()->attach($this->scenario['user'], ['role' => CompanyRole::Owner->value]);
    $this->b->members()->attach($this->scenario['user'], ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->scenario['user']);
});

it('combined cash flow collapses P&L into net income and reconciles to mapped cash', function () {
    $report = combinedCashFlowFor($this->group);

    // Only Cash / Revenue / Expense are mapped — so the whole statement is the
    // net income line in operating, reconciling to the combined bank movement.
    expect($report['net_income'])->toBe(100000)        // $700 + $300
        ->and($report['total_operating'])->toBe(100000)
        ->and($report['total_investing'])->toBe(0)
        ->and($report['total_financing'])->toBe(0)
        ->and($report['net_change'])->toBe(100000)
        ->and($report['cash_ending'])->toBe(100000)
        ->and($report['reconciles'])->toBeTrue();

    // Net income matches the combined income statement exactly.
    $is = app(CombinedReportCalculator::class)->incomeStatement(
        $this->group,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );
    expect($report['net_income'])->toBe($is['net_income']);
});

it('reports every subtotal per company alongside the combined figure', function () {
    $report = combinedCashFlowFor($this->group);
    $a = $this->a->id;
    $b = $this->b->id;

    expect($report['net_income_by_company'])->toEqual([$a => 70000, $b => 30000])
        ->and($report['total_operating_by_company'])->toEqual([$a => 70000, $b => 30000])
        ->and($report['total_investing_by_company'])->toEqual([$a => 0, $b => 0])
        ->and($report['total_financing_by_company'])->toEqual([$a => 0, $b => 0])
        ->and($report['net_change_by_company'])->toEqual([$a => 70000, $b => 30000])
        ->and($report['cash_beginning_by_company'])->toEqual([$a => 0, $b => 0])
        ->and($report['cash_ending_by_company'])->toEqual([$a => 70000, $b => 30000])
        ->and($report['reconciles_by_company'])->toEqual([$a => true, $b => true]);

    // The per-company columns add up to the combined column.
    expect(array_sum($report['total_operating_by_company']))->toBe($report['total_operating'])
        ->and(array_sum($report['net_change_by_company']))->toBe($report['net_change'])
        ->and(array_sum($report['cash_ending_by_company']))->toBe($report['cash_ending']);
});

it('shows per-company subtotals on the page when the by-company toggle is on', function () {
    $component = Livewire::test('pages::report-groups.cash-flow', ['reportGroup' => $this->group])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->set('byCompany', true)
        ->assertOk()
        ->assertSeeHtml('data-test="cf-total-operating-'.$this->a->id.'">700.00<')
        ->assertSeeHtml('data-test="cf-total-operating-'.$this->b->id.'">300.00<')
        ->assertSeeHtml('data-test="cf-net-change-'.$this->a->id.'">700.00<')
        ->assertSeeHtml('data-test="cf-cash-beginning-'.$this->a->id.'">0.00<')
        ->assertSeeHtml('data-test="cf-cash-ending-'.$this->b->id.'">300.00<')
        ->assertDontSeeHtml('data-test="cf-unreconciled-companies"');

    expect($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class);
});

it('places an investing line under a custom section and renders/exports', function () {
    $line = combinedEquipmentLine($this->scenario);

    $section = $this->group->sections()->create([
        'statement' => 'cash_flow',
        'group_key' => 'investing',
        'name' => 'Capital Expenditure',
        'sort_order' => 1,
    ]);
    $line->update(['report_group_section_id' => $section->id]);

    $report = combinedCashFlowFor($this->group);

    $block = collect($report['investing'])->firstWhere('type', 'section');
    expect($block)->not->toBeNull()
        ->and($block['name'])->toBe('Capital Expenditure')
        ->and($block['subtotal'])->toBe(-40000)
        ->and($report['total_investing'])->toBe(-40000)
        ->and($report['total_investing_by_company'])->toEqual([$this->a->id => -40000, $this->b->id => 0]);

    $component = Livewire::test('pages::report-groups.cash-flow', ['reportGroup' => $this->group])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertOk()
        ->assertSee('Capital Expenditure')
        ->assertSeeHtml('data-test="ccf-section-subtotal-'.$section->id.'"');

    $response = $component->instance()->exportCsv();
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    expect($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});

it('honors a per-line activity override and still reconciles', function () {
    $line = combinedEquipmentLine($this->scenario);
    $line->update(['cash_flow_activity' => CashFlowActivity::Financing]);

    $report = combinedCashFlowFor($this->group);
    $a = $this->a->id;
    $b = $this->b->id;

    expect($report['investing'])->toBe([])
        ->and($report['total_investing'])->toBe(0)
        ->and($report['total_financing'])->toBe(-40000)
        ->and($report['total_financing_by_company'])->toEqual([$a => -40000, $b => 0])
        ->and($report['net_change'])->toBe(60000)                     // unchanged — only the grouping moved
        ->and($report['net_change_by_company'])->toEqual([$a => 30000, $b => 30000])
        ->and($report['cash_ending_by_company'])->toEqual([$a => 30000, $b => 30000])
        ->and($report['reconciles'])->toBeTrue()
        ->and($report['reconciles_by_company'])->toEqual([$a => true, $b => true]);
});

it('moves a line across activities from the sections page and clears its old custom section', function () {
    $line = combinedEquipmentLine($this->scenario);

    $section = $this->group->sections()->create([
        'statement' => 'cash_flow',
        'group_key' => 'investing',
        'name' => 'Capital Expenditure',
        'sort_order' => 1,
    ]);
    $line->update(['report_group_section_id' => $section->id]);

    $page = Livewire::test('pages::report-groups.cash-flow-sections', ['reportGroup' => $this->group])
        ->call('moveLineToActivity', $line->id, 'financing')
        ->assertHasNoErrors();

    $fresh = $line->fresh();
    expect($fresh->cash_flow_activity)->toBe(CashFlowActivity::Financing)
        ->and($fresh->report_group_section_id)->toBeNull(); // old section belonged to investing

    // The report now lists the line under Financing.
    $report = combinedCashFlowFor($this->group);
    $financingLineIds = collect($report['financing'])->flatMap(fn (array $block) => $block['rows'])->pluck('line_id');
    expect($financingLineIds)->toContain($line->id)
        ->and($report['investing'])->toBe([]);

    // Picking the default activity again clears the override rather than storing a no-op.
    $page->call('moveLineToActivity', $line->id, 'investing')->assertHasNoErrors();
    expect($line->fresh()->cash_flow_activity)->toBeNull();
});

it('ignores a sections-page activity move for a line with no activity of its own', function () {
    // Cash is the bank line — it is what the statement explains, so the move is a no-op.
    $cash = $this->group->lines()->where('name', 'Cash')->firstOrFail();

    Livewire::test('pages::report-groups.cash-flow-sections', ['reportGroup' => $this->group])
        ->call('moveLineToActivity', $cash->id, 'financing')
        ->assertHasNoErrors();

    expect($cash->fresh()->cash_flow_activity)->toBeNull();
});

it('stores a cash-flow activity override from the line form only for balance-sheet lines', function () {
    $line = combinedEquipmentLine($this->scenario);

    $page = Livewire::test('pages::report-groups.edit', ['reportGroup' => $this->group])
        ->call('openEditLine', $line->id)
        ->assertSet('f_line_cash_flow_activity', '')
        ->set('f_line_cash_flow_activity', 'financing')
        ->call('saveLine')
        ->assertHasNoErrors();

    expect($line->fresh()->cash_flow_activity)->toBe(CashFlowActivity::Financing);

    // Re-opening shows the stored override; restating the default clears it.
    $page->call('openEditLine', $line->id)
        ->assertSet('f_line_cash_flow_activity', 'financing')
        ->set('f_line_cash_flow_activity', 'investing')
        ->call('saveLine')
        ->assertHasNoErrors();

    expect($line->fresh()->cash_flow_activity)->toBeNull();

    // A Bank line is cash itself and never carries an override.
    $cash = $this->group->lines()->where('name', 'Cash')->firstOrFail();
    $page->call('openEditLine', $cash->id)
        ->set('f_line_cash_flow_activity', 'financing')
        ->call('saveLine')
        ->assertHasNoErrors();

    expect($cash->fresh()->cash_flow_activity)->toBeNull();
});
