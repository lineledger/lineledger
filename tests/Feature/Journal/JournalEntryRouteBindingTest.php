<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('opens a journal entry by its id or by its entry number', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $entry = JournalEntry::create(['entry_no' => 'JE-007748', 'entry_date' => '2026-01-15', 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 1000, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 1000, 'line_order' => 1]);

    $this->get(route('journal.show', ['company' => $this->company->slug, 'entry' => $entry->id]))->assertOk();
    $this->get(route('journal.show', ['company' => $this->company->slug, 'entry' => 'JE-007748']))->assertOk();
});

it('will not resolve another company\'s entry number', function () {
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    JournalEntry::create(['entry_no' => 'JE-OTHER', 'entry_date' => '2026-01-15', 'is_posted' => true]);
    app()->instance('current_company', $this->company);

    $this->get(route('journal.show', ['company' => $this->company->slug, 'entry' => 'JE-OTHER']))->assertNotFound();
});
