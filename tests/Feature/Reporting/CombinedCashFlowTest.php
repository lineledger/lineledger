<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\ReportGroupAccountMap;
use App\Models\ReportGroupLine;
use App\Services\Reporting\CombinedReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Reuses combinedScenario(), postEntry() and acctOfType() from CombinedReportsTest.

beforeEach(function () {
    $this->scenario = combinedScenario();
    $this->group = $this->scenario['group'];

    $this->scenario['a']->members()->attach($this->scenario['user'], ['role' => CompanyRole::Owner->value]);
    $this->scenario['b']->members()->attach($this->scenario['user'], ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->scenario['user']);
});

it('combined cash flow collapses P&L into net income and reconciles to mapped cash', function () {
    $report = app(CombinedReportCalculator::class)->cashFlow(
        $this->group,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

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

it('places an investing line under a custom section and renders/exports', function () {
    // Add an unmapped fixed-asset purchase to Alpha and map it as an investing line.
    $bankA = acctOfType($this->scenario['a'], AccountType::Asset);
    $fixedAssetA = Account::withoutGlobalScopes()
        ->where('company_id', $this->scenario['a']->id)
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->orderBy('code')
        ->firstOrFail();

    postEntry($this->scenario['a'], '2026-04-01', [
        ['account' => $fixedAssetA, 'debit' => 40000],
        ['account' => $bankA, 'credit' => 40000],
    ]);

    $line = ReportGroupLine::create([
        'report_group_id' => $this->group->id,
        'name' => 'Equipment',
        'type' => AccountType::Asset,
        'subtype' => AccountSubtype::FixedAsset,
        'sort_order' => 5,
    ]);
    ReportGroupAccountMap::create([
        'report_group_id' => $this->group->id,
        'report_group_line_id' => $line->id,
        'company_id' => $this->scenario['a']->id,
        'account_id' => $fixedAssetA->id,
    ]);

    $section = $this->group->sections()->create([
        'statement' => 'cash_flow',
        'group_key' => 'investing',
        'name' => 'Capital Expenditure',
        'sort_order' => 1,
    ]);
    $line->update(['report_group_section_id' => $section->id]);

    $report = app(CombinedReportCalculator::class)->cashFlow(
        $this->group,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    $block = collect($report['investing'])->firstWhere('type', 'section');
    expect($block)->not->toBeNull()
        ->and($block['name'])->toBe('Capital Expenditure')
        ->and($block['subtotal'])->toBe(-40000)
        ->and($report['total_investing'])->toBe(-40000);

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
