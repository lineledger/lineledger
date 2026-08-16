<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;

// Reuses combinedScenario() from CombinedReportsTest.

beforeEach(function () {
    $this->scenario = combinedScenario();
    $this->group = $this->scenario['group'];
    $this->expenseLine = $this->group->lines()->where('name', 'Expenses')->firstOrFail();
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
