<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Transfer;
use App\Models\User;
use Livewire\Livewire;

/**
 * A second Post on a create page — a double-click, or Enter pressed again
 * before the redirect lands — arrives holding the record the first request
 * just posted. It must finish the way the first one did (on the record's page)
 * without touching the posted record: no line rewrite, no extra audit row, and
 * no "already posted" 500.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * The posted entry's line ids plus the audit row count — a rewrite of the
 * lines replaces their ids and records new audit rows.
 *
 * @return array{lines: list<int>, audit: int}
 */
function postedFootprint(JournalEntry $entry): array
{
    return [
        'lines' => JournalLine::query()->where('journal_entry_id', $entry->id)->orderBy('id')->pluck('id')->all(),
        'audit' => AccountingAuditLog::query()->count(),
    ];
}

function postedJournalForm(Company $company, Account $debit, Account $credit): mixed
{
    return Livewire::test('pages::journal.form', ['company' => $company])
        ->set('lines.0.account_id', $debit->id)
        ->set('lines.0.debit', '100.00')
        ->set('lines.1.account_id', $credit->id)
        ->set('lines.1.credit', '100.00')
        ->call('postEntry')
        ->assertHasNoErrors();
}

it('treats a second Post on a new journal entry as done', function () {
    $component = postedJournalForm($this->company, $this->expenseAccount, $this->bank);

    $entry = JournalEntry::query()->sole();
    expect($entry->isPosted())->toBeTrue();
    $before = postedFootprint($entry);

    $component->call('postEntry')
        ->assertHasNoErrors()
        ->assertRedirect(route('journal.show', ['company' => $this->company->slug, 'entry' => $entry->id]));

    expect(JournalEntry::query()->count())->toBe(1)
        ->and($entry->fresh()->isPosted())->toBeTrue()
        ->and(postedFootprint($entry))->toBe($before);
});

it('never rewrites a posted journal entry from Save draft', function () {
    $component = postedJournalForm($this->company, $this->expenseAccount, $this->bank);

    $entry = JournalEntry::query()->sole();
    $before = postedFootprint($entry);

    // A different amount proves the lines were not rebuilt from the form.
    $component->set('lines.0.debit', '250.00')
        ->set('lines.1.credit', '250.00')
        ->call('saveDraft')
        ->assertRedirect(route('journal.show', ['company' => $this->company->slug, 'entry' => $entry->id]));

    expect($entry->fresh()->isPosted())->toBeTrue()
        ->and((int) $entry->fresh()->lines()->sum('debit_cents'))->toBe(10000)
        ->and(postedFootprint($entry))->toBe($before);
});

it('treats a second Post on a new expense as done', function () {
    $component = Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payment_account_id', $this->bank->id)
        ->set('payee_name', 'Cloud Host')
        ->set('lines', [[
            'account_id' => $this->expenseAccount->id,
            'description' => 'Hosting',
            'amount' => '80.00',
            'tax_code_id' => null,
            'tax_override' => '',
            'class_id' => null,
            'location_id' => null,
            'auto_tax_cents' => 0,
            'tax_cents' => 0,
            'total' => 0,
        ]])
        ->call('postExpense')
        ->assertHasNoErrors();

    $expense = Expense::query()->sole();
    $entry = JournalEntry::query()->findOrFail($expense->journal_entry_id);
    $lineIds = $expense->lines()->orderBy('id')->pluck('id')->all();
    $before = postedFootprint($entry);

    $component->call('postExpense')
        ->assertHasNoErrors()
        ->assertRedirect(route('expenses.show', ['company' => $this->company->slug, 'expense' => $expense->id]));

    expect(Expense::query()->count())->toBe(1)
        ->and($expense->fresh()->journal_entry_id)->toBe($entry->id)
        ->and($expense->lines()->orderBy('id')->pluck('id')->all())->toBe($lineIds)
        ->and(postedFootprint($entry))->toBe($before);
});

it('treats a second Post on a new transfer as done', function () {
    $savings = app(SaveAccount::class)->handle([
        'code' => '1011', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);

    $component = Livewire::test('pages::transfers.form', ['company' => $this->company])
        ->set('from_account_id', $this->bank->id)
        ->set('to_account_id', $savings->id)
        ->set('from_amount', '250.00')
        ->set('to_amount', '250.00')
        ->call('postTransfer')
        ->assertHasNoErrors();

    $transfer = Transfer::query()->sole();
    $entry = JournalEntry::query()->findOrFail($transfer->journal_entry_id);
    $before = postedFootprint($entry);

    $component->call('postTransfer')
        ->assertHasNoErrors()
        ->assertRedirect(route('transfers.show', ['company' => $this->company->slug, 'transfer' => $transfer->id]));

    expect(Transfer::query()->count())->toBe(1)
        ->and($transfer->fresh()->journal_entry_id)->toBe($entry->id)
        ->and(postedFootprint($entry))->toBe($before);
});
