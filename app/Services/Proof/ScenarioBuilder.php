<?php

namespace App\Services\Proof;

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Companies\CreateCompany;
use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SaveBillPayment;
use App\Actions\Sales\SaveInvoice;
use App\Actions\Sales\SaveReceipt;
use App\Enums\AccountSubtype;
use App\Enums\Country;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DataMigrationRun;
use App\Models\User;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\GeneralLedgerReplayImporter;
use App\Services\Migration\Importers\TrialBalanceImporter;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Builds the deterministic data behind the public verification page. Every figure
 * is index-driven — no Faker, no `now()` — so the same books, and therefore the
 * same reports, come out in every environment and can be re-derived by anyone who
 * downloads the source bundle.
 *
 * Data is created through the real Save-then-Poster pipeline (the same code the UI and
 * API use) so the immutable accounting audit log is populated naturally and the
 * proof exercises production posting, not a test shortcut.
 */
class ScenarioBuilder
{
    /** Calendar fiscal year, so the year-ends the user asked about are Dec 31. */
    private const FISCAL_YEAR_START_MONTH = 1;

    /** Test 1 spans these three fiscal years. */
    public const YEARS = [2023, 2024, 2025];

    /** Default transaction volume per fiscal year for the published artifacts. */
    public const DEFAULT_PER_YEAR = 500;

    /** Opening capital that funds the bank account before trading begins. */
    private const OPENING_CAPITAL_CENTS = 10_000_000;

    /**
     * Test 1 — three fiscal years of trading, ~$perYear posted transactions each,
     * verified at every Dec 31 year-end.
     */
    public function buildThreeYearScenario(int $perYear = self::DEFAULT_PER_YEAR): ProofScenario
    {
        $company = $this->newCompany('test1', 'Proof Test Co.', 'ON');

        $bank = $this->account($company, AccountSubtype::Bank);
        $revenue = $this->account($company, AccountSubtype::Income);
        $expense = $this->account($company, AccountSubtype::Expense);
        $equity = $this->equityAccount($company);

        $customers = $this->makeContacts($company, 'Customer', 4, customer: true);
        $vendors = $this->makeContacts($company, 'Vendor', 3, customer: false);

        // Seed the bank with owner capital on the first day so cash is real.
        $this->postJournal(
            CarbonImmutable::create(self::YEARS[0], 1, 1),
            'Opening owner capital',
            [
                ['account_id' => $bank->id, 'debit_cents' => self::OPENING_CAPITAL_CENTS, 'credit_cents' => 0],
                ['account_id' => $equity->id, 'debit_cents' => 0, 'credit_cents' => self::OPENING_CAPITAL_CENTS],
            ],
        );

        /** @var list<array{id: int, date: CarbonImmutable, total: int}> $openInvoices */
        $openInvoices = [];
        /** @var list<array{id: int, date: CarbonImmutable, total: int}> $openBills */
        $openBills = [];

        foreach (self::YEARS as $year) {
            $yearStart = CarbonImmutable::create($year, 1, 1);

            for ($i = 0; $i < $perYear; $i++) {
                // Offset by 1 so the first transaction lands on Jan 2, never the
                // fiscal-year-start day. A P&L entry dated exactly on the FY start
                // is excluded from the income-statement period under SQLite (where a
                // date-only column is not >= the bound's 'Y-m-d 00:00:00' string),
                // which would leave the trial balance out by that entry's amount.
                $date = $yearStart->addDays(($i * 7) % 360 + 1);
                $type = $i % 10;

                if ($type <= 3) {
                    // 40% — post a sales invoice (recognises revenue).
                    $amount = 50_000 + ($i % 9) * 2_500;
                    $invoice = app(SaveInvoice::class)->handle([
                        'contact_id' => $customers[$i % count($customers)]->id,
                        'invoice_date' => $date->toDateString(),
                        'lines' => [[
                            'account_id' => $revenue->id,
                            'description' => 'Services rendered',
                            'quantity' => 1,
                            'unit_price_cents' => $amount,
                        ]],
                    ]);
                    app(InvoicePoster::class)->post($invoice);
                    $openInvoices[] = ['id' => $invoice->id, 'date' => $date, 'total' => (int) $invoice->fresh()->total_cents];
                } elseif ($type <= 5) {
                    // 20% — post a vendor bill (recognises expense).
                    $amount = 20_000 + ($i % 7) * 1_500;
                    $bill = app(SaveBill::class)->handle([
                        'contact_id' => $vendors[$i % count($vendors)]->id,
                        'bill_date' => $date->toDateString(),
                        'lines' => [[
                            'account_id' => $expense->id,
                            'description' => 'Operating supplies',
                            'quantity' => 1,
                            'unit_price_cents' => $amount,
                        ]],
                    ]);
                    app(BillPoster::class)->post($bill);
                    $openBills[] = ['id' => $bill->id, 'date' => $date, 'total' => (int) $bill->fresh()->total_cents];
                } elseif ($type <= 7 && $openInvoices !== []) {
                    // 20% — collect the oldest open invoice in full.
                    $open = array_shift($openInvoices);
                    $payDate = $date->greaterThan($open['date']) ? $date : $open['date']->addDays(10);
                    $receipt = app(SaveReceipt::class)->handle([
                        'contact_id' => $customers[$i % count($customers)]->id,
                        'receipt_date' => $payDate->toDateString(),
                        'deposit_to_account_id' => $bank->id,
                        'amount_cents' => $open['total'],
                        'applications' => [['invoice_id' => $open['id'], 'amount_cents' => $open['total']]],
                    ]);
                    app(ReceiptPoster::class)->post($receipt);
                } elseif ($type === 8 && $openBills !== []) {
                    // 10% — pay the oldest open bill in full.
                    $open = array_shift($openBills);
                    $payDate = $date->greaterThan($open['date']) ? $date : $open['date']->addDays(10);
                    $payment = app(SaveBillPayment::class)->handle([
                        'contact_id' => $vendors[$i % count($vendors)]->id,
                        'payment_date' => $payDate->toDateString(),
                        'paid_from_account_id' => $bank->id,
                        'amount_cents' => $open['total'],
                        'applications' => [['bill_id' => $open['id'], 'amount_cents' => $open['total']]],
                    ]);
                    app(BillPaymentPoster::class)->post($payment);
                } else {
                    // Remainder — a manual depreciation journal entry.
                    $this->postJournal($date, 'Monthly depreciation', [
                        ['account_id' => $expense->id, 'debit_cents' => 7_500, 'credit_cents' => 0],
                        ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 7_500],
                    ]);
                }
            }
        }

        $checkpoints = array_map(fn (int $year) => [
            'label' => "Fiscal year ending {$year}-12-31",
            'as_of' => CarbonImmutable::create($year, 12, 31),
        ], self::YEARS);

        return new ProofScenario(
            key: 'test-1',
            title: '3-Year Closing Trial Balance',
            company: $company,
            checkpoints: $checkpoints,
        );
    }

    /**
     * Test 2 — a brand-new company seeded the setup-wizard way, then brought live
     * with an imported opening trial balance. Verified at the conversion date.
     */
    public function buildImportedTrialBalanceScenario(): ProofScenario
    {
        $company = $this->newCompany('test2', 'Proof Import Co.', 'ON');
        $conversionDate = CarbonImmutable::create(2025, 6, 30);

        $bank = $this->account($company, AccountSubtype::Bank);
        $revenue = $this->account($company, AccountSubtype::Income);
        $expense = $this->account($company, AccountSubtype::Expense);

        // An opening trial balance the importer will post on the conversion date,
        // plugging the difference to Opening Balance Equity. Amounts are exact.
        $rows = [
            ['code' => $bank->code, 'debit' => 5_000_000, 'credit' => 0],
            ['code' => $revenue->code, 'debit' => 0, 'credit' => 3_000_000],
            ['code' => $expense->code, 'debit' => 1_200_000, 'credit' => 0],
        ];

        $run = DataMigrationRun::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => DataMigrationStatus::InProgress,
            'conversion_date' => $conversionDate,
            'current_step' => 1,
            'step_results' => [],
            'started_at' => CarbonImmutable::create(2025, 7, 1),
        ]);

        $csvPath = $this->writeImportCsv($rows);

        $ctx = new ImportContext(
            company: $company,
            run: $run,
            conversionDate: $conversionDate,
        );

        $result = app(TrialBalanceImporter::class)->commit($csvPath, $ctx);
        @unlink($csvPath);

        if ($result->errors !== []) {
            $messages = implode('; ', array_map(fn ($e) => $e['message'], $result->errors));
            throw new \RuntimeException("Opening trial balance import failed: {$messages}");
        }

        return new ProofScenario(
            key: 'test-2',
            title: 'Imported Opening Trial Balance',
            company: $company,
            checkpoints: [[
                'label' => "Conversion date {$conversionDate->toDateString()}",
                'as_of' => $conversionDate,
            ]],
            importedRows: $rows,
        );
    }

    /** QuickBooks separates an account number from its name with a middot. */
    private const QB_SEP = " \u{00B7} ";

    /**
     * The QuickBooks accounts used by the mock journals (code => [name, QB type]).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const QB_ACCOUNTS = [
        '102' => ['Bank of Montreal', 'Bank'],
        '200' => ['Customer Receivables', 'Accounts Receivable'],
        '516' => ['GST Collected', 'Other Current Liability'],
        '680' => ['Opening Bal Equity', 'Equity'],
        '703' => ['Funeral Services Revenue', 'Income'],
        '869' => ['Salary - Funeral Operations', 'Expense'],
        '841' => ['Repairs & Maintenance', 'Expense'],
    ];

    /**
     * Test 3 — replay a mocked QuickBooks Desktop "Journal" export (2023, 2024,
     * 2025), in the same CSV format as a real QBD export, through the live
     * full-history importer. Every replayed account ties back to the source totals.
     */
    public function buildQuickBooksJournalScenario(int $perYear = 200): ProofScenario
    {
        $company = $this->newCompany('test3', 'Proof QuickBooks Co.', 'ON');

        /** @var array<string, int> $expected  account code => net (debit − credit) cents */
        $expected = [];
        $transactions = 0;
        $sources = [];

        $journalPaths = [];
        foreach (self::YEARS as $year) {
            [$csv, $count] = $this->buildQuickBooksJournalCsv($year, $perYear, $expected);
            $transactions += $count;
            $sources["quickbooks-journal-{$year}.csv"] = $csv;

            $path = tempnam(sys_get_temp_dir(), "qbj-{$year}-").'.csv';
            file_put_contents($path, $csv);
            $journalPaths[] = $path;
        }

        $chartCsv = $this->buildQuickBooksChartCsv();
        $sources['quickbooks-chart-of-accounts.csv'] = $chartCsv;
        $chartPath = tempnam(sys_get_temp_dir(), 'qbc-').'.csv';
        file_put_contents($chartPath, $chartCsv);

        $run = DataMigrationRun::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'status' => DataMigrationStatus::InProgress,
            'mode' => DataMigrationMode::FullHistory,
            'conversion_date' => CarbonImmutable::create(2025, 12, 31),
            'current_step' => 6,
            'step_results' => [],
            'started_at' => CarbonImmutable::create(2026, 1, 1),
        ]);

        $ctx = new ImportContext(
            company: $company,
            run: $run,
            conversionDate: CarbonImmutable::create(2025, 12, 31),
            sourceFormat: 'csv',
            autoCreateAccounts: true,
            linkContactNames: false,
            reconstructDocuments: false,
            accountTypesPath: $chartPath,
        );

        $result = app(GeneralLedgerReplayImporter::class)->commit($journalPaths, $ctx);

        foreach ([...$journalPaths, $chartPath] as $tmp) {
            @unlink($tmp);
        }

        if ($result->errors !== []) {
            $messages = implode('; ', array_map(fn ($e) => $e['message'], array_slice($result->errors, 0, 5)));
            throw new \RuntimeException("QuickBooks journal replay failed: {$messages}");
        }

        $checkpoints = array_map(fn (int $year) => [
            'label' => "Fiscal year ending {$year}-12-31",
            'as_of' => CarbonImmutable::create($year, 12, 31),
        ], self::YEARS);

        return new ProofScenario(
            key: 'test-3',
            title: 'QuickBooks Journal Import (3-Year Replay)',
            company: $company,
            checkpoints: $checkpoints,
            tieOut: [
                'label' => 'QuickBooks source tie-out (as of 2025-12-31)',
                'as_of' => CarbonImmutable::create(2025, 12, 31),
                'accounts' => $expected,
                'transactions' => $transactions,
            ],
            extraSourceFiles: $sources,
        );
    }

    /**
     * Build one year of mock QuickBooks "Journal" CSV. Accumulates the expected
     * per-account net into $expected and returns [csv, transactionCount].
     *
     * @param  array<string, int>  $expected
     * @return array{0: string, 1: int}
     */
    private function buildQuickBooksJournalCsv(int $year, int $perYear, array &$expected): array
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Trans #', 'Type', 'Date', 'Num', 'Name', 'Memo', 'Account', 'Debit', 'Credit']);

        $count = 0;
        $write = function (string $transNo, string $type, string $date, string $num, string $name, array $lines) use ($handle, &$expected, &$count): void {
            foreach (array_values($lines) as $idx => [$code, $debit, $credit]) {
                fputcsv($handle, [
                    $idx === 0 ? $transNo : '',
                    $idx === 0 ? $type : '',
                    $idx === 0 ? $date : '',
                    $idx === 0 ? $num : '',
                    $name,
                    '',
                    $code.self::QB_SEP.self::QB_ACCOUNTS[$code][0],
                    $debit > 0 ? number_format($debit / 100, 2, '.', '') : '',
                    $credit > 0 ? number_format($credit / 100, 2, '.', '') : '',
                ]);
                $expected[$code] = ($expected[$code] ?? 0) + $debit - $credit;
            }
            $count++;
        };

        // The first year opens the bank from Opening Balance Equity.
        if ($year === self::YEARS[0]) {
            $write((string) ($year * 1_000_000), 'Deposit', "{$year}-01-01", '', 'Opening balance', [
                ['102', 5_000_000, 0],
                ['680', 0, 5_000_000],
            ]);
        }

        for ($i = 0; $i < $perYear; $i++) {
            $transNo = (string) ($year * 1_000_000 + $i + 1);
            // Day 2–28 only: never the fiscal-year-start day (Jan 1). A P&L entry
            // dated on the FY start is dropped from the income-statement period
            // under SQLite's date-string comparison, unbalancing the trial balance.
            $date = sprintf('%04d-%02d-%02d', $year, ($i % 12) + 1, ($i % 27) + 2);
            $type = $i % 5;

            if ($type <= 1) {
                // Invoice: AR debited; revenue + GST credited.
                $revenue = 50_000 + ($i % 9) * 2_500;
                $gst = (int) round($revenue * 0.05);
                $write($transNo, 'Invoice', $date, "INV-{$transNo}", 'Funeral Client', [
                    ['200', $revenue + $gst, 0],
                    ['703', 0, $revenue],
                    ['516', 0, $gst],
                ]);
            } elseif ($type === 2) {
                // Payment: bank debited, receivable credited.
                $amount = 50_000 + ($i % 7) * 2_000;
                $write($transNo, 'Payment', $date, '', 'Funeral Client', [
                    ['102', $amount, 0],
                    ['200', 0, $amount],
                ]);
            } elseif ($type === 3) {
                // Cheque: payroll.
                $amount = 60_000 + ($i % 6) * 3_000;
                $write($transNo, 'Cheque', $date, "CHQ-{$transNo}", 'Receiver General', [
                    ['102', 0, $amount],
                    ['869', $amount, 0],
                ]);
            } else {
                // Cheque: repairs.
                $amount = 15_000 + ($i % 5) * 1_000;
                $write($transNo, 'Cheque', $date, "CHQ-{$transNo}", 'Mike Muise Landscaping', [
                    ['102', 0, $amount],
                    ['841', $amount, 0],
                ]);
            }
        }

        rewind($handle);

        return [(string) stream_get_contents($handle), $count];
    }

    /**
     * Build the QuickBooks "Account Listing" CSV used to type the auto-created accounts.
     */
    private function buildQuickBooksChartCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['', 'Active Status', 'Account', 'Type', 'Balance Total', 'Description', 'Accnt. #', 'Tax Line']);
        foreach (self::QB_ACCOUNTS as $code => [$name, $qbType]) {
            fputcsv($handle, ['', 'Active', $name, $qbType, '', $name, $code, '<Unassigned>']);
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * Create a fresh user + company with the full standard chart of accounts and
     * bind the posting context (auth + current company) the pipeline expects.
     */
    private function newCompany(string $key, string $name, string $region): Company
    {
        $user = User::factory()->create([
            'email' => "proof+{$key}@lineledger.test",
            'name' => 'Proof Runner',
        ]);

        // Log in BEFORE creating the company: company creation fires account.created
        // audit rows whose actor_user_id is read from Auth::user(). Logging in first
        // attributes them to this scenario's user instead of whatever (possibly
        // rolled-back) user a previous scenario left authenticated.
        Auth::login($user);

        $company = app(CreateCompany::class)->handle(
            user: $user,
            name: $name,
            country: Country::Canada,
            regionCode: $region,
            attributes: ['fiscal_year_start_month' => self::FISCAL_YEAR_START_MONTH],
        );

        app()->instance('current_company', $company);

        return $company;
    }

    private function account(Company $company, AccountSubtype $subtype): Account
    {
        return Account::query()
            ->where('company_id', $company->id)
            ->where('subtype', $subtype->value)
            ->orderBy('code')
            ->firstOrFail();
    }

    /**
     * The general equity account used for owner capital — never the special-purpose
     * Opening Balance Equity or Retained Earnings.
     */
    private function equityAccount(Company $company): Account
    {
        return Account::query()
            ->where('company_id', $company->id)
            ->where('subtype', AccountSubtype::Equity->value)
            ->whereNotIn('name', Account::OPENING_BALANCE_NAMES)
            ->orderBy('code')
            ->firstOrFail();
    }

    /**
     * @return list<Contact>
     */
    private function makeContacts(Company $company, string $prefix, int $count, bool $customer): array
    {
        $contacts = [];
        for ($n = 1; $n <= $count; $n++) {
            $factory = Contact::factory();
            $factory = $customer ? $factory->customer() : $factory->vendor();
            $contacts[] = $factory->create([
                'company_id' => $company->id,
                'display_name' => "{$prefix} {$n}",
            ]);
        }

        return $contacts;
    }

    /**
     * @param  list<array{account_id: int, debit_cents: int, credit_cents: int}>  $lines
     */
    private function postJournal(CarbonImmutable $date, string $memo, array $lines): void
    {
        $entry = app(SaveJournalEntry::class)->handle([
            'entry_date' => $date->toDateString(),
            'memo' => $memo,
            'lines' => $lines,
        ]);
        app(JournalPoster::class)->post($entry);
    }

    /**
     * @param  list<array{code: string, debit: int, credit: int}>  $rows
     */
    private function writeImportCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'proof-tb').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ['account_code', 'debit', 'credit']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['code'],
                $row['debit'] > 0 ? number_format($row['debit'] / 100, 2, '.', '') : '',
                $row['credit'] > 0 ? number_format($row['credit'] / 100, 2, '.', '') : '',
            ]);
        }
        fclose($handle);

        return $path;
    }
}
