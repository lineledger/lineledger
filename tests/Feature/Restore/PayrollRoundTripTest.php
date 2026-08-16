<?php

use App\Actions\Payroll\IssuePayrollCheques;
use App\Actions\Payroll\RecordPayrollRemittance;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\CompanyRestore;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\Membership;
use App\Models\PayrollRemittance;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkersCompSetting;
use App\Services\Backup\CompanyExporter;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Restore\CompanyImporter;
use Illuminate\Support\Facades\Storage;

it('round-trips payroll data, remapping foreign keys onto the restored company', function () {
    Storage::fake('local');
    Storage::fake('public');

    $owner = User::factory()->create(['email' => 'payroll-owner@acme.test']);
    $companyA = Company::factory()->create(['name' => 'Payroll Co', 'address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $companyA->id, 'user_id' => $owner->id, 'role' => CompanyRole::Owner]);

    app()->instance('current_company', $companyA);

    $schedule = PayrollSchedule::factory()->create();
    WorkersCompSetting::create(['province' => 'AB', 'rate_bp' => 137, 'is_active' => true]);
    $employee = Contact::create(['display_name' => 'Round Tripper', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
    ]);

    $bank = Account::query()->where('code', '1000')->firstOrFail();

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());
    app(IssuePayrollCheques::class)->handle($run->fresh(), 9001);

    // Record a CRA remittance (a journal + bank FK to remap on restore).
    app(RecordPayrollRemittance::class)->handle([
        'agency' => 'cra',
        'period_start' => '2025-06-01',
        'period_end' => '2025-06-30',
        'due_date' => '2025-07-15',
        'bank_account_id' => $bank->id,
        'payment_date' => '2025-07-10',
        'reference' => 'RT-REMIT',
    ]);

    // A time entry whose FKs span groups — contacts (employee + customer, core),
    // classifications (core) and pay_runs (payroll). All must remap on restore.
    $timeCustomer = Contact::create(['display_name' => 'Time Customer', 'is_customer' => true]);
    $timeClass = Classification::create(['name' => 'Project X', 'is_active' => true]);
    TimeEntry::create([
        'contact_id' => $employee->id,
        'date_worked' => '2025-06-03',
        'hours' => 8,
        'billable' => true,
        'customer_id' => $timeCustomer->id,
        'class_id' => $timeClass->id,
        'status' => 'approved',
        'pay_run_id' => $run->id,
    ]);

    // ---- Export ----
    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);
    app(CompanyExporter::class)->export($backup);
    $backup->refresh();

    $zipAbs = Storage::disk('local')->path($backup->file_path);
    $restoreRelative = 'restores/'.basename($zipAbs);
    Storage::disk('local')->put($restoreRelative, file_get_contents($zipAbs));

    $restore = CompanyRestore::create([
        'requested_by_user_id' => $owner->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $restoreRelative,
        'file_size_bytes' => Storage::disk('local')->size($restoreRelative),
        'sha256' => hash_file('sha256', Storage::disk('local')->path($restoreRelative)),
    ]);

    app()->forgetInstance('current_company');
    app(CompanyImporter::class)->import($restore);
    $restore->refresh();

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed);

    $companyB = Company::find($restore->company_id);
    app()->instance('current_company', $companyB);

    // ---- Assert payroll data restored with FKs pointing at Company B's rows ----
    $runB = PayRun::query()->where('company_id', $companyB->id)->firstOrFail();
    $lineB = $runB->lines()->firstOrFail();
    $employeeB = Contact::query()->where('company_id', $companyB->id)->where('is_employee', true)->firstOrFail();

    expect(PayRun::query()->where('company_id', $companyB->id)->count())->toBe(1)
        ->and($runB->status)->toBe(PayRunStatus::Paid)
        ->and($lineB->contact_id)->toBe($employeeB->id)
        ->and($lineB->pay_run_id)->toBe($runB->id)
        ->and($employeeB->payrollProfile)->not->toBeNull()
        ->and($employeeB->payrollProfile->payroll_schedule_id)->toBe(PayrollSchedule::query()->where('company_id', $companyB->id)->value('id'));

    // The pay-run journal entry is restored, company-scoped, and linked via the
    // forward FK. (The polymorphic source_id BACK-pointer is left stale on
    // restore — an app-wide limitation shared with posted invoices/bills, since
    // journal_entries are imported before the documents they reference.)
    expect($runB->journal_entry_id)->not->toBeNull()
        ->and($runB->journalEntry)->not->toBeNull()
        ->and($runB->journalEntry->company_id)->toBe($companyB->id);

    // The cheque restored and links to B's run + line.
    $chequeB = $runB->cheques()->firstOrFail();
    expect($chequeB->pay_run_line_id)->toBe($lineB->id)
        ->and($chequeB->payee_contact_id)->toBe($employeeB->id)
        ->and($chequeB->journal_entry_id)->not->toBeNull();

    // The recorded remittance restored with its bank + JE FKs remapped onto Company B.
    $remittanceB = PayrollRemittance::query()->where('company_id', $companyB->id)->firstOrFail();
    $bankB = Account::query()->where('company_id', $companyB->id)->where('code', '1000')->value('id');
    expect($remittanceB->agency->value)->toBe('cra')
        ->and($remittanceB->bank_account_id)->toBe($bankB)
        ->and($remittanceB->journal_entry_id)->not->toBeNull()
        ->and($remittanceB->journalEntry->company_id)->toBe($companyB->id);

    // Per-province workers'-comp settings restore under Company B.
    $wcB = WorkersCompSetting::query()->where('company_id', $companyB->id)->firstOrFail();
    expect($wcB->province)->toBe('AB')->and($wcB->rate_bp)->toBe(137);

    // The time entry restores with every cross-group FK remapped onto Company B.
    $teB = TimeEntry::query()->where('company_id', $companyB->id)->firstOrFail();
    $timeCustomerB = Contact::query()->where('company_id', $companyB->id)->where('is_customer', true)->firstOrFail();
    $classB = Classification::query()->where('company_id', $companyB->id)->firstOrFail();
    expect($teB->contact_id)->toBe($employeeB->id)
        ->and($teB->customer_id)->toBe($timeCustomerB->id)
        ->and($teB->class_id)->toBe($classB->id)
        ->and($teB->pay_run_id)->toBe($runB->id);

    app()->forgetInstance('current_company');
});
