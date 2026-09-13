<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Posting\InvoicePoster;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Finding #5 (2026-08-18 security review): the audit hash chain protects
 * accounting_audit_logs, not the live ledger tables. Enforcement that a
 * posted journal_entries/journal_lines row can't be edited or deleted was
 * 100% application-layer — a raw DB connection (compromised container,
 * SQL-injection primitive, an operator in tinker) could bypass Eloquent
 * entirely and tamper with posted financial data, leaving no audit row and
 * defeating the hash chain (which only captured a snapshot at posting time).
 *
 * These triggers close that at the database layer. They must still allow the
 * two reviewed, audit-logged flows that legitimately touch a posted row in
 * place (repost via SaveJournalEntry, void via JournalPoster) — those are
 * covered by PostedMutationGate and exercised in JournalPostingTest /
 * RepostTest; this file only proves the raw-SQL threat model is blocked.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $customer = Contact::create(['display_name' => 'Trigger Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-TRG',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $this->entryId = $invoice->fresh()->journal_entry_id;
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('rejects a raw UPDATE of a posted journal_entries row outside the app', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL trigger-only behavior.');
    }

    expect(fn () => DB::table('journal_entries')->where('id', $this->entryId)->update(['memo' => 'tampered']))
        ->toThrow(QueryException::class, 'immutable at the database layer');
});

it('rejects a raw DELETE of a posted journal_entries row outside the app', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL trigger-only behavior.');
    }

    expect(fn () => DB::table('journal_entries')->where('id', $this->entryId)->delete())
        ->toThrow(QueryException::class, 'immutable at the database layer');
});

it('rejects a raw UPDATE of a posted journal_lines row outside the app (balance-preserving tamper)', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL trigger-only behavior.');
    }

    $lineId = DB::table('journal_lines')->where('journal_entry_id', $this->entryId)->value('id');

    expect(fn () => DB::table('journal_lines')->where('id', $lineId)->update(['account_id' => DB::raw('account_id + 0'), 'debit_cents' => 999999]))
        ->toThrow(QueryException::class, 'immutable at the database layer');
});

it('rejects a raw DELETE of a posted journal_lines row outside the app', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL trigger-only behavior.');
    }

    $lineId = DB::table('journal_lines')->where('journal_entry_id', $this->entryId)->value('id');

    expect(fn () => DB::table('journal_lines')->where('id', $lineId)->delete())
        ->toThrow(QueryException::class, 'immutable at the database layer');
});

it('still allows editing a draft (unposted) journal entry directly', function () {
    $draft = JournalEntry::create([
        'entry_no' => 'JE-TRG-DRAFT',
        'entry_date' => now()->toDateString(),
        'is_posted' => false,
    ]);

    DB::table('journal_entries')->where('id', $draft->id)->update(['memo' => 'still a draft, fine']);

    expect($draft->fresh()->memo)->toBe('still a draft, fine');
});
