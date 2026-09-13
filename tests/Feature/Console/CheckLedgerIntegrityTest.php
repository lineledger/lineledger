<?php

use App\Console\Commands\CheckLedgerIntegrity;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Notifications\LedgerIntegrityAlert;
use App\Services\Audit\PostedMutationGate;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * integrity:check is the nightly proof the books reconcile: audit hash chain,
 * double-entry balance across the GL, and account-balance cache. It must pass on
 * healthy books and fail loudly (non-zero exit + ops alert) when any check trips.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $customer = Contact::create(['display_name' => 'Integrity Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-INT',
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

    $this->companyId = $this->company->id;
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('passes on healthy books', function () {
    $this->artisan('integrity:check', ['company' => $this->companyId])
        ->assertExitCode(0);
});

it('fails and alerts when the general ledger is out of balance', function () {
    Notification::fake();

    // Tamper a single posted journal line so debits no longer equal credits.
    $lineId = DB::table('journal_lines')->orderBy('id')->value('id');
    PostedMutationGate::within(fn () => DB::table('journal_lines')->where('id', $lineId)->update([
        'debit_cents' => DB::raw('debit_cents + 100'),
    ]));

    $this->artisan('integrity:check', ['company' => $this->companyId])
        ->assertExitCode(1);

    Notification::assertSentOnDemand(LedgerIntegrityAlert::class);
});

it('detects a drifted account-balance cache and heals it with --fix, but still alerts', function () {
    Notification::fake();

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $income->forceFill(['balance_cents' => 999999])->saveQuietly();

    // Without --fix: reported as an issue, non-zero exit.
    $this->artisan('integrity:check', ['company' => $this->companyId, '--no-alert' => true])
        ->assertExitCode(1);

    // With --fix: the cache is recomputed in place, but drift is a real signal —
    // healing it in the same run must never absorb that signal silently, so the
    // run still fails and still alerts (Finding #2, 2026-08-18 security review).
    $this->artisan('integrity:check', ['company' => $this->companyId, '--fix' => true])
        ->assertExitCode(1);

    expect($income->fresh()->balance_cents)->toBe(10000);
    Notification::assertSentOnDemand(LedgerIntegrityAlert::class);
});

it('does not email when --no-alert is set', function () {
    Notification::fake();

    $lineId = DB::table('journal_lines')->orderBy('id')->value('id');
    PostedMutationGate::within(fn () => DB::table('journal_lines')->where('id', $lineId)->update([
        'credit_cents' => DB::raw('credit_cents + 50'),
    ]));

    $this->artisan('integrity:check', ['company' => $this->companyId, '--no-alert' => true])
        ->assertExitCode(1);

    Notification::assertNothingSent();
});

it('rotates the full-verification sweep so every company is covered within the cycle', function () {
    $command = new ReflectionClass(CheckLedgerIntegrity::class);
    $cycle = $command->getConstant('FULL_SWEEP_CYCLE_DAYS');

    $check = function (int $companyId, CarbonImmutable $on): bool {
        Carbon::setTestNow($on);

        $method = new ReflectionMethod(CheckLedgerIntegrity::class, 'shouldFullyVerify');

        return $method->invoke(app(CheckLedgerIntegrity::class), $companyId, false);
    };

    $start = CarbonImmutable::parse('2026-08-03 04:00:00');

    // Each company is swept exactly once per cycle — never skipped, never daily.
    foreach ([1, 7, 30, 4291] as $companyId) {
        $sweeps = collect(range(0, $cycle - 1))
            ->filter(fn (int $offset): bool => $check($companyId, $start->addDays($offset)))
            ->count();

        expect($sweeps)->toBe(1, "company {$companyId} should sweep once per {$cycle}-day cycle");
    }

    // The rotation must survive a year boundary, which is why it counts days
    // from the epoch rather than using the calendar day of month.
    $acrossNewYear = collect(range(0, $cycle - 1))
        ->filter(fn (int $offset): bool => $check(9, CarbonImmutable::parse('2026-12-20')->addDays($offset)))
        ->count();

    expect($acrossNewYear)->toBe(1);

    // An explicitly named company always gets the thorough walk.
    $method = new ReflectionMethod(CheckLedgerIntegrity::class, 'shouldFullyVerify');
    expect($method->invoke(app(CheckLedgerIntegrity::class), 999999, true))->toBeTrue();

    Carbon::setTestNow();
});

/*
 * Check 4 — the invoice/bill paid caches. A cache that disagrees with the
 * live applications shows a document as paid (or owing) that the ledger says
 * otherwise; --fix recomputes it through the poster.
 */
it('fails when an invoice paid cache disagrees with its receipt applications, and --fix repairs it', function () {
    Notification::fake();

    $invoice = Invoice::query()->firstOrFail();
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $receipt = CustomerReceipt::create([
        'contact_id' => $invoice->contact_id,
        'receipt_no' => 'REC-INT',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => 4000,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 4000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $this->artisan('integrity:check', ['company' => $this->companyId])->assertExitCode(0);

    // The drift the old repost left behind: cache says unpaid, ledger says 4000 applied.
    DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid_cents' => 0, 'status' => 'posted']);

    $this->artisan('integrity:check', ['company' => $this->companyId])
        ->expectsOutputToContain('paid cache is 0 but its live receipt applications sum to 4000')
        ->assertExitCode(1);
    Notification::assertSentOnDemand(LedgerIntegrityAlert::class);

    $this->artisan('integrity:check', ['company' => $this->companyId, '--fix' => true])->assertExitCode(0);

    $invoice->refresh();
    expect($invoice->amount_paid_cents)->toBe(4000);
    expect($invoice->status->value)->toBe('partial');
});

it('reports an over-application it cannot repair, even under --fix', function () {
    Notification::fake();

    $invoice = Invoice::query()->firstOrFail();
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $receipt = CustomerReceipt::create([
        'contact_id' => $invoice->contact_id,
        'receipt_no' => 'REC-OVER',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => 10000,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 10000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    // The invoice total is later reduced below what was applied.
    DB::table('invoices')->where('id', $invoice->id)->update(['total_cents' => 8000]);

    $this->artisan('integrity:check', ['company' => $this->companyId, '--fix' => true])
        ->expectsOutputToContain('has 10000 applied from receipts against a total of 8000')
        ->assertExitCode(1);

    // --fix capped the cache at the total; the over-application itself stays reported.
    expect($invoice->fresh()->amount_paid_cents)->toBe(8000);
});

it('checks bill paid caches too, and --fix repairs them', function () {
    $vendor = Contact::create(['display_name' => 'Integrity Vendor', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-INT',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 5000, 'line_subtotal_cents' => 5000, 'line_tax_cents' => 0, 'line_total_cents' => 5000, 'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $payment = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-INT',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $bank->id,
        'amount_cents' => 5000,
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 5000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    $this->artisan('integrity:check', ['company' => $this->companyId])->assertExitCode(0);

    DB::table('bills')->where('id', $bill->id)->update(['amount_paid_cents' => 0, 'status' => 'posted']);

    $this->artisan('integrity:check', ['company' => $this->companyId])
        ->expectsOutputToContain('Bill BILL-INT')
        ->assertExitCode(1);

    $this->artisan('integrity:check', ['company' => $this->companyId, '--fix' => true])
        ->expectsOutputToContain('Repaired bill BILL-INT')
        ->assertExitCode(0);

    expect($bill->fresh()->amount_paid_cents)->toBe(5000);
    expect($bill->fresh()->status->value)->toBe('paid');
});
