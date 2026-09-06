<?php

use App\Enums\AccountSubtype;
use App\Exceptions\Posting\PostedDocumentDeletionException;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Deposit;
use App\Models\JournalEntry;
use App\Models\PayRun;
use App\Models\StockAdjustment;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Models\Transfer;

/**
 * Finding #1 follow-up: GuardsPostedDeletion previously only covered Invoice,
 * Bill, BillPayment, CustomerReceipt, SalesReceipt. This covers the eight
 * models it was missing from — each also carries a journal_entry_id (or, for
 * JournalEntry itself, is_posted) once posted. A real posted JournalEntry is
 * attached directly rather than routed through each document's Poster
 * service, since the guard only cares about the FK/flag, not GL balance.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function guardPostedJournalEntry(): JournalEntry
{
    return JournalEntry::create([
        'entry_no' => 'JE-G'.fake()->unique()->numberBetween(1000, 999999),
        'entry_date' => now()->toDateString(),
        'is_posted' => true,
        'posted_at' => now(),
    ]);
}

it('refuses to delete a posted journal entry directly', function () {
    $entry = guardPostedJournalEntry();

    expect(fn () => $entry->delete())->toThrow(PostedDocumentDeletionException::class);
});

it('allows deleting a draft journal entry', function () {
    $entry = JournalEntry::create([
        'entry_no' => 'JE-DRAFT',
        'entry_date' => now()->toDateString(),
        'is_posted' => false,
    ]);

    $entry->delete();

    expect(JournalEntry::whereKey($entry->id)->exists())->toBeFalse();
});

it('refuses to delete a posted credit memo', function () {
    $customer = Contact::create(['display_name' => 'Guard Customer', 'is_customer' => true]);
    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-G1',
        'credit_memo_date' => now()->toDateString(),
        'journal_entry_id' => guardPostedJournalEntry()->id,
    ]);

    expect(fn () => $memo->delete())->toThrow(PostedDocumentDeletionException::class);
});

it('refuses to delete a posted cheque', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => 'CHQ-G1',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Guard Payee',
        'amount_cents' => 10000,
        'journal_entry_id' => guardPostedJournalEntry()->id,
    ]);

    expect(fn () => $cheque->delete())->toThrow(PostedDocumentDeletionException::class);
});

it('refuses to delete a posted deposit', function () {
    $deposit = Deposit::create([
        'bank_account_id' => $this->bank->id,
        'deposit_no' => 'DEP-G1',
        'deposit_date' => now()->toDateString(),
        'amount_cents' => 10000,
        'journal_entry_id' => guardPostedJournalEntry()->id,
    ]);

    expect(fn () => $deposit->delete())->toThrow(PostedDocumentDeletionException::class);
});

it('refuses to delete a posted transfer', function () {
    $other = Account::query()->where('subtype', AccountSubtype::Bank->value)->skip(1)->first()
        ?? Account::query()->where('id', '!=', $this->bank->id)->first();

    $transfer = Transfer::create([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $other->id,
        'transfer_no' => 'TRF-G1',
        'transfer_date' => now()->toDateString(),
        'from_amount_cents' => 10000,
        'to_amount_cents' => 10000,
        'journal_entry_id' => guardPostedJournalEntry()->id,
    ]);

    expect(fn () => $transfer->delete())->toThrow(PostedDocumentDeletionException::class);
});

it('refuses to delete a posted stock adjustment', function () {
    $adjustment = StockAdjustment::create([
        'adjustment_no' => 'ADJ-G1',
        'adjustment_date' => now()->toDateString(),
        'reason' => 'other',
        'journal_entry_id' => guardPostedJournalEntry()->id,
    ]);

    expect(fn () => $adjustment->delete())->toThrow(PostedDocumentDeletionException::class);
});

it('refuses to delete a posted pay run', function () {
    $payRun = PayRun::factory()->create([
        'company_id' => $this->company->id,
        'journal_entry_id' => guardPostedJournalEntry()->id,
    ]);

    expect(fn () => $payRun->delete())->toThrow(PostedDocumentDeletionException::class);
});

it('refuses to delete a posted tax return payment', function () {
    $taxPayable = Account::query()->where('subtype', AccountSubtype::TaxPayable->value)->first();
    $agency = TaxAgency::create(['name' => 'Guard Tax Agency', 'payable_account_id' => $taxPayable->id]);
    $taxReturn = TaxReturn::create([
        'tax_agency_id' => $agency->id,
        'tax_return_no' => 'TR-G1',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
    ]);

    $payment = TaxReturnPayment::create([
        'tax_return_id' => $taxReturn->id,
        'payment_no' => 'TRP-G1',
        'payment_date' => now()->toDateString(),
        'direction' => 'outgoing',
        'bank_account_id' => $this->bank->id,
        'net_amount_cents' => 10000,
        'total_cents' => 10000,
        'journal_entry_id' => guardPostedJournalEntry()->id,
    ]);

    expect(fn () => $payment->delete())->toThrow(PostedDocumentDeletionException::class);
});
