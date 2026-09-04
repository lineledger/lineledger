<?php

namespace Database\Seeders;

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Accounting\SaveRecurringJournalEntry;
use App\Actions\Banking\SaveCheque;
use App\Actions\Banking\SaveTransfer;
use App\Actions\Charity\SaveDonationReceipt;
use App\Actions\Companies\CreateCompany;
use App\Actions\Fundraising\SaveDonation;
use App\Actions\Fundraising\SaveGrant;
use App\Actions\Inventory\SaveStockAdjustment;
use App\Actions\MasterData\SaveClassification;
use App\Actions\MasterData\SaveFund;
use App\Actions\MasterData\SaveLocation;
use App\Actions\MasterData\SaveMembershipLevel;
use App\Actions\Membership\SaveMember;
use App\Actions\Payroll\EnsurePayrollAccounts;
use App\Actions\Payroll\IssuePayrollCheques;
use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayrollSchedule;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Payroll\SaveTimeOffPolicy;
use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SaveBillPayment;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Actions\Recurring\SaveRecurringDocument;
use App\Actions\Sales\SaveCreditMemo;
use App\Actions\Sales\SaveEstimate;
use App\Actions\Sales\SaveInvoice;
use App\Actions\Sales\SaveReceipt;
use App\Actions\Sales\SaveSalesOrder;
use App\Enums\AccountSubtype;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\ContributionMethod;
use App\Enums\Country;
use App\Enums\FundType;
use App\Enums\GiftType;
use App\Enums\Industry;
use App\Enums\LegalStructure;
use App\Enums\OrganizationType;
use App\Enums\RecurrenceFrequency;
use App\Enums\StatementLineMatchStatus;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Asset;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\WorkersCompSetting;
use App\Services\Charity\DonationReceiptIssuer;
use App\Services\Fundraising\DonationPoster;
use App\Services\Fundraising\GrantPoster;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\PayRunPoster;
use App\Services\Posting\ReceiptPoster;
use App\Services\Posting\StockAdjustmentPoster;
use App\Services\Posting\TransferPoster;
use App\Support\Defaults\ChartTemplateBuilder;
use App\Support\Legal\LegalDocuments;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Builds a realistic "Demo Company Inc." used to capture the documentation
 * screenshots. Standalone and idempotent — run on demand with:
 *
 *   php artisan db:seed --class=DemoCompanySeeder
 *
 * Not wired into DatabaseSeeder so the test suite (SQLite) is unaffected.
 * Re-running wipes and rebuilds the demo company (every company_id FK
 * cascades on delete), so screenshots can be regenerated at will.
 *
 * Covers the full app surface the docs screenshot: customers, invoices,
 * credit memos, receipts, vendors, bills, payments, estimates, orders,
 * recurring templates, journal entries, banking (transfers, reconciliation,
 * and paused statement-import states), inventory, fixed assets,
 * multi-currency, and Canadian payroll including a Quebec employee.
 */
class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        // This is a development/QA seeder: it creates a `test@example.com` /
        // `password` platform site admin and sample data. Running it against a
        // production database would plant a publicly-known-credential super
        // admin, so refuse loudly rather than silently backdoor the instance.
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'DemoCompanySeeder must not run in production — it seeds a known-credential site admin.'
            );
        }

        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );

        // The demo user doubles as the platform site admin so the admin portal
        // is reachable in local/QA. Forced on each run so re-seeding an existing
        // demo user (firstOrCreate won't update) still grants the role.
        $user->forceFill(['site_admin' => true])->save();

        // Record the demo user's legal acceptance so login lands on the dashboard
        // rather than the acceptance gate.
        app(LegalDocuments::class)->record($user, ['terms', 'privacy']);

        // Fresh start: free the 'demo' slug for a clean rebuild. Rather than
        // hard-delete (RESTRICT foreign keys and immutable audit-log triggers
        // make that brittle), archive any prior demo company by renaming its
        // slug and soft-deleting it. Its data stays scoped to its old id and
        // never surfaces under the new demo company.
        Company::withTrashed()->where('slug', 'demo')->get()->each(function (Company $old): void {
            $old->forceFill(['slug' => 'demo-archived-'.$old->id])->saveQuietly();
            if (! $old->trashed()) {
                $old->delete();
            }
        });

        $company = app(CreateCompany::class)->handle(
            user: $user,
            name: 'Demo Company Inc.',
            country: Country::Canada,
            regionCode: 'ON',
            attributes: ['slug' => 'demo'],
        );

        // Posting reads Auth::id() for posted_by_user_id; scoped models read
        // the bound current_company. Set both for the seeding context.
        Auth::login($user);
        app()->instance('current_company', $company);

        // The class/location tracking dimensions are off by default — turn them
        // on so the docs can screenshot them. Every other feature defaults on.
        $company->update([
            'features_classes' => true,
            'features_locations' => true,
        ]);

        $accounts = $company->accounts()->get();
        $revenue = $accounts->firstWhere('subtype', AccountSubtype::Income);
        $bank = $accounts->firstWhere('subtype', AccountSubtype::Bank);

        $salesTax = TaxCode::query()
            ->where('company_id', $company->id)
            ->whereIn('applies_to', [TaxAppliesTo::SaleOnly, TaxAppliesTo::Both])
            ->where('is_active', true)
            ->first();
        $taxId = $salesTax?->id;

        // --- Customers (one carries a credit limit for the warning screenshot) ---
        // Email consent is off for every new customer, so the first two opt in
        // explicitly — otherwise the reminders worklist and the recurring-invoice
        // schedule below would have nothing to demonstrate. Riverside stays opted
        // out on purpose, to show the "Reminders off" badge and the reveal filter.
        $northwind = Contact::factory()->customer()->emailOptedIn()->create([
            'company_id' => $company->id,
            'display_name' => 'Northwind Traders',
            'company_name' => 'Northwind Traders Ltd.',
            'email' => 'ap@northwind.example',
            'credit_limit_cents' => 250000,
        ]);

        $acme = Contact::factory()->customer()->emailOptedIn()->create([
            'company_id' => $company->id,
            'display_name' => 'Acme Studios',
            'company_name' => 'Acme Studios Inc.',
            'email' => 'billing@acme.example',
        ]);

        $riverside = Contact::factory()->customer()->create([
            'company_id' => $company->id,
            'display_name' => 'Riverside Cafe',
            'company_name' => 'Riverside Cafe',
            'email' => 'owner@riverside.example',
        ]);

        // A couple of vendors so the customers list and selectors look real.
        Contact::factory()->vendor()->create([
            'company_id' => $company->id,
            'display_name' => 'Office Supply Co.',
        ]);
        Contact::factory()->vendor()->create([
            'company_id' => $company->id,
            'display_name' => 'City Utilities',
        ]);

        // A QuickBooks-style "Other name" — a one-time payee that is not a
        // vendor, customer or employee — with one posted cheque, so the payee
        // picker badge, the Settings → Lists → Other names count and the
        // name drill-through into the Transactions report all have demo data.
        $raffleWinner = Contact::factory()->otherName()->create([
            'company_id' => $company->id,
            'display_name' => 'Raffle winner — J. Chen',
        ]);
        $otherNameCheque = app(SaveCheque::class)->handle([
            'bank_account_id' => $bank->id,
            'cheque_no' => '1001',
            'cheque_date' => now()->subDays(20)->toDateString(),
            'payee_contact_id' => $raffleWinner->id,
            'payee_name' => $raffleWinner->display_name,
            'memo' => 'Spring fundraiser draw prize',
            'lines' => [[
                'account_id' => $accounts->firstWhere('subtype', AccountSubtype::Expense)->id,
                'description' => 'Draw prize',
                'amount_cents' => 25000,
                'tax_code_id' => null,
                'tax_override_cents' => null,
                'class_id' => null,
                'location_id' => null,
            ]],
        ]);
        app(ChequePoster::class)->post($otherNameCheque);

        // --- Items ---
        $consulting = Item::factory()->create([
            'company_id' => $company->id,
            'name' => 'Consulting (hourly)',
            'description' => 'Professional consulting services',
            'default_price_cents' => 12000,
        ]);
        Item::factory()->tracked()->create([
            'company_id' => $company->id,
            'name' => 'Branded Mug',
            'description' => '350ml ceramic mug with logo',
            'default_price_cents' => 1800,
        ]);

        $line = fn (int $qty, int $priceCents): array => [
            'item_id' => $consulting->id,
            'account_id' => $revenue->id,
            'description' => $consulting->description,
            'quantity' => $qty,
            'unit_price_cents' => $priceCents,
            'tax_code_id' => $taxId,
        ];

        // --- Invoices in varied states ---
        // Draft (stays unposted so the "draft" state is documented).
        app(SaveInvoice::class)->handle([
            'contact_id' => $acme->id,
            'invoice_date' => now()->subDays(2)->toDateString(),
            'lines' => [$line(5, 12000)],
        ]);

        // Posted.
        $posted = app(SaveInvoice::class)->handle([
            'contact_id' => $riverside->id,
            'invoice_date' => now()->subDays(20)->toDateString(),
            'lines' => [$line(3, 12000)],
        ]);
        app(InvoicePoster::class)->post($posted);

        // Posted and partially paid via a receipt.
        $partlyPaid = app(SaveInvoice::class)->handle([
            'contact_id' => $northwind->id,
            'invoice_date' => now()->subDays(15)->toDateString(),
            'lines' => [$line(10, 12000)],
        ]);
        app(InvoicePoster::class)->post($partlyPaid);

        $receipt = app(SaveReceipt::class)->handle([
            'contact_id' => $northwind->id,
            'receipt_date' => now()->subDays(5)->toDateString(),
            'deposit_to_account_id' => $bank->id,
            'amount_cents' => 60000,
            'applications' => [
                ['invoice_id' => $partlyPaid->id, 'amount_cents' => 60000],
            ],
        ]);
        app(ReceiptPoster::class)->post($receipt);

        // --- Credit memo (standalone) ---
        $creditMemo = app(SaveCreditMemo::class)->handle([
            'contact_id' => $acme->id,
            'credit_memo_date' => now()->subDays(3)->toDateString(),
            'lines' => [[
                'item_id' => $consulting->id,
                'account_id' => $revenue->id,
                'description' => 'Goodwill adjustment',
                'quantity' => 1,
                'unit_price_cents' => 12000,
                'tax_code_id' => $taxId,
            ]],
        ]);
        app(CreditMemoPoster::class)->post($creditMemo);

        // ───────────────────────── Tracking dimensions ─────────────────────────
        foreach (['Funeral Services', 'Cremation Services', 'Merchandise'] as $name) {
            app(SaveClassification::class)->handle(['name' => $name]);
        }
        foreach (['Main Chapel', 'North Branch'] as $name) {
            app(SaveLocation::class)->handle(['name' => $name]);
        }

        // ───────────────────────── Estimates / orders ──────────────────────────
        app(SaveEstimate::class)->handle([
            'contact_id' => $riverside->id,
            'estimate_date' => now()->subDays(10)->toDateString(),
            'expires_on' => now()->addDays(20)->toDateString(),
            'lines' => [$line(8, 12000)],
        ]);

        app(SaveSalesOrder::class)->handle([
            'contact_id' => $acme->id,
            'order_date' => now()->subDays(7)->toDateString(),
            'expected_date' => now()->addDays(7)->toDateString(),
            'lines' => [$line(12, 12000)],
        ]);

        // ───────────────────────── Vendors: bills & payments ───────────────────
        $expense = $accounts->firstWhere('subtype', AccountSubtype::Expense);
        $officeSupply = Contact::where('company_id', $company->id)
            ->where('display_name', 'Office Supply Co.')->first();
        $cityUtilities = Contact::where('company_id', $company->id)
            ->where('display_name', 'City Utilities')->first();

        $billLine = fn (int $qty, int $priceCents): array => [
            'account_id' => $expense->id,
            'description' => 'Office supplies',
            'quantity' => $qty,
            'unit_price_cents' => $priceCents,
            'tax_code_id' => $taxId,
        ];

        // A posted bill that gets fully paid, and one left open.
        $paidBill = app(SaveBill::class)->handle([
            'contact_id' => $officeSupply->id,
            'bill_date' => now()->subDays(18)->toDateString(),
            'lines' => [$billLine(1, 24000)],
        ]);
        app(BillPoster::class)->post($paidBill);

        $payment = app(SaveBillPayment::class)->handle([
            'contact_id' => $officeSupply->id,
            'payment_date' => now()->subDays(4)->toDateString(),
            'paid_from_account_id' => $bank->id,
            'amount_cents' => $paidBill->fresh()->total_cents,
            'applications' => [
                ['bill_id' => $paidBill->id, 'amount_cents' => $paidBill->fresh()->total_cents],
            ],
        ]);
        app(BillPaymentPoster::class)->post($payment);

        $openBill = app(SaveBill::class)->handle([
            'contact_id' => $cityUtilities->id,
            'bill_date' => now()->subDays(6)->toDateString(),
            'lines' => [[
                'account_id' => $expense->id,
                'description' => 'Monthly utilities',
                'quantity' => 1,
                'unit_price_cents' => 18000,
                'tax_code_id' => $taxId,
            ]],
        ]);
        app(BillPoster::class)->post($openBill);

        // ───────────────────────── Purchase order ──────────────────────────────
        app(SavePurchaseOrder::class)->handle([
            'contact_id' => $officeSupply->id,
            'po_date' => now()->subDays(3)->toDateString(),
            'expected_date' => now()->addDays(10)->toDateString(),
            'lines' => [[
                'account_id' => $expense->id,
                'description' => 'Printer paper (10 boxes)',
                'quantity' => 10,
                'unit_price_cents' => 4500,
                'tax_code_id' => $taxId,
            ]],
        ]);

        // ───────────────────────── Recurring invoice template ──────────────────
        app(SaveRecurringDocument::class)->handle([
            'document_type' => 'invoice',
            'contact_id' => $northwind->id,
            'name' => 'Monthly retainer',
            'frequency' => 'monthly',
            'start_date' => now()->startOfMonth()->toDateString(),
            'day_of_month' => 1,
            'end_type' => 'never',
            'lines' => [[
                'item_id' => $consulting->id,
                'account_id' => $revenue->id,
                'description' => 'Monthly retainer',
                'quantity' => 1,
                'unit_price_cents' => 150000,
                'tax_code_id' => $taxId,
            ]],
        ]);

        // ───────────────────────── Manual journal entry ────────────────────────
        $equity = $accounts->firstWhere('subtype', AccountSubtype::Equity)
            ?? $accounts->firstWhere('subtype', AccountSubtype::RetainedEarnings);
        $ownerContribution = app(SaveJournalEntry::class)->handle([
            'entry_date' => now()->subDays(25)->toDateString(),
            'memo' => 'Owner capital contribution',
            'lines' => [
                ['account_id' => $bank->id, 'debit_cents' => 500000, 'credit_cents' => 0],
                ['account_id' => $equity->id, 'debit_cents' => 0, 'credit_cents' => 500000],
            ],
        ]);
        app(JournalPoster::class)->post($ownerContribution);

        // A recurring journal entry template (e.g. monthly depreciation).
        app(SaveRecurringJournalEntry::class)->handle([
            'name' => 'Monthly depreciation',
            'memo' => 'Depreciation expense',
            'frequency' => 'monthly',
            'start_date' => now()->startOfMonth()->toDateString(),
            'day_of_month' => 28,
            'end_type' => 'never',
            'lines' => [
                ['account_id' => $expense->id, 'debit_cents' => 20000, 'credit_cents' => 0],
                ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 20000],
            ],
        ]);

        // ───────────────────────── Banking: transfer & reconciliation ──────────
        $savings = $accounts->firstWhere(fn (Account $a) => $a->subtype === AccountSubtype::Bank && $a->id !== $bank->id)
            ?? Account::create([
                'company_id' => $company->id,
                'code' => '1065',
                'name' => 'Savings',
                'subtype' => AccountSubtype::Bank,
                'is_system' => false,
            ]);

        $transfer = app(SaveTransfer::class)->handle([
            'from_account_id' => $bank->id,
            'to_account_id' => $savings->id,
            'transfer_date' => now()->subDays(8)->toDateString(),
            'from_amount_cents' => 100000,
            'to_amount_cents' => 100000,
            'memo' => 'Move surplus to savings',
        ]);
        app(TransferPoster::class)->post($transfer);

        BankReconciliation::factory()->completed()->create([
            'company_id' => $company->id,
            'account_id' => $bank->id,
            'statement_date' => now()->subMonth()->endOfMonth()->toDateString(),
            'beginning_balance_cents' => 0,
            'ending_balance_cents' => 440000,
        ]);

        // ───────────────────────── Inventory opening stock ─────────────────────
        $mug = Item::where('company_id', $company->id)->where('name', 'Branded Mug')->first();
        $adjustment = app(SaveStockAdjustment::class)->handle([
            'adjustment_date' => now()->subDays(30)->toDateString(),
            'reason' => 'opening_balance',
            'notes' => 'Opening stock count',
            'lines' => [[
                'item_id' => $mug->id,
                'qty_change' => 50,
                'unit_cost_cents' => 900,
            ]],
        ]);
        app(StockAdjustmentPoster::class)->post($adjustment);

        // ───────────────────────── Fixed asset ─────────────────────────────────
        $fixedAssetAccount = $accounts->firstWhere('subtype', AccountSubtype::FixedAsset);
        if ($fixedAssetAccount) {
            Asset::factory()->create([
                'company_id' => $company->id,
                'asset_account_id' => $fixedAssetAccount->id,
                'name' => 'Delivery Van',
                'description' => '2024 cargo van',
                'acquired_date' => now()->subMonths(6)->toDateString(),
                'in_service_date' => now()->subMonths(6)->toDateString(),
                'cost_cents' => 3500000,
                'salvage_value_cents' => 500000,
                'useful_life_months' => 60,
            ]);
        }

        // ───────────────────────── Multi-currency ──────────────────────────────
        app(EnableCompanyCurrency::class)->handle($company, 'USD');
        Contact::factory()->customer()->create([
            'company_id' => $company->id,
            'display_name' => 'Stateside Imports (USD)',
            'company_name' => 'Stateside Imports LLC',
            'email' => 'ap@stateside.example',
            'currency_code' => 'USD',
        ]);

        // ───────────────────────── Employees ───────────────────────────────────
        $jordan = Contact::factory()->create([
            'company_id' => $company->id,
            'display_name' => 'Jordan Avery',
            'first_name' => 'Jordan',
            'last_name' => 'Avery',
            'job_title' => 'Operations Manager',
            'email' => 'jordan@demo.example',
            'is_employee' => true,
        ]);

        // A Quebec-based employee so the Revenu Québec remittance and RL-1 slip
        // screenshots have real Quebec deductions (QPP/QPIP/Quebec tax) to show.
        $camille = Contact::factory()->create([
            'company_id' => $company->id,
            'display_name' => 'Camille Tremblay',
            'first_name' => 'Camille',
            'last_name' => 'Tremblay',
            'job_title' => 'Sales Associate',
            'email' => 'camille@demo.example',
            'is_employee' => true,
        ]);

        // ───────────────────────── Payroll (Canadian, incl. Quebec) ────────────
        // Turn payroll on, create the system payroll accounts, and set the Quebec
        // employer levies so the Quebec payroll docs screenshot real numbers.
        $company->update([
            'features_payroll' => true,
            'qhsf_rate_bp' => 193,   // QHSF 1.93% of Quebec gross pay
            'cnesst_rate_bp' => 150, // CNESST 1.50% of Quebec insurable earnings
            'wsdrf_applicable' => false,
        ]);
        app(EnsurePayrollAccounts::class)->handle($company);

        $schedule = app(SavePayrollSchedule::class)->handle([
            'name' => 'Bi-weekly',
            'frequency' => 'biweekly',
            'anchor_period_end_date' => now()->toDateString(),
            'default_pay_offset_days' => 5,
            'is_active' => true,
        ]);

        // Time-off policy presets: sick accrues per pay period (capped + carryover);
        // personal is granted as an annual lump at the start of the year.
        $sickPolicy = app(SaveTimeOffPolicy::class)->handle([
            'name' => 'Sick leave', 'code' => 'sick', 'category' => 'sick', 'unit' => 'hours',
            'accrual_method' => 'per_pay_period', 'rate_hours' => 1.5,
            'annual_cap_hours' => 40, 'carryover_max_hours' => 40, 'paid' => true, 'is_default' => true,
        ]);
        app(SaveTimeOffPolicy::class)->handle([
            'name' => 'Personal days', 'code' => 'personal', 'category' => 'personal', 'unit' => 'hours',
            'accrual_method' => 'beginning_of_year', 'rate_hours' => 24,
            'annual_cap_hours' => 24, 'carryover_max_hours' => 0, 'paid' => true,
        ]);

        // Ontario WSIB assessment rate, so the rest-of-Canada employee accrues
        // workers' comp (Quebec stays on CNESST).
        WorkersCompSetting::create(['province' => 'ON', 'rate_bp' => 137, 'is_active' => true]);

        $payrollProfile = fn (Contact $employee, string $province, int $salaryCents, int $provincialClaimCents, array $timeOff = []): array => [
            'contact_id' => $employee->id,
            'sin' => '000000000',
            'date_of_birth' => '1988-05-20',
            'hire_date' => now()->subYears(3)->toDateString(),
            'province_of_employment' => $province,
            'pay_basis' => 'salary',
            'annual_salary_cents' => $salaryCents,
            'payroll_schedule_id' => $schedule->id,
            'td1_federal_claim_cents' => 1612900,
            'td1_federal_code' => '1',
            'td1_provincial_claim_cents' => $provincialClaimCents,
            'td1_provincial_code' => '1',
            'cpp_exempt' => false,
            'ei_exempt' => false,
            'qpip_exempt' => false,
            'additional_tax_per_period_cents' => 0,
            'vacation_policy' => 'accrue',
            'vacation_rate_bp' => 400,
            'is_active' => true,
            'time_off_policies' => $timeOff,
        ];

        app(SaveEmployeePayrollProfile::class)->handle($payrollProfile($jordan, 'ON', 7800000, 1274700, [
            ['time_off_policy_id' => $sickPolicy->id, 'opening_balance' => 16],
        ]));
        app(SaveEmployeePayrollProfile::class)->handle($payrollProfile($camille, 'QC', 6500000, 1819000));

        // One posted pay run covering both employees, with cheques written, so the
        // pay-run, PD7A, and Revenu Québec report screenshots have data.
        $payRun = app(SavePayRun::class)->handle([
            'payroll_schedule_id' => $schedule->id,
            'period_start_date' => now()->subDays(13)->toDateString(),
            'period_end_date' => now()->toDateString(),
            'pay_date' => now()->toDateString(),
            'bank_account_id' => $bank->id,
            'lines' => [
                ['contact_id' => $jordan->id, 'hours_worked' => null],
                ['contact_id' => $camille->id, 'hours_worked' => null],
            ],
        ]);
        app(CalculatePayRun::class)->calculate($payRun);
        app(PayRunPoster::class)->post($payRun->fresh());
        app(IssuePayrollCheques::class)->handle($payRun->fresh(), 5001, $bank->id);

        // ───────────────────────── Bank statement import (review states) ───────
        // The import flow is interactive, so rather than drive a file upload we
        // seed one import paused at each step the docs screenshot: a CSV waiting
        // for column mapping (Savings) and a parsed-and-matched OFX ready for
        // review (Chequing). Neither is committed, so opening Banking → Import
        // statement for that account resumes the matching step. The seeded chart
        // already has a Credit Card account with nothing in flight, which is what
        // the upload-step screenshot uses.
        $expense = $accounts->firstWhere('subtype', AccountSubtype::Expense);

        $mappingImport = new BankStatementImport;
        $mappingImport->forceFill([
            'company_id' => $company->id,
            'account_id' => $savings->id,
            'source_format' => BankStatementFormat::fromExtension('csv')->value,
            'original_filename' => 'savings-export.csv',
            'status' => BankStatementImportStatus::NeedsMapping->value,
            'parse_meta' => [
                'headers' => ['Date', 'Description', 'Withdrawals', 'Deposits', 'Balance'],
                'header_signature' => 'demo-savings-csv',
            ],
            'created_by_user_id' => $user->id,
        ])->save();

        $reviewImport = new BankStatementImport;
        $reviewImport->forceFill([
            'company_id' => $company->id,
            'account_id' => $bank->id,
            'source_format' => BankStatementFormat::fromExtension('ofx')->value,
            'original_filename' => 'chequing-statement.ofx',
            'status' => BankStatementImportStatus::Ready->value,
            'parse_meta' => ['headers' => []],
            'line_count' => 5,
            'matched_count' => 2,
            'created_count' => 1,
            'duplicate_count' => 1,
            'created_by_user_id' => $user->id,
        ])->save();

        $importLine = function (string $date, string $desc, int $cents, StatementLineMatchStatus $status, ?string $reason = null, ?int $suggested = null) use ($company, $reviewImport): void {
            $line = new BankStatementLine;
            $line->forceFill([
                'company_id' => $company->id,
                'bank_statement_import_id' => $reviewImport->id,
                'account_id' => $reviewImport->account_id,
                'txn_date' => $date,
                'description' => $desc,
                'amount_cents' => $cents,
                'fingerprint' => md5($reviewImport->id.$date.$cents.$desc),
                'match_status' => $status->value,
                'match_reason' => $reason,
                'suggested_account_id' => $suggested,
            ])->save();
        };

        $importLine(now()->subDays(5)->toDateString(), 'DEPOSIT - NORTHWIND TRADERS', 60000, StatementLineMatchStatus::Matched, __('Matches a receipt on the same day'));
        $importLine(now()->subDays(4)->toDateString(), 'CHEQUE 1001 - OFFICE SUPPLY CO', -24000, StatementLineMatchStatus::Matched, __('Matches a bill payment'));
        $importLine(now()->subDays(8)->toDateString(), 'TRANSFER TO SAVINGS', -100000, StatementLineMatchStatus::Duplicate, __('Already recorded as a transfer'));
        $importLine(now()->subDays(3)->toDateString(), 'MONTHLY BANK FEE', -3200, StatementLineMatchStatus::Created, null, $expense?->id);
        $importLine(now()->subDays(2)->toDateString(), 'INTERAC E-TRANSFER - RIVERSIDE', 36000, StatementLineMatchStatus::Suggested, __('Likely matches an open invoice'));

        $this->command?->info("Demo company ready at slug 'demo' (login test@example.com / password).");

        // A second company — a Canadian registered charity — so the docs can
        // screenshot the non-profit surfaces (Members, Fundraising, Funds) that
        // the for-profit demo company above doesn't exercise.
        $this->buildCommunitySociety($user);
    }

    /**
     * Build "Demo Community Society": a Canadian registered charity using the
     * restricted-fund method, with members, donations, grants, and official
     * donation receipts. Same idempotency pattern as the demo company above —
     * any prior society is archived (slug renamed + soft-deleted) so its data
     * stays scoped to its old id and a clean one is rebuilt under 'demo-society'.
     */
    private function buildCommunitySociety(User $user): void
    {
        Company::withTrashed()->where('slug', 'demo-society')->get()->each(function (Company $old): void {
            $old->forceFill(['slug' => 'demo-society-archived-'.$old->id])->saveQuietly();
            if (! $old->trashed()) {
                $old->delete();
            }
        });

        // Build the non-profit chart the setup wizard would seed for a registered
        // charity (Net Assets, restricted/endowment classes, donation + grant +
        // membership-dues income, deferred-grant liability) and pass it through as
        // the pending chart so CreateCompany seeds exactly that instead of the bare
        // jurisdiction core. Selecting every row matches "keep the whole chart".
        $chartRows = app(ChartTemplateBuilder::class)->build(
            jurisdiction: Country::Canada,
            industry: Industry::NonProfit,
            orgType: OrganizationType::Charity,
        );
        $pendingChart = ChartTemplateBuilder::toSeedRows($chartRows, array_column($chartRows, 'code'));

        $company = app(CreateCompany::class)->handle(
            user: $user,
            name: 'Demo Community Society',
            country: Country::Canada,
            regionCode: 'ON',
            attributes: [
                'slug' => 'demo-society',
                'industry' => Industry::NonProfit->value,
                'organization_type' => OrganizationType::Charity->value,
                'legal_structure' => LegalStructure::RegisteredCharity->value,
                // A plausible CRA business-number / charity registration (BN RR).
                'charity_registration_number' => '123456789RR0001',
                // True fund accounting — required for the Funds dimension and for
                // donations/grants to carry a fund tag on the GL.
                'contribution_method' => ContributionMethod::RestrictedFund->value,
                'setup_completed_at' => now(),
            ],
            pendingChartAccounts: $pendingChart,
        );

        // Rebind the seeding context to the charity (the for-profit demo company
        // was bound above). Posting reads Auth::id(); scoped models and the
        // feature/method gates read the bound current_company.
        Auth::login($user);
        app()->instance('current_company', $company);

        // Turn on the non-profit modules the docs screenshot. (Funds also needs
        // the restricted-fund method, set above.)
        $company->update([
            'features_membership' => true,
            'features_fundraising' => true,
            'features_funds' => true,
        ]);

        $accounts = $company->accounts()->get();
        $byCode = fn (string $code): ?Account => $accounts->firstWhere('code', $code);

        $chequing = $byCode('1000') ?? $accounts->firstWhere('subtype', AccountSubtype::Bank);
        $donationIncome = $byCode('4000');   // Donations & Contributions
        $grantIncome = $byCode('4100');      // Grant Revenue
        $duesIncome = $byCode('4200');       // Membership Dues
        $deferred = $byCode('2500');         // Deferred / Restricted Grants
        $inKindAsset = $byCode('1500');      // Office Equipment (receives in-kind gift)

        $exemptTax = TaxCode::query()
            ->where('company_id', $company->id)
            ->where('code', 'EX')
            ->where('is_active', true)
            ->first();

        // ───────────────────────── Funds (restricted fund method) ──────────────
        $generalFund = app(SaveFund::class)->handle([
            'name' => 'General Fund',
            'fund_type' => FundType::General->value,
        ]);
        $buildingFund = app(SaveFund::class)->handle([
            'name' => 'Building Fund',
            'fund_type' => FundType::Restricted->value,
        ]);
        app(SaveFund::class)->handle([
            'name' => 'Endowment',
            'fund_type' => FundType::Endowment->value,
        ]);

        // ───────────────────────── Membership levels ───────────────────────────
        $individual = app(SaveMembershipLevel::class)->handle([
            'name' => 'Individual',
            'default_dues_cents' => 5000,
            'billing_frequency' => RecurrenceFrequency::Annual->value,
            'revenue_account_id' => $duesIncome?->id,
            'default_tax_code_id' => $exemptTax?->id,
        ]);
        $family = app(SaveMembershipLevel::class)->handle([
            'name' => 'Family',
            'default_dues_cents' => 9000,
            'billing_frequency' => RecurrenceFrequency::Annual->value,
            'revenue_account_id' => $duesIncome?->id,
            'default_tax_code_id' => $exemptTax?->id,
        ]);

        // ───────────────────────── Members (mix of levels) ─────────────────────
        // Inline contacts; SaveMember flags each as a member + customer. One
        // member runs auto-renew, which spins up a recurring dues schedule.
        $memberSpecs = [
            ['Priya Sharma', 'priya@society.example', $individual, true],
            ['Marcus Lee', 'marcus@society.example', $individual, false],
            ['The Okafor Family', 'okafor@society.example', $family, true],
            ['Diane Cote', 'diane@society.example', $family, false],
        ];

        foreach ($memberSpecs as [$name, $email, $level, $autoRenew]) {
            $contact = Contact::factory()->create([
                'company_id' => $company->id,
                'display_name' => $name,
                'email' => $email,
            ]);

            app(SaveMember::class)->handle([
                'contact_id' => $contact->id,
                'membership_level_id' => $level->id,
                'joined_on' => now()->subMonths(8)->toDateString(),
                'started_on' => now()->subMonths(2)->toDateString(),
                'expires_on' => now()->addMonths(10)->toDateString(),
                'dues_cents' => $level->default_dues_cents,
                'auto_renew' => $autoRenew,
                'is_active' => true,
            ]);
        }

        // ───────────────────────── Donations (cash, in-kind, pledge) ───────────
        // Donors are created inline. Cash + in-kind are posted to the GL; the
        // pledge is left as a draft (promised, not yet received).
        $cashDonor = Contact::factory()->create([
            'company_id' => $company->id,
            'display_name' => 'Helen Wright',
            'email' => 'helen@donor.example',
            'billing_line1' => '14 Maple Street',
            'billing_city' => 'Ottawa',
            'billing_region' => 'ON',
            'billing_postal_code' => 'K1A 0B1',
            'billing_country' => Country::Canada->value,
        ]);
        $inKindDonor = Contact::factory()->create([
            'company_id' => $company->id,
            'display_name' => 'Maplewood Furnishings',
            'company_name' => 'Maplewood Furnishings Ltd.',
            'email' => 'giving@maplewood.example',
        ]);
        $pledgeDonor = Contact::factory()->create([
            'company_id' => $company->id,
            'display_name' => 'Theodore Banks',
            'email' => 'theo@donor.example',
        ]);

        // Cash donation, restricted to the Building Fund.
        $cashDonation = app(SaveDonation::class)->handle([
            'contact_id' => $cashDonor->id,
            'gift_type' => GiftType::Cash->value,
            'donation_date' => now()->subDays(21)->toDateString(),
            'amount_cents' => 25000,
            'is_restricted' => true,
            'fund_id' => $buildingFund->id,
            'restriction_note' => 'For the new accessibility ramp.',
            'deposit_to_account_id' => $chequing?->id,
            'revenue_account_id' => $donationIncome?->id,
        ]);
        app(DonationPoster::class)->post($cashDonation);

        // In-kind gift: donated office furniture, booked to the asset account at
        // fair market value, unrestricted (General Fund).
        $inKindDonation = app(SaveDonation::class)->handle([
            'contact_id' => $inKindDonor->id,
            'gift_type' => GiftType::InKind->value,
            'donation_date' => now()->subDays(12)->toDateString(),
            'amount_cents' => 80000,
            'is_restricted' => false,
            'fund_id' => $generalFund->id,
            'deposit_to_account_id' => $inKindAsset?->id ?? $chequing?->id,
            'revenue_account_id' => $donationIncome?->id,
            'notes' => 'Six donated office desks (fair market value).',
        ]);
        app(DonationPoster::class)->post($inKindDonation);

        // Pledge: promised but not yet received — left as a draft donation.
        app(SaveDonation::class)->handle([
            'contact_id' => $pledgeDonor->id,
            'gift_type' => GiftType::Cash->value,
            'donation_date' => now()->subDays(3)->toDateString(),
            'amount_cents' => 50000,
            'is_restricted' => false,
            'fund_id' => $generalFund->id,
            'deposit_to_account_id' => $chequing?->id,
            'revenue_account_id' => $donationIncome?->id,
            'notes' => 'Pledged at the spring gala; payment to follow.',
        ]);

        // ───────────────────────── Grant (restricted, with period) ─────────────
        $funder = Contact::factory()->create([
            'company_id' => $company->id,
            'display_name' => 'Ontario Trillium Foundation',
            'company_name' => 'Ontario Trillium Foundation',
            'email' => 'grants@otf.example',
        ]);

        $grant = app(SaveGrant::class)->handle([
            'funder_contact_id' => $funder->id,
            'name' => 'Community Programs Grant 2026',
            'award_amount_cents' => 1500000,
            'is_restricted' => true,
            'fund_id' => $buildingFund->id,
            'period_start' => now()->startOfYear()->toDateString(),
            'period_end' => now()->endOfYear()->toDateString(),
            'deposit_to_account_id' => $chequing?->id,
            'revenue_account_id' => $grantIncome?->id,
            'deferred_account_id' => $deferred?->id,
            'notes' => 'Restricted to youth outreach programming.',
        ]);
        app(GrantPoster::class)->postAward($grant);

        // ───────────────────────── Donation receipts (issued + draft) ──────────
        // One official CRA receipt issued (cash — record-only, posts no GL), and
        // one left as a draft. Available because this company is a registered
        // charity.
        $issuedReceipt = app(SaveDonationReceipt::class)->handle([
            'contact_id' => $cashDonor->id,
            'gift_type' => GiftType::Cash->value,
            'gift_date' => now()->subDays(21)->toDateString(),
            'amount_cents' => 25000,
            'advantage_cents' => 0,
            'revenue_account_id' => $donationIncome?->id,
        ]);
        app(DonationReceiptIssuer::class)->issue($issuedReceipt);

        app(SaveDonationReceipt::class)->handle([
            'contact_id' => $pledgeDonor->id,
            'gift_type' => GiftType::Cash->value,
            'gift_date' => now()->subDays(3)->toDateString(),
            'amount_cents' => 50000,
            'advantage_cents' => 0,
            'revenue_account_id' => $donationIncome?->id,
        ]);

        $this->command?->info("Demo charity ready at slug 'demo-society' (same login).");
    }
}
