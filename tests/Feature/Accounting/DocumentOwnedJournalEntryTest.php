<?php

use App\Actions\Accounting\ReverseJournalEntry;
use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Accounting\UpdateJournalEntryHeader;
use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * The general journal manages manual entries only. An entry another document
 * created, and the reversing entry a void wrote, belong to that document or
 * that void: the journal page, the journal form, the header update and the
 * API must all refuse to void, reverse, rewrite or delete them (audit
 * findings D1Ledger-05 and D1Ledger-06).
 */

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function ownedEntryManualPosted(): JournalEntry
{
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-05-20',
        'memo' => 'Manual',
        'lines' => [
            ['account_id' => test()->expense->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => test()->bank->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ]);

    return app(JournalPoster::class)->post($entry);
}

it('tells a void reversal apart from a manual entry and an accrual reversal', function () {
    $original = ownedEntryManualPosted();
    $reversal = app(JournalPoster::class)->void($original);

    expect($reversal->isVoidReversal())->toBeTrue()
        ->and($reversal->isManualEntry())->toBeFalse()
        ->and($reversal->isEditableInJournal())->toBeFalse()
        ->and($original->fresh()->isManualEntry())->toBeTrue();

    $accrual = ownedEntryManualPosted();
    $draftReversal = app(ReverseJournalEntry::class)->handle($accrual, CarbonImmutable::parse('2026-06-01'));
    app(JournalPoster::class)->post($draftReversal);

    expect($draftReversal->fresh()->isVoidReversal())->toBeFalse()
        ->and($draftReversal->fresh()->isManualEntry())->toBeTrue();
});

it('refuses an API delete of a posted entry another document created, leaving the document posted', function () {
    $invoiceId = $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-20',
        'lines' => [['description' => 'Work', 'quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => $this->income->id]],
    ], $this->h)->assertStatus(201)->json('data.id');

    $invoice = Invoice::query()->findOrFail($invoiceId);

    $this->deleteJson("/api/v1/journal-entries/{$invoice->journal_entry_id}", [], $this->h)->assertStatus(422);

    expect(JournalEntry::query()->findOrFail($invoice->journal_entry_id)->voided_at)->toBeNull()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Posted);
});

it('refuses an API delete or edit of the reversal a void wrote', function () {
    $reversal = app(JournalPoster::class)->void(ownedEntryManualPosted());

    $this->deleteJson("/api/v1/journal-entries/{$reversal->id}", [], $this->h)->assertStatus(422);

    $this->patchJson("/api/v1/journal-entries/{$reversal->id}", [
        'entry_date' => '2026-05-21',
        'lines' => [
            ['account_id' => $this->expense->id, 'debit_cents' => 100, 'credit_cents' => 0],
            ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 100],
        ],
    ], $this->h)->assertStatus(409);

    expect($reversal->fresh()->voided_at)->toBeNull()
        ->and((int) $reversal->fresh()->lines()->sum('debit_cents'))->toBe(5000);
});

it('refuses a header edit of the reversal a void wrote', function () {
    $reversal = app(JournalPoster::class)->void(ownedEntryManualPosted());

    expect(fn () => app(UpdateJournalEntryHeader::class)->handle($reversal, ['memo' => 'Changed']))
        ->toThrow(LinkedJournalEntryException::class);
});

it('withholds void and reverse on the journal page for the reversal a void wrote', function () {
    $reversal = app(JournalPoster::class)->void(ownedEntryManualPosted());

    Livewire::actingAs(actAsCompanyOwner($this->company))
        ->test('pages::journal.show', ['company' => $this->company, 'entry' => $reversal])
        ->assertDontSeeHtml('data-test="void-entry-button"')
        ->assertDontSeeHtml('data-test="delete-transaction-button"')
        ->call('void')
        ->assertForbidden();

    Livewire::actingAs(actAsCompanyOwner($this->company))
        ->test('pages::journal.show', ['company' => $this->company, 'entry' => $reversal])
        ->set('reverseDate', '2026-07-01')
        ->call('reverse')
        ->assertForbidden();

    expect($reversal->fresh()->voided_at)->toBeNull()
        ->and(JournalEntry::query()->where('reverses_entry_id', $reversal->id)->exists())->toBeFalse();
});

it('sends the journal form back to the entry for the reversal a void wrote', function () {
    $reversal = app(JournalPoster::class)->void(ownedEntryManualPosted());

    Livewire::actingAs(actAsCompanyOwner($this->company))
        ->test('pages::journal.form', ['company' => $this->company, 'entry' => $reversal])
        ->assertRedirect(route('journal.show', ['company' => $this->company->slug, 'entry' => $reversal->id]));
});
