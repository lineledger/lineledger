<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Models\ReportGroupLine;

// Reuses combinedScenario() from CombinedReportsTest.

beforeEach(function () {
    $this->scenario = combinedScenario();
    $this->group = $this->scenario['group'];
    $this->expenseLine = $this->group->lines()->where('name', 'Expenses')->firstOrFail();
});

it('drops a cash-flow section when a line is re-routed to another activity', function () {
    $line = ReportGroupLine::create([
        'report_group_id' => $this->group->id,
        'name' => 'Equipment',
        'type' => AccountType::Asset,
        'subtype' => AccountSubtype::FixedAsset,
        'sort_order' => 9,
    ]);
    $section = $this->group->sections()->create(['statement' => 'cash_flow', 'group_key' => 'investing', 'name' => 'Capital Expenditure', 'sort_order' => 1]);
    $line->update(['report_group_section_id' => $section->id]);

    // Re-routing the line to Financing leaves it outside its investing section.
    $line->update(['cash_flow_activity' => CashFlowActivity::Financing]);

    expect($line->fresh()->report_group_section_id)->toBeNull();
});

it('keeps a balance-sheet section when a line is re-routed to another cash-flow activity', function () {
    $line = ReportGroupLine::create([
        'report_group_id' => $this->group->id,
        'name' => 'Equipment',
        'type' => AccountType::Asset,
        'subtype' => AccountSubtype::FixedAsset,
        'sort_order' => 9,
    ]);
    $section = $this->group->sections()->create(['statement' => 'balance_sheet', 'group_key' => AccountSubtype::FixedAsset->value, 'name' => 'Plant', 'sort_order' => 1]);
    $line->update(['report_group_section_id' => $section->id]);

    // The section is anchored to the subtype, which the activity override does not change.
    $line->update(['cash_flow_activity' => CashFlowActivity::Financing]);

    expect($line->fresh()->report_group_section_id)->toBe($section->id);
});

it('drops the section assignment when a line is re-typed out of its anchor', function () {
    $section = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expenseLine->update(['report_group_section_id' => $section->id]);

    // Re-type the line from expense to income — its income-statement bucket changes,
    // so the observer should clear the now-mismatched section.
    $this->expenseLine->update(['type' => AccountType::Income, 'subtype' => AccountSubtype::Income]);

    expect($this->expenseLine->fresh()->report_group_section_id)->toBeNull();
});

it('keeps the assignment when an unrelated field changes', function () {
    $section = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expenseLine->update(['report_group_section_id' => $section->id]);

    $this->expenseLine->update(['name' => 'Expenses (renamed)']);

    expect($this->expenseLine->fresh()->report_group_section_id)->toBe($section->id);
});

it('nulls assignments when a section is deleted directly', function () {
    $section = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expenseLine->update(['report_group_section_id' => $section->id]);

    $section->delete();

    expect($this->expenseLine->fresh()->report_group_section_id)->toBeNull();
});
