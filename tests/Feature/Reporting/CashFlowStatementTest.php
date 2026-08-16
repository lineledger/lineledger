<?php

use App\Enums\AccountSubtype;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\ReportSection;
use App\Models\User;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * First seeded account of a given subtype for a company (no global scope).
 */
function cfAccount(Company $company, AccountSubtype $subtype): Account
{
    return Account::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('subtype', $subtype->value)
        ->orderBy('code')
        ->firstOrFail();
}

/**
 * Post a balanced, posted manual journal entry to a company.
 *
 * @param  array<int, array{account: Account, debit?: int, credit?: int}>  $lines
 */
function cfPost(Company $company, string $date, array $lines): void
{
    app()->instance('current_company', $company);

    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.fake()->unique()->numerify('#####'),
        'entry_date' => CarbonImmutable::parse($date),
        'memo' => 'Cash flow test',
        'is_posted' => true,
    ]);

    foreach ($lines as $i => $line) {
        $entry->lines()->create([
            'account_id' => $line['account']->id,
            'debit_cents' => $line['debit'] ?? 0,
            'credit_cents' => $line['credit'] ?? 0,
            'line_order' => $i,
        ]);
    }

    app()->forgetInstance('current_company');
}

/**
 * One company with cash moved by an operating sale, an operating expense, a fixed
 * asset purchase (investing) and an owner contribution (financing). Net cash change
 * is therefore a known mix of all three activities.
 */
function cashFlowScenario(): array
{
    $company = Company::factory()->create();

    $bank = cfAccount($company, AccountSubtype::Bank);
    $income = cfAccount($company, AccountSubtype::Income);
    $expense = cfAccount($company, AccountSubtype::Expense);
    $fixedAsset = cfAccount($company, AccountSubtype::FixedAsset);
    $equity = cfAccount($company, AccountSubtype::Equity);

    cfPost($company, '2026-03-01', [['account' => $bank, 'debit' => 100000], ['account' => $income, 'credit' => 100000]]);
    cfPost($company, '2026-03-05', [['account' => $expense, 'debit' => 30000], ['account' => $bank, 'credit' => 30000]]);
    cfPost($company, '2026-03-10', [['account' => $fixedAsset, 'debit' => 50000], ['account' => $bank, 'credit' => 50000]]);
    cfPost($company, '2026-03-15', [['account' => $bank, 'debit' => 20000], ['account' => $equity, 'credit' => 20000]]);

    return compact('company', 'bank', 'income', 'expense', 'fixedAsset', 'equity');
}

it('builds an indirect cash flow that reconciles to the bank movement', function () {
    $s = cashFlowScenario();

    $report = app(ReportCalculator::class)->cashFlow(
        $s['company'],
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    expect($report['net_income'])->toBe(70000)            // $1000 income − $300 expense
        ->and($report['total_operating'])->toBe(70000)    // no working-capital accounts moved
        ->and($report['total_investing'])->toBe(-50000)   // fixed asset purchase uses cash
        ->and($report['total_financing'])->toBe(20000)    // owner contribution is a source
        ->and($report['net_change'])->toBe(40000)
        ->and($report['cash_beginning'])->toBe(0)
        ->and($report['cash_ending'])->toBe(40000)
        ->and($report['reconciles'])->toBeTrue();
});

it('working-capital changes offset net income when no cash moves (AR/AP only)', function () {
    $company = Company::factory()->create();
    $income = cfAccount($company, AccountSubtype::Income);
    $expense = cfAccount($company, AccountSubtype::Expense);
    $ar = cfAccount($company, AccountSubtype::AccountsReceivable);
    $ap = cfAccount($company, AccountSubtype::AccountsPayable);

    // Accrue revenue into AR and an expense into AP — never touching cash.
    cfPost($company, '2026-02-01', [['account' => $ar, 'debit' => 100000], ['account' => $income, 'credit' => 100000]]);
    cfPost($company, '2026-02-05', [['account' => $expense, 'debit' => 30000], ['account' => $ap, 'credit' => 30000]]);

    $report = app(ReportCalculator::class)->cashFlow(
        $company,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    expect($report['net_income'])->toBe(70000)
        ->and($report['total_operating'])->toBe(0)   // +70000 NI − 100000 AR + 30000 AP
        ->and($report['net_change'])->toBe(0)
        ->and($report['reconciles'])->toBeTrue();
});

it('groups an assigned account under a custom section with its own subtotal', function () {
    $s = cashFlowScenario();

    $section = ReportSection::create([
        'company_id' => $s['company']->id,
        'statement' => ReportStatement::CashFlow->value,
        'group_key' => 'investing',
        'name' => 'Capital Expenditure',
        'sort_order' => 1,
    ]);
    $s['fixedAsset']->update(['report_section_id' => $section->id]);

    $report = app(ReportCalculator::class)->cashFlow(
        $s['company'],
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    $block = collect($report['investing'])->firstWhere('type', 'section');

    expect($block)->not->toBeNull()
        ->and($block['name'])->toBe('Capital Expenditure')
        ->and($block['id'])->toBe($section->id)
        ->and($block['subtotal'])->toBe(-50000)
        ->and($report['total_investing'])->toBe(-50000); // unchanged by sectioning
});

it('re-routes an account to another activity via the per-account override and still reconciles', function () {
    $s = cashFlowScenario();

    // The fixed-asset purchase normally lands in Investing (−$500). Override it to Operating.
    $s['fixedAsset']->update(['cash_flow_activity' => 'operating']);

    $report = app(ReportCalculator::class)->cashFlow(
        $s['company'],
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    expect($report['total_operating'])->toBe(20000)   // 70000 NI − 50000 reclassified asset
        ->and($report['total_investing'])->toBe(0)    // nothing left in investing
        ->and($report['total_financing'])->toBe(20000)
        ->and($report['net_change'])->toBe(40000)     // unchanged — only the grouping moved
        ->and($report['reconciles'])->toBeTrue();
});

it('moves an account across activities from the sections page and clears its custom section', function () {
    $s = cashFlowScenario();
    $this->actingAs(User::factory()->create());

    $section = ReportSection::create([
        'company_id' => $s['company']->id,
        'statement' => ReportStatement::CashFlow->value,
        'group_key' => 'investing',
        'name' => 'Capital Expenditure',
        'sort_order' => 1,
    ]);
    $s['fixedAsset']->update(['report_section_id' => $section->id]);

    Livewire::test('pages::reports.cash-flow-sections', ['company' => $s['company']])
        ->call('moveAccountToActivity', $s['fixedAsset']->id, 'financing')
        ->assertHasNoErrors();

    $fresh = $s['fixedAsset']->fresh();
    expect($fresh->cash_flow_activity->value)->toBe('financing')
        ->and($fresh->report_section_id)->toBeNull(); // old section belonged to investing
});

it('ignores a sections-page activity move for an account with no activity line', function () {
    $s = cashFlowScenario();
    $this->actingAs(User::factory()->create());

    // Bank is cash itself — it has no activity, so the move is a no-op.
    Livewire::test('pages::reports.cash-flow-sections', ['company' => $s['company']])
        ->call('moveAccountToActivity', $s['bank']->id, 'financing')
        ->assertHasNoErrors();

    expect($s['bank']->fresh()->cash_flow_activity)->toBeNull();
});

it('renders the report and exports to CSV, XLSX and PDF', function () {
    $s = cashFlowScenario();
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::reports.cash-flow', ['company' => $s['company']])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->assertOk()
        ->assertSee('Operating Activities')
        ->assertSee('Net change in cash')
        ->assertSeeHtml('data-test="cf-cash-ending"');

    $response = $component->instance()->exportCsv();
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Net income')
        ->and($csv)->toContain('NET CHANGE IN CASH')
        ->and($component->instance()->exportXlsx())->toBeInstanceOf(BinaryFileResponse::class)
        ->and($component->instance()->exportPdf())->toBeInstanceOf(BinaryFileResponse::class);
});

it('renders the prior column and names the comparison range in the subtitle', function () {
    $s = cashFlowScenario();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::reports.cash-flow', ['company' => $s['company']])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31')
        ->set('comparisonBasis', 'prior_year')
        ->assertOk()
        ->assertSee('Prior')
        ->assertSee('compared to 2025-01-01 to 2025-12-31 (prior year)')
        ->set('comparisonBasis', 'prior_period')
        ->assertSee('compared to 2025-01-01 to 2025-12-31 (prior period)');
});
