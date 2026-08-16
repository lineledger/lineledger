<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\ReportSection;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('drops the section assignment when an account is re-typed out of its anchor', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Ops', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);

    // Re-type the account from expense to income — its income-statement bucket
    // changes, so the observer should clear the now-mismatched section.
    $this->expense->update([
        'type' => AccountType::Income,
        'subtype' => AccountSubtype::Income,
        'normal_balance' => AccountType::Income->normalBalance(),
    ]);

    expect($this->expense->fresh()->report_section_id)->toBeNull();
});

it('keeps the assignment when an unrelated field changes', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Ops', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);

    $this->expense->update(['name' => $this->expense->name.' (renamed)']);

    expect($this->expense->fresh()->report_section_id)->toBe($section->id);
});

it('nulls assignments at the database level when a section is deleted directly', function () {
    $section = ReportSection::create(['statement' => 'income_statement', 'group_key' => 'expense', 'name' => 'Ops', 'sort_order' => 1]);
    $this->expense->update(['report_section_id' => $section->id]);

    $section->delete();

    expect($this->expense->fresh()->report_section_id)->toBeNull();
});
