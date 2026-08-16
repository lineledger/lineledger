<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Averback, Harold', 'is_customer' => true, 'is_active' => true]);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // Two posted AR journal lines with no customer attribution.
    $this->entry = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-UNATTR',
        'entry_date' => CarbonImmutable::now()->subDays(5),
        'memo' => 'Averback opening balance',
    ]);
    $this->entry->lines()->create(['account_id' => $ar->id, 'debit_cents' => 6230, 'credit_cents' => 0, 'line_order' => 0]);
    $this->entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 6230, 'line_order' => 1]);
    app(JournalPoster::class)->post($this->entry);

    $this->arAccountId = $ar->id;
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('lists unattributed AR lines and totals them', function () {
    Livewire::test('pages::reports.unattributed-ar', ['company' => $this->company])
        ->assertSet('assignToContactId', null)
        ->assertSee('JE-UNATTR')
        ->assertSee('Averback opening balance')
        ->tap(function ($t) {
            expect($t->instance()->totalUnattributed())->toBe(6230);
        });
});

it('attributes selected lines to a chosen customer', function () {
    $lineId = $this->entry->lines()->where('account_id', $this->arAccountId)->value('id');

    Livewire::test('pages::reports.unattributed-ar', ['company' => $this->company])
        ->set('selected', [$lineId => true])
        ->call('chooseCustomer', $this->customer->id)
        ->call('assign')
        ->assertHasNoErrors();

    expect((int) $this->entry->lines()->where('account_id', $this->arAccountId)->value('contact_id'))
        ->toBe($this->customer->id);

    // After attribution it no longer shows as unattributed.
    Livewire::test('pages::reports.unattributed-ar', ['company' => $this->company])
        ->tap(fn ($t) => expect($t->instance()->totalUnattributed())->toBe(0));
});

it('requires a customer before attributing', function () {
    $lineId = $this->entry->lines()->where('account_id', $this->arAccountId)->value('id');

    Livewire::test('pages::reports.unattributed-ar', ['company' => $this->company])
        ->set('selected', [$lineId => true])
        ->call('assign')
        ->assertHasErrors('assignToContactId');

    expect($this->entry->lines()->where('account_id', $this->arAccountId)->value('contact_id'))->toBeNull();
});
