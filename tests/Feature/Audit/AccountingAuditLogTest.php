<?php

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Acme Corp',
        'is_customer' => true,
    ]);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeAndPostInvoice(string $invoiceNo): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => $invoiceNo,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $totals = app(TaxCalculator::class)->line('1', 10000);

    $invoice->lines()->create([
        'account_id' => test()->incomeAccount->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->refresh();
}

it('records an invoice.created row when a draft invoice is saved', function () {
    Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-DRAFT',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $rows = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('action', AuditAction::InvoiceCreated)
        ->get();

    expect($rows)->toHaveCount(1);

    // Chart seeding + the contact in beforeEach already wrote audit rows, so the
    // invoice row is not the chain head — assert it chains off its predecessor.
    $row = $rows->first();
    $predecessor = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('sequence', $row->sequence - 1)
        ->first();

    expect($row->previous_hash)->toBe($predecessor->row_hash);
});

it('emits invoice.posted and journal_entry.posted with a valid hash chain', function () {
    makeAndPostInvoice('INV-CHAIN-1');

    $rows = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->orderBy('sequence')
        ->get();

    expect($rows->pluck('action')->map(fn ($a) => $a->value)->all())->toContain(
        AuditAction::InvoiceCreated->value,
        AuditAction::JournalEntryPosted->value,
        AuditAction::InvoicePosted->value,
    );

    // Sequence is monotonic, starting at 1
    expect($rows->first()->sequence)->toBe(1);
    expect($rows->pluck('sequence')->all())->toBe(range(1, $rows->count()));

    // Each row's previous_hash matches the prior row's row_hash
    $prev = AccountingAuditRecorder::GENESIS_HASH;
    foreach ($rows as $row) {
        expect($row->previous_hash)->toBe($prev);
        $prev = $row->row_hash;
    }
});

it('mutes Eloquent observers during posting so updates are not double-recorded', function () {
    makeAndPostInvoice('INV-MUTE-1');

    $invoiceUpdateRows = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('action', AuditAction::InvoiceUpdated)
        ->count();

    // The poster mutes the observer; we should NOT see invoice.updated rows
    // for the posted_at/status/journal_entry_id mutation done by the poster.
    expect($invoiceUpdateRows)->toBe(0);
});

it('produces gap-free sequences across two postings in the same company', function () {
    makeAndPostInvoice('INV-SEQ-1');
    makeAndPostInvoice('INV-SEQ-2');

    $sequences = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->orderBy('sequence')
        ->pluck('sequence')
        ->all();

    expect($sequences)->toBe(range(1, count($sequences)));
});

it('rejects raw UPDATE on accounting_audit_logs at the database trigger', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL trigger-only behavior.');
    }

    makeAndPostInvoice('INV-IMMUT');

    $row = AccountingAuditLog::query()->withoutGlobalScopes()->first();

    expect(function () use ($row) {
        DB::table('accounting_audit_logs')->where('id', $row->id)->update(['action' => 'tampered']);
    })->toThrow(QueryException::class);
});

it('rejects raw DELETE on accounting_audit_logs at the database trigger', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MySQL trigger-only behavior.');
    }

    makeAndPostInvoice('INV-IMMUT-DEL');

    $row = AccountingAuditLog::query()->withoutGlobalScopes()->first();

    expect(function () use ($row) {
        DB::table('accounting_audit_logs')->where('id', $row->id)->delete();
    })->toThrow(QueryException::class);
});

it('refuses to update an AccountingAuditLog via Eloquent', function () {
    makeAndPostInvoice('INV-IMMUT-E');

    $row = AccountingAuditLog::query()->withoutGlobalScopes()->first();

    expect(function () use ($row) {
        $row->action = AuditAction::InvoiceVoided;
        $row->save();
    })->toThrow(LogicException::class);
});
