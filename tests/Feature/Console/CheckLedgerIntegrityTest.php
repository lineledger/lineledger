<?php

use App\Console\Commands\CheckLedgerIntegrity;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Notifications\LedgerIntegrityAlert;
use App\Services\Posting\InvoicePoster;
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
    DB::table('journal_lines')->where('id', $lineId)->update([
        'debit_cents' => DB::raw('debit_cents + 100'),
    ]);

    $this->artisan('integrity:check', ['company' => $this->companyId])
        ->assertExitCode(1);

    Notification::assertSentOnDemand(LedgerIntegrityAlert::class);
});

it('detects a drifted account-balance cache and heals it with --fix', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $income->forceFill(['balance_cents' => 999999])->saveQuietly();

    // Without --fix: reported as an issue, non-zero exit.
    $this->artisan('integrity:check', ['company' => $this->companyId, '--no-alert' => true])
        ->assertExitCode(1);

    // With --fix: the cache is recomputed in place and the run goes green.
    $this->artisan('integrity:check', ['company' => $this->companyId, '--fix' => true])
        ->assertExitCode(0);

    expect($income->fresh()->balance_cents)->toBe(10000);
});

it('does not email when --no-alert is set', function () {
    Notification::fake();

    $lineId = DB::table('journal_lines')->orderBy('id')->value('id');
    DB::table('journal_lines')->where('id', $lineId)->update([
        'credit_cents' => DB::raw('credit_cents + 50'),
    ]);

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
