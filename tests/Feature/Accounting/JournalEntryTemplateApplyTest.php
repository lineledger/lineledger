<?php

use App\Actions\Accounting\SaveJournalEntryTemplate;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryTemplate;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $this->template = app(SaveJournalEntryTemplate::class)->handle([
        'name' => 'Monthly depreciation',
        'is_active' => true,
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 10000, 'credit_cents' => 0, 'memo' => 'Cash'],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 10000, 'memo' => 'Revenue'],
        ],
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('replaces the empty lines and populates lines when a template is selected on create', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->assertSeeHtml('data-test="journal-entry-template-picker"')
        ->set('template_id', $this->template->id)
        ->assertCount('lines', 2)
        ->assertSet('lines.0.account_id', $this->bank->id)
        ->assertSet('lines.0.debit', '100.00')
        ->assertSet('lines.1.account_id', $this->income->id)
        ->assertSet('lines.1.credit', '100.00');
});

it('appends template lines when an existing line already has content', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->bank->id)
        ->set('lines.0.debit', '50.00')
        ->set('template_id', $this->template->id)
        ->assertCount('lines', 4)
        ->assertSet('lines.0.debit', '50.00')
        ->assertSet('lines.2.account_id', $this->bank->id);
});

it('hides the picker and ignores template selection when editing an entry', function () {
    $entry = JournalEntry::create([
        'entry_no' => 'JE-EDIT-1',
        'entry_date' => $this->company->currentDateTime()->toDateString(),
        'memo' => 'Existing',
    ]);
    $entry->lines()->createMany([
        ['account_id' => $this->bank->id, 'debit_cents' => 9900, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 9900, 'line_order' => 1],
    ]);
    app(JournalPoster::class)->post($entry);

    Livewire::test('pages::journal.form', ['company' => $this->company, 'entry' => $entry->fresh('lines')])
        ->assertDontSeeHtml('data-test="journal-entry-template-picker"')
        ->set('template_id', $this->template->id)
        ->assertCount('lines', 2)
        ->assertSet('lines.0.debit', '99.00');
});

it('posts a balanced entry built from a template', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('template_id', $this->template->id)
        ->set('entryNo', 'JE-TPL-1')
        ->call('postEntry')
        ->assertHasNoErrors();

    $entry = JournalEntry::query()->where('entry_no', 'JE-TPL-1')->with('lines')->firstOrFail();

    expect($entry->lines)->toHaveCount(2)
        ->and($entry->isPosted())->toBeTrue();

    $debitLine = $entry->lines->firstWhere('account_id', $this->bank->id);
    expect($debitLine->debit_cents)->toBe(10000)
        ->and($debitLine->credit_cents)->toBe(0);
});

it('saves the current lines as a template', function () {
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->bank->id)
        ->set('lines.0.debit', '300.00')
        ->set('lines.1.account_id', $this->income->id)
        ->set('lines.1.credit', '300.00')
        ->set('template_name', 'Captured entry')
        ->call('saveAsTemplate')
        ->assertHasNoErrors();

    $template = JournalEntryTemplate::query()->where('name', 'Captured entry')->with('lines')->firstOrFail();

    expect($template->lines)->toHaveCount(2)
        ->and($template->lines->firstWhere('line_order', 0)->debit_cents)->toBe(30000)
        ->and($template->lines->firstWhere('line_order', 1)->credit_cents)->toBe(30000);
});

it('fills each applied line tax code from the account default', function () {
    // Templates carry no tax code of their own; applying one behaves like
    // picking the account by hand, which adopts the account's default tag.
    $taxCode = TaxCode::query()->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->income->update(['default_tax_code_id' => $taxCode->id]);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('template_id', $this->template->id)
        ->assertSet('lines.1.tax_code_id', $taxCode->id)
        ->assertSet('lines.0.tax_code_id', $this->bank->default_tax_code_id);
});
