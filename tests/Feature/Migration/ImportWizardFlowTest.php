<?php

use App\Enums\AccountSubtype;
use App\Enums\DataMigrationStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\CustomersImporter;
use App\Services\Migration\Importers\OpenInvoicesImporter;
use App\Services\Migration\Importers\TrialBalanceImporter;
use App\Services\Migration\Importers\VendorsImporter;
use App\Services\Migration\QuickBooksMigrationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 8]);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('runs a full migration: customers, vendors, open invoices, trial balance, then finalize', function () {
    $service = app(QuickBooksMigrationService::class);
    $run = $service->startOrResume($this->company, CarbonImmutable::create(2026, 7, 31));

    $ctx = new ImportContext(
        company: $this->company,
        run: $run,
        conversionDate: CarbonImmutable::create(2026, 7, 31),
        useOriginalDates: true,
    );

    // Customers
    $custCsv = tempnam(sys_get_temp_dir(), 'c').'.csv';
    file_put_contents($custCsv, "display_name\nAcme Co\nBeta LLC\n");
    expect(app(CustomersImporter::class)->commit($custCsv, $ctx)->isOk())->toBeTrue();

    // Vendors
    $vendCsv = tempnam(sys_get_temp_dir(), 'v').'.csv';
    file_put_contents($vendCsv, "display_name\nOffice Supply Co\n");
    expect(app(VendorsImporter::class)->commit($vendCsv, $ctx)->isOk())->toBeTrue();

    // Open AR invoices
    $invCsv = tempnam(sys_get_temp_dir(), 'i').'.csv';
    file_put_contents($invCsv, "customer_display_name,invoice_no,invoice_date,due_date,balance_remaining\nAcme Co,INV-1,2026-06-15,2026-07-15,1000.00\nBeta LLC,INV-2,2026-07-15,2026-08-15,500.00\n");
    $invResult = app(OpenInvoicesImporter::class)->commit($invCsv, $ctx);
    expect($invResult->isOk())->toBeTrue();
    expect($invResult->summary['total_ar_cents'])->toBe(150000);

    // Trial balance — bank + retained earnings (P&L closed at FY-end, so no income/expense)
    $bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $retained = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::RetainedEarnings->value)->first();

    $tbCsv = tempnam(sys_get_temp_dir(), 'tb').'.csv';
    file_put_contents(
        $tbCsv,
        "account_code,debit,credit\n".
        "{$bank->code},25000.00,\n".
        "{$retained->code},,26500.00\n",
    );
    $tbResult = app(TrialBalanceImporter::class)->commit($tbCsv, $ctx);
    expect($tbResult->isOk())->toBeTrue();

    // Finalize
    $finalized = $service->finalize($run->fresh());

    expect($finalized->status)->toBe(DataMigrationStatus::Completed);
    expect($this->company->fresh()->lock_date->toDateString())->toBe('2026-07-31');

    // Sanity: AR equals sum of open invoices
    $ar = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $ar->recomputeBalance();
    expect((int) $ar->balance_cents)->toBe(150000);

    // Sanity: bank balance from TB
    $bank->recomputeBalance();
    expect((int) $bank->balance_cents)->toBe(2500000);

    // Sanity: every imported invoice is_opening_balance
    expect(Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_opening_balance', true)->count())->toBe(2);
});

it('resumes an in-progress run instead of starting a new one', function () {
    $service = app(QuickBooksMigrationService::class);

    $first = $service->startOrResume($this->company, CarbonImmutable::create(2026, 7, 31));
    $first->forceFill(['current_step' => 4])->save();

    $second = $service->startOrResume($this->company);

    expect($second->id)->toBe($first->id);
    expect((int) $second->current_step)->toBe(4);
});

it('enforces the lock date after migration completes', function () {
    $service = app(QuickBooksMigrationService::class);
    $run = $service->startOrResume($this->company, CarbonImmutable::create(2026, 7, 31));
    $service->finalize($run);

    $company = $this->company->fresh();
    expect($company->isLockedFor(CarbonImmutable::create(2026, 7, 31)))->toBeTrue()
        ->and($company->isLockedFor(CarbonImmutable::create(2026, 8, 1)))->toBeFalse();
});
