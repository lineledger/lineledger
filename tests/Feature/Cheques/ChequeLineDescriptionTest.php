<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->payee = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('keeps the line description when saving a draft cheque', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_contact_id', $this->payee->id)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.description', 'Snow removal')
        ->set('lines.0.amount', '120.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Cheque::firstOrFail()->lines->first()->description)->toBe('Snow removal');
});

it('keeps the line description when posting a cheque, and memos the expense leg with it', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_contact_id', $this->payee->id)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.description', 'Snow removal')
        ->set('lines.0.amount', '120.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    $cheque = Cheque::firstOrFail();

    expect($cheque->lines->first()->description)->toBe('Snow removal')
        ->and($cheque->journalEntry->lines->firstWhere('account_id', $this->expense->id)->memo)->toBe('Snow removal');
});
