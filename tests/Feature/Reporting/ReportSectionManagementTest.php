<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use App\Models\ReportSection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    // A true expense-subtype account (the first expense *by code* is COGS, which
    // buckets to 'cogs' on the income statement, not 'expense').
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('creates a section scoped to the company and statement', function () {
    Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->set('f_section_name', 'Operating')
        ->set('f_section_group', 'expense')
        ->call('saveSection')
        ->assertHasNoErrors();

    $section = ReportSection::query()->where('company_id', $this->company->id)->sole();

    expect($section->name)->toBe('Operating')
        ->and($section->statement)->toBe(ReportStatement::IncomeStatement)
        ->and($section->group_key)->toBe('expense')
        ->and($section->sort_order)->toBe(1);
});

it('renames a section', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Ops', 'sort_order' => 1]);

    Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->call('openEditSection', $section->id)
        ->set('f_section_name', 'Operating expenses')
        ->call('saveSection')
        ->assertHasNoErrors();

    expect($section->fresh()->name)->toBe('Operating expenses');
});

it('assigns an account to a section and back to unassigned', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Ops', 'sort_order' => 1]);

    $component = Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->call('moveAccount', $this->expense->id, (string) $section->id);

    expect($this->expense->fresh()->report_section_id)->toBe($section->id);

    $component->call('moveAccount', $this->expense->id, 'unassigned');

    expect($this->expense->fresh()->report_section_id)->toBeNull();
});

it('ignores a move to a section in a different anchor group', function () {
    $incomeSection = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'income', 'name' => 'Sales', 'sort_order' => 1]);

    Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->call('moveAccount', $this->expense->id, (string) $incomeSection->id);

    expect($this->expense->fresh()->report_section_id)->toBeNull();
});

it('reverts accounts to unassigned when a section is deleted', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Ops', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);

    Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->call('deleteSection', $section->id);

    expect($this->expense->fresh()->report_section_id)->toBeNull()
        ->and(ReportSection::find($section->id))->toBeNull();
});

it('validates the name and group', function () {
    Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->set('f_section_name', '')
        ->set('f_section_group', 'expense')
        ->call('saveSection')
        ->assertHasErrors('f_section_name');

    Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->set('f_section_name', 'Nope')
        ->set('f_section_group', 'not_a_group')
        ->call('saveSection')
        ->assertHasErrors('f_section_group');
});

it('reorders sections within a group', function () {
    $a = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'A', 'sort_order' => 1]);
    $b = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'B', 'sort_order' => 2]);

    Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->call('moveSectionDown', $a->id);

    expect($a->fresh()->sort_order)->toBe(2)
        ->and($b->fresh()->sort_order)->toBe(1);
});

it('cannot move an account belonging to another company', function () {
    $other = Company::factory()->create();
    $otherAccount = Account::withoutGlobalScopes()
        ->where('company_id', $other->id)
        ->where('type', AccountType::Expense->value)
        ->first();

    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Ops', 'sort_order' => 1]);

    expect(fn () => Livewire::test('pages::reports.income-statement-sections', ['company' => $this->company])
        ->call('moveAccount', $otherAccount->id, (string) $section->id))
        ->toThrow(ModelNotFoundException::class);

    expect($otherAccount->fresh()->report_section_id)->toBeNull();
});
