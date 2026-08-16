<?php

use App\Models\ReportGroupSection;
use App\Models\User;
use Livewire\Livewire;

// Reuses combinedScenario() / acctOfType() / postEntry() from CombinedReportsTest.

beforeEach(function () {
    $this->scenario = combinedScenario();
    $this->group = $this->scenario['group'];
    $this->actingAs($this->scenario['user']);

    // The seeded combined lines: Expenses (expense bucket) and Revenue (income).
    $this->expenseLine = $this->group->lines()->where('name', 'Expenses')->firstOrFail();
    $this->revenueLine = $this->group->lines()->where('name', 'Revenue')->firstOrFail();
});

it('creates a section scoped to the group and statement', function () {
    Livewire::test('pages::report-groups.income-statement-sections', ['reportGroup' => $this->group])
        ->set('f_section_name', 'Operating')
        ->set('f_section_group', 'expense')
        ->call('saveSection')
        ->assertHasNoErrors();

    $section = ReportGroupSection::query()->where('report_group_id', $this->group->id)->sole();

    expect($section->name)->toBe('Operating')
        ->and($section->statement->value)->toBe('income_statement')
        ->and($section->group_key)->toBe('expense')
        ->and($section->sort_order)->toBe(1);
});

it('assigns a line to a section and back to unassigned', function () {
    $section = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);

    $component = Livewire::test('pages::report-groups.income-statement-sections', ['reportGroup' => $this->group])
        ->call('moveLine', $this->expenseLine->id, (string) $section->id);

    expect($this->expenseLine->fresh()->report_group_section_id)->toBe($section->id);

    $component->call('moveLine', $this->expenseLine->id, 'unassigned');

    expect($this->expenseLine->fresh()->report_group_section_id)->toBeNull();
});

it('ignores a move to a section in a different anchor group', function () {
    $incomeSection = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'income', 'name' => 'Sales', 'sort_order' => 1]);

    Livewire::test('pages::report-groups.income-statement-sections', ['reportGroup' => $this->group])
        ->call('moveLine', $this->expenseLine->id, (string) $incomeSection->id);

    expect($this->expenseLine->fresh()->report_group_section_id)->toBeNull();
});

it('reverts lines to unassigned when a section is deleted', function () {
    $section = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Operating', 'sort_order' => 1]);
    $this->expenseLine->update(['report_group_section_id' => $section->id]);

    Livewire::test('pages::report-groups.income-statement-sections', ['reportGroup' => $this->group])
        ->call('deleteSection', $section->id);

    expect($this->expenseLine->fresh()->report_group_section_id)->toBeNull()
        ->and(ReportGroupSection::find($section->id))->toBeNull();
});

it('validates the name and group', function () {
    Livewire::test('pages::report-groups.income-statement-sections', ['reportGroup' => $this->group])
        ->set('f_section_name', '')
        ->set('f_section_group', 'expense')
        ->call('saveSection')
        ->assertHasErrors('f_section_name');

    Livewire::test('pages::report-groups.income-statement-sections', ['reportGroup' => $this->group])
        ->set('f_section_name', 'Nope')
        ->set('f_section_group', 'not_a_group')
        ->call('saveSection')
        ->assertHasErrors('f_section_group');
});

it('reorders sections within a group', function () {
    $a = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'A', 'sort_order' => 1]);
    $b = $this->group->sections()->create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'B', 'sort_order' => 2]);

    Livewire::test('pages::report-groups.income-statement-sections', ['reportGroup' => $this->group])
        ->call('moveSectionDown', $a->id);

    expect($a->fresh()->sort_order)->toBe(2)
        ->and($b->fresh()->sort_order)->toBe(1);
});

it('forbids a non-creator from opening the config page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('report-groups.income-statement.sections', $this->group))
        ->assertForbidden();
});
