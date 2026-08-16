<?php

use App\Actions\Accounting\SaveJournalEntryTemplate;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntryTemplate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function journalEntryTemplateData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Monthly depreciation',
        'is_active' => true,
        'lines' => [
            ['account_id' => test()->bank->id, 'debit_cents' => 10000, 'credit_cents' => 0, 'memo' => 'Cash'],
            ['account_id' => test()->income->id, 'debit_cents' => 0, 'credit_cents' => 10000, 'memo' => 'Revenue'],
        ],
    ], $overrides);
}

it('creates a template with ordered lines via the action', function () {
    $template = app(SaveJournalEntryTemplate::class)->handle(journalEntryTemplateData());

    expect($template->company_id)->toBe($this->company->id)
        ->and($template->name)->toBe('Monthly depreciation')
        ->and($template->is_active)->toBeTrue()
        ->and($template->lines)->toHaveCount(2);

    $first = $template->lines->firstWhere('line_order', 0);
    expect($first->account_id)->toBe($this->bank->id)
        ->and($first->debit_cents)->toBe(10000)
        ->and($first->debit_cents)->toBeInt()
        ->and($first->company_id)->toBe($this->company->id);

    expect($template->lines->pluck('line_order')->all())->toBe([0, 1]);
});

it('replaces the line set when a template is updated', function () {
    $template = app(SaveJournalEntryTemplate::class)->handle(journalEntryTemplateData());

    $updated = app(SaveJournalEntryTemplate::class)->handle(journalEntryTemplateData([
        'name' => 'Renamed',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'memo' => null],
        ],
    ]), $template);

    expect($updated->id)->toBe($template->id)
        ->and($updated->name)->toBe('Renamed')
        ->and($updated->lines)->toHaveCount(1)
        ->and($updated->lines->first()->debit_cents)->toBe(5000);
});

it('soft-deletes a template from the index component', function () {
    $template = app(SaveJournalEntryTemplate::class)->handle(journalEntryTemplateData());

    Livewire::test('pages::journal-entry-templates.index', ['company' => $this->company])
        ->call('delete', $template->id);

    $this->assertSoftDeleted('journal_entry_templates', ['id' => $template->id]);
});

it('only lists templates from the current company', function () {
    $mine = app(SaveJournalEntryTemplate::class)->handle(journalEntryTemplateData(['name' => 'Mine']));

    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $theirs = app(SaveJournalEntryTemplate::class)->handle([
        'name' => 'Theirs',
        'lines' => [['account_id' => null, 'debit_cents' => 100, 'credit_cents' => 0, 'memo' => null]],
    ]);
    app()->instance('current_company', $this->company);

    $ids = JournalEntryTemplate::query()->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirs->id);
});

it('saves a template through the management form', function () {
    Livewire::test('pages::journal-entry-templates.form', ['company' => $this->company])
        ->set('name', 'From form')
        ->set('lines.0.account_id', $this->bank->id)
        ->set('lines.0.debit', '250.00')
        ->set('lines.1.account_id', $this->income->id)
        ->set('lines.1.credit', '250.00')
        ->call('save')
        ->assertRedirect(route('journal-entry-templates.index', ['company' => $this->company->slug]));

    $template = JournalEntryTemplate::query()->where('name', 'From form')->firstOrFail();
    expect($template->lines)->toHaveCount(2)
        ->and($template->lines->firstWhere('line_order', 0)->debit_cents)->toBe(25000)
        ->and($template->lines->firstWhere('line_order', 1)->credit_cents)->toBe(25000);
});
