<?php

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\AuditChainCheckpoint;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Notifications\LedgerIntegrityAlert;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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

function postAnInvoice(string $no): void
{
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => $no,
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
}

it('verifies an intact chain with exit code 0', function () {
    postAnInvoice('INV-V-1');
    postAnInvoice('INV-V-2');

    $this->artisan('audit:verify')
        ->assertExitCode(0);
});

it('verifies a chain longer than one keyset page', function () {
    // The verifier streams rows in pages of 1000 rather than loading the whole
    // chain, so the previous_hash handoff has to survive a page boundary. Build
    // a chain that straddles one and assert every row is still walked.
    $recorder = app(AccountingAuditRecorder::class);

    foreach (range(1, 1050) as $i) {
        $recorder->record(
            $this->company->id,
            AuditAction::AccountUpdated,
            $this->incomeAccount,
            ['changes' => ['n' => $i]],
        );
    }

    $total = AccountingAuditLog::query()->withoutGlobalScopes()->count();

    expect($total)->toBeGreaterThan(1000);

    $this->artisan('audit:verify')
        ->expectsOutputToContain("checked {$total} row(s) from genesis, 0 issue(s)")
        ->assertExitCode(0);
});

it('checkpoints a clean chain and resumes from it on the next run', function () {
    postAnInvoice('INV-CP-1');

    $this->artisan('audit:verify')->assertExitCode(0);

    $checkpoint = AuditChainCheckpoint::query()->where('company_id', $this->company->id)->first();
    $tip = AccountingAuditLog::query()->withoutGlobalScopes()->orderByDesc('sequence')->first();

    expect($checkpoint)->not->toBeNull()
        ->and($checkpoint->last_verified_sequence)->toBe((int) $tip->sequence)
        ->and($checkpoint->last_verified_row_hash)->toBe($tip->row_hash);

    // Second run sees only what was written after the watermark.
    $before = (int) $tip->sequence;
    postAnInvoice('INV-CP-2');
    $added = AccountingAuditLog::query()->withoutGlobalScopes()->where('sequence', '>', $before)->count();

    $this->artisan('audit:verify')
        ->expectsOutputToContain("checked {$added} row(s) after sequence {$before}")
        ->assertExitCode(0);
});

it('re-verifies from genesis when --full is passed', function () {
    postAnInvoice('INV-CP-FULL');

    $this->artisan('audit:verify')->assertExitCode(0);

    $total = AccountingAuditLog::query()->withoutGlobalScopes()->count();

    $this->artisan('audit:verify', ['--full' => true])
        ->expectsOutputToContain("checked {$total} row(s) from genesis")
        ->assertExitCode(0);
});

it('discards a checkpoint that no longer matches the chain', function () {
    postAnInvoice('INV-CP-STALE');

    $this->artisan('audit:verify')->assertExitCode(0);

    // Simulate a watermark that describes rows this chain does not have — what
    // a forged checkpoint, or a company restored onto a different chain, looks
    // like. It must be distrusted, not used to skip verification.
    AuditChainCheckpoint::query()->where('company_id', $this->company->id)->update([
        'last_verified_row_hash' => str_repeat('a', 64),
    ]);

    $total = AccountingAuditLog::query()->withoutGlobalScopes()->count();

    $this->artisan('audit:verify')
        ->expectsOutputToContain('no longer matches the chain')
        ->expectsOutputToContain("checked {$total} row(s) from genesis")
        ->assertExitCode(0);
});

it('does not advance the checkpoint past a broken chain', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Trigger drop only relevant on MySQL.');
    }

    postAnInvoice('INV-CP-BREAK');

    $this->artisan('audit:verify')->assertExitCode(0);

    $stale = AuditChainCheckpoint::query()->where('company_id', $this->company->id)->first();

    // Tamper with an already-verified row, then force a full re-walk. The break
    // must be reported and the watermark left where it was — advancing it would
    // hide the break from every later incremental run.
    $row = AccountingAuditLog::query()->withoutGlobalScopes()->orderBy('sequence')->first();

    DB::unprepared('DROP TRIGGER IF EXISTS accounting_audit_logs_no_update');

    try {
        DB::table('accounting_audit_logs')
            ->where('id', $row->id)
            ->update(['payload' => json_encode(['tampered' => true])]);

        $this->artisan('audit:verify', ['--full' => true])->assertExitCode(1);

        $after = AuditChainCheckpoint::query()->where('company_id', $this->company->id)->first();

        expect($after->last_verified_sequence)->toBe($stale->last_verified_sequence)
            ->and($after->verified_at->timestamp)->toBe($stale->verified_at->timestamp);
    } finally {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER accounting_audit_logs_no_update
            BEFORE UPDATE ON accounting_audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'accounting_audit_logs are immutable';
        SQL);
    }
});

it('reports a hash mismatch when a row payload is tampered with', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Trigger drop only relevant on MySQL.');
    }

    postAnInvoice('INV-V-TAMPER');

    $row = AccountingAuditLog::query()->withoutGlobalScopes()->orderBy('sequence')->first();

    // Drop the trigger temporarily so we can simulate a tamper
    DB::unprepared('DROP TRIGGER IF EXISTS accounting_audit_logs_no_update');

    try {
        DB::table('accounting_audit_logs')
            ->where('id', $row->id)
            ->update(['payload' => json_encode(['tampered' => true])]);

        $this->artisan('audit:verify')
            ->assertExitCode(1)
            ->expectsOutputToContain('Payload mismatch');
    } finally {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER accounting_audit_logs_no_update
            BEFORE UPDATE ON accounting_audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'accounting_audit_logs are immutable';
        SQL);
    }
});

it('emails ops on a standalone failure and honours --no-alert', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Trigger drop only relevant on MySQL.');
    }

    config()->set('services.ledger_integrity.alert_email', 'ops@example.com');
    postAnInvoice('INV-ALERT');

    $row = AccountingAuditLog::query()->withoutGlobalScopes()->orderBy('sequence')->first();
    DB::unprepared('DROP TRIGGER IF EXISTS accounting_audit_logs_no_update');

    try {
        DB::table('accounting_audit_logs')
            ->where('id', $row->id)
            ->update(['payload' => json_encode(['tampered' => true])]);

        Notification::fake();
        $this->artisan('audit:verify')->assertExitCode(1);
        Notification::assertSentOnDemand(LedgerIntegrityAlert::class);

        // --no-alert (the path integrity:check and the reports UI use) stays silent.
        Notification::fake();
        $this->artisan('audit:verify', ['--no-alert' => true])->assertExitCode(1);
        Notification::assertNothingSent();
    } finally {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER accounting_audit_logs_no_update
            BEFORE UPDATE ON accounting_audit_logs
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'accounting_audit_logs are immutable';
        SQL);
    }
});
