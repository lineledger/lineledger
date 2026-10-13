<?php

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->otherExpense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->skip(1)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function editablePostedCheque(int $cents = 10000): Cheque
{
    $cheque = Cheque::create([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => '1001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Acme Services',
    ]);

    $cheque->lines()->create([
        'account_id' => test()->expense->id,
        'description' => 'Service call',
        'amount_cents' => $cents,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque->fresh('lines'));

    return $cheque->fresh('lines');
}

/** @return array<int, array<string, mixed>> */
function editChequeFormLine(int $accountId, string $amount): array
{
    return [[
        'account_id' => $accountId,
        'description' => 'Service call',
        'amount' => $amount,
        'tax_code_id' => null,
        'secondary_tax_code_id' => null,
        'tax_code_ids' => [],
        'tax_overrides' => [],
        'class_id' => null,
        'location_id' => null,
        'auto_tax_cents' => 0,
        'tax_cents' => 0,
        'secondary_tax_cents' => 0,
        'total' => 0,
    ]];
}

it('opens a posted cheque in the edit form pre-filled', function () {
    $cheque = editablePostedCheque();

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSet('cheque_no', '1001')
        ->assertSet('bank_account_id', $this->bank->id)
        ->assertSet('payee_name', 'Acme Services')
        ->assertSet('lines.0.amount', '100.00')
        ->assertSee('Save changes')
        ->assertDontSee('Save draft');
});

it('reposts an edited cheque onto the same journal entry', function () {
    $cheque = editablePostedCheque();
    $entryId = $cheque->journal_entry_id;

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->set('payee_name', 'Acme Services Ltd.')
        ->set('memo', 'Corrected amount')
        ->set('lines', editChequeFormLine($this->otherExpense->id, '250.00'))
        ->call('postCheque')
        ->assertHasNoErrors();

    $cheque->refresh();

    expect($cheque->journal_entry_id)->toBe($entryId)
        ->and($cheque->status)->toBe(ChequeStatus::Posted)
        ->and($cheque->amount_cents)->toBe(25000)
        ->and($cheque->payee_name)->toBe('Acme Services Ltd.');

    // The GL follows: one entry, re-pointed at the new expense account.
    $lines = JournalLine::query()->where('journal_entry_id', $entryId)->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->sum('debit_cents'))->toBe(25000)
        ->and($lines->sum('credit_cents'))->toBe(25000)
        ->and($lines->firstWhere('account_id', $this->otherExpense->id)?->debit_cents)->toBe(25000)
        ->and($lines->firstWhere('account_id', $this->expense->id))->toBeNull()
        ->and($lines->firstWhere('account_id', $this->bank->id)?->credit_cents)->toBe(25000);

    // No second entry was created for the correction.
    expect(JournalEntry::query()->where('source_type', Cheque::class)->where('source_id', $cheque->id)->count())->toBe(1);
});

it('recomputes the bank balance after a repost', function () {
    $cheque = editablePostedCheque();

    $opening = (int) $this->bank->fresh()->balance_cents;

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->set('lines', editChequeFormLine($this->expense->id, '150.00'))
        ->call('postCheque')
        ->assertHasNoErrors();

    // The cheque grew by $50, so the bank is $50 further down than before.
    expect((int) $this->bank->fresh()->balance_cents)->toBe($opening - 5000);
});

it('refuses to save a posted cheque as a draft', function () {
    $cheque = editablePostedCheque();

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->call('saveDraft')
        ->assertHasErrors('lines');

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Posted);
});

it('blocks editing a posted cheque into a locked period and leaves the ledger untouched', function () {
    $cheque = editablePostedCheque();

    $this->company->forceFill(['lock_date' => now()->addDay()->toDateString()])->save();
    app()->instance('current_company', $this->company->fresh());

    Livewire::test('pages::cheques.form', ['company' => $this->company->fresh(), 'cheque' => $cheque])
        ->set('lines', editChequeFormLine($this->expense->id, '999.00'))
        ->call('postCheque')
        ->assertHasErrors('lines');

    // The rewrite rolled back with the failed repost.
    expect($cheque->fresh()->amount_cents)->toBe(10000)
        ->and($cheque->fresh('lines')->lines->first()->amount_cents)->toBe(10000);
});

it('still refuses to edit a voided cheque', function () {
    $cheque = editablePostedCheque();
    app(ChequePoster::class)->void($cheque->fresh());

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque->fresh()])
        ->assertStatus(403);
});

it('offers Edit on a posted cheque and hides it once voided', function () {
    $cheque = editablePostedCheque();

    Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSeeHtml(route('cheques.edit', ['company' => $this->company->slug, 'cheque' => $cheque->id]));

    app(ChequePoster::class)->void($cheque->fresh());

    Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque->fresh()])
        ->assertDontSeeHtml(route('cheques.edit', ['company' => $this->company->slug, 'cheque' => $cheque->id]));
});
