<?php

namespace Database\Seeders;

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Companies\CreateCompany;
use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SaveBillPayment;
use App\Actions\Purchasing\SaveExpense;
use App\Actions\Purchasing\SaveVendorCredit;
use App\Actions\Sales\SaveCreditMemo;
use App\Actions\Sales\SaveInvoice;
use App\Actions\Sales\SaveReceipt;
use App\Actions\Tax\SaveTaxReturn;
use App\Enums\Country;
use App\Enums\Industry;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Item;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use App\Services\Posting\VendorCreditPoster;
use App\Services\Tax\TaxReturnFiler;
use App\Support\Legal\LegalDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Two UK demo organizations for trying the United Kingdom jurisdiction by hand:
 *
 *   "Thames Valley Trading Ltd" (slug uk-demo)  — England, VAT-registered,
 *       two VAT quarters of trading (the first a repayment, the second with
 *       VAT to pay): standard, reduced, zero-rated and exempt
 *       sales; a euro export; a credit memo; bills, expenses (including an
 *       out-of-scope line), a vendor credit, receipts and a bill payment. The
 *       earlier quarter is filed as a tax return (its nine boxes frozen); the
 *       last complete quarter is left as a draft to review and file.
 *
 *   "Laxey Craft Brewery Ltd" (slug iom-demo)   — Isle of Man, VAT run by
 *       Isle of Man Customs and Excise, with a few sales and purchases.
 *
 * Both belong to test@example.com / password (created if missing, and made a
 * site admin like the other demo seeder). Standalone and re-runnable:
 *
 *   php artisan db:seed --class=UkDemoCompanySeeder
 *
 * Re-running archives the previous demo companies and builds fresh ones. Dates
 * are relative to today, so the VAT Return report always opens on a quarter
 * with figures in it.
 */
class UkDemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'UkDemoCompanySeeder must not run in production — it seeds a known-credential site admin.'
            );
        }

        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );
        $user->forceFill(['site_admin' => true])->save();
        app(LegalDocuments::class)->record($user, ['terms', 'privacy']);

        $this->archive(['uk-demo', 'iom-demo']);

        $thames = $this->seedThamesValley($user);
        $this->seedLaxey($user);

        // Land the demo user on the English company.
        $user->switchCompany($thames);

        Auth::logout();
        app()->forgetInstance('current_company');

        $this->command?->info('UK demo ready — sign in as test@example.com / password.');
        $this->command?->info('  Thames Valley Trading Ltd: /'.$thames->slug.'/reports/vat-return');
    }

    /**
     * Free the demo slugs by renaming and soft-deleting any earlier build, the
     * same way DemoCompanySeeder does (immutable audit logs make a hard delete
     * brittle).
     *
     * @param  list<string>  $slugs
     */
    protected function archive(array $slugs): void
    {
        Company::withTrashed()->whereIn('slug', $slugs)->get()->each(function (Company $old): void {
            $old->forceFill(['slug' => $old->slug.'-archived-'.$old->id])->saveQuietly();

            if (! $old->trashed()) {
                $old->delete();
            }
        });
    }

    protected function seedThamesValley(User $user): Company
    {
        $company = app(CreateCompany::class)->handle(
            user: $user,
            name: 'Thames Valley Trading Ltd',
            country: Country::UnitedKingdom,
            regionCode: 'ENG',
            attributes: [
                'slug' => 'uk-demo',
                'legal_name' => 'Thames Valley Trading Limited',
                'organization_type' => OrganizationType::Corporation->value,
                'industry' => Industry::General->value,
                'company_registration_number' => '09876543',
                'tax_number' => 'GB123456789',
                'address_line1' => '14 Market Place',
                'address_city' => 'Reading',
                'address_postal_code' => 'RG1 2EQ',
                'phone' => '0118 496 0123',
                'email' => 'accounts@thamesvalley.example',
            ],
        );

        $this->bind($user, $company);
        $this->showTaxNumberOnForms($company);

        $agency = TaxAgency::query()->firstOrFail();
        $agency->update(['registration_number' => 'GB123456789']);

        [$standard, $reduced, $zero, $exempt] = $this->codes();
        $sales = $this->account('4000');
        $services = $this->account('4100');
        $bank = $this->account('1000');

        // Quarters, relative to today: Q-2 gets filed, Q-1 is the one to file now.
        $current = CarbonImmutable::now($company->timezone)->startOfQuarter();
        $q1 = $current->subQuarters(2);   // filed
        $q2 = $current->subQuarter();     // draft — the VAT Return report's default
        $on = fn (CarbonImmutable $quarter, int $day): string => $quarter->addDays($day)->toDateString();

        // Opening capital.
        $capital = app(SaveJournalEntry::class)->handle([
            'entry_date' => $q1->subDay()->toDateString(),
            'memo' => 'Share capital and director’s loan',
            'lines' => [
                ['account_id' => $bank->id, 'debit_cents' => 2500000, 'credit_cents' => 0],
                ['account_id' => $this->account('3100')->id, 'debit_cents' => 0, 'credit_cents' => 2500000],
            ],
        ]);
        app(JournalPoster::class)->post($capital);

        // ── Customers & suppliers ────────────────────────────────────────────
        $oxford = $this->customer('Oxford Bookshop Ltd', 'orders@oxfordbooks.example', '22 Broad Street', 'Oxford', 'OX1 3AS');
        $kensington = $this->customer('Kensington Kids Boutique', 'buyer@kenkids.example', '8 Church Street', 'London', 'W8 4LL');
        $harbour = $this->customer('Harbour Lights Hotel', 'finance@harbourlights.example', '1 Esplanade', 'Weymouth', 'DT4 8DN');
        $edinburgh = $this->customer('Edinburgh Design Studio', 'hello@edstudio.example', '5 Rose Street', 'Edinburgh', 'EH2 2PR', 'SCT');
        $berlin = Contact::factory()->customer()->create([
            'company_id' => $company->id,
            'display_name' => 'Buchhandlung Berlin GmbH',
            'email' => 'einkauf@buchberlin.example',
            'billing_line1' => 'Friedrichstraße 100',
            'billing_city' => 'Berlin',
            'billing_postal_code' => '10117',
            'billing_country' => 'DE',
            'currency_code' => 'EUR',
        ]);

        $printer = $this->vendor('Midlands Print Works', 'B1 1AA', 'Birmingham');
        $courier = $this->vendor('Swift Couriers UK', 'M1 1AE', 'Manchester');
        $landlord = $this->vendor('Reading Business Centre', 'RG1 1AF', 'Reading');
        $energy = $this->vendor('Green Energy Supply Ltd', 'BS1 4DJ', 'Bristol');

        // ── Items, one per VAT treatment ─────────────────────────────────────
        $books = $this->item('Printed books (zero-rated)', 1499, $zero, $sales);
        $kidsWear = $this->item('Children’s clothing (zero-rated)', 1850, $zero, $sales);
        $consulting = $this->item('Design consultancy (standard rated)', 9500, $standard, $services);
        $carSeat = $this->item('Child car seat (reduced rate)', 12000, $reduced, $sales);
        $training = $this->item('Accredited training course (exempt)', 45000, $exempt, $services);

        // ── Quarter 1 (to be filed) ──────────────────────────────────────────
        $inv1 = $this->invoice($oxford, $on($q1, 5), [[$books, 120], [$consulting, 6]]);
        $inv2 = $this->invoice($kensington, $on($q1, 18), [[$kidsWear, 60], [$carSeat, 4]]);
        $this->invoice($harbour, $on($q1, 33), [[$consulting, 14]]);
        $this->invoice($edinburgh, $on($q1, 47), [[$training, 2], [$consulting, 3]]);

        $this->receipt($oxford, $inv1, $on($q1, 30), $bank);
        $this->receipt($kensington, $inv2, $on($q1, 50), $bank);

        $bill1 = $this->bill($printer, $on($q1, 9), 'Catalogue printing', 180000, $standard);
        $this->bill($landlord, $on($q1, 1), 'Office rent — quarter', 300000, $standard, '6060');
        $this->bill($energy, $on($q1, 40), 'Electricity (business, standard)', 42000, $standard, '6100');
        $this->payBill($printer, $bill1, $on($q1, 38), $bank);

        $this->expense($on($q1, 21), 'Swift Couriers UK', [
            [$this->account('6090'), 'Courier deliveries', 8500, $standard],
            [$this->account('6020'), 'Business insurance (exempt)', 64000, $exempt],
        ], $bank);

        // ── Quarter 2 (draft — review and file) ──────────────────────────────
        $inv5 = $this->invoice($oxford, $on($q2, 4), [[$books, 200]]);
        $this->invoice($harbour, $on($q2, 12), [[$consulting, 20]], documentDiscountCents: 15000);
        $this->invoice($kensington, $on($q2, 26), [[$kidsWear, 45], [$carSeat, 6], [$consulting, 2]]);
        $this->receipt($oxford, $inv5, $on($q2, 35), $bank);

        // A large standard-rated consultancy job, so this quarter ends with VAT
        // to pay (the earlier, book-heavy quarter is a repayment).
        $this->invoice($edinburgh, $on($q2, 58), [[$consulting, 80]]);

        // A euro export: zero-rated, converted at 0.86.
        app(EnableCompanyCurrency::class)->handle($company, 'EUR');
        $company->refresh();
        $export = app(SaveInvoice::class)->handle([
            'contact_id' => $berlin->id,
            'invoice_date' => $on($q2, 40),
            'currency_code' => 'EUR',
            'lines' => [$this->line($books, 400, $zero, 1750)],
        ]);
        $export->forceFill(['fx_rate' => '0.86'])->save();
        app(InvoicePoster::class)->post($export);

        // A credit memo for damaged stock on a standard-rated job.
        $memo = app(SaveCreditMemo::class)->handle([
            'contact_id' => $harbour->id,
            'credit_memo_date' => $on($q2, 45),
            'memo' => 'Two consultancy hours written off',
            'lines' => [$this->line($consulting, 2, $standard)],
        ]);
        app(CreditMemoPoster::class)->post($memo);

        $this->bill($landlord, $on($q2, 1), 'Office rent — quarter', 300000, $standard, '6060');
        $this->bill($printer, $on($q2, 15), 'Book printing run', 260000, $standard);
        $this->bill($courier, $on($q2, 30), 'Pallet deliveries', 36000, $standard, '6090');

        $credit = app(SaveVendorCredit::class)->handle([
            'contact_id' => $printer->id,
            'vendor_credit_date' => $on($q2, 33),
            'memo' => 'Misprinted covers',
            'lines' => [[
                'account_id' => $this->account('6040')->id,
                'description' => 'Credit for misprinted covers',
                'quantity' => 1,
                'unit_price_cents' => 20000,
                'tax_code_id' => $standard->id,
            ]],
        ]);
        app(VendorCreditPoster::class)->post($credit);

        $this->expense($on($q2, 52), 'Trainline', [
            [$this->account('6090'), 'Rail fares (zero-rated)', 18640, $zero],
            [$this->account('6030'), 'Client lunch (standard)', 7200, $standard],
            [$this->account('6010'), 'Bank charges (outside the scope of VAT)', 3500, null],
        ], $bank);

        // ── Current quarter: a little activity plus a draft invoice ──────────
        $this->invoice($edinburgh, $on($current, 3), [[$consulting, 5]]);
        app(SaveInvoice::class)->handle([
            'contact_id' => $oxford->id,
            'invoice_date' => CarbonImmutable::now($company->timezone)->toDateString(),
            'lines' => [$this->line($books, 80, $zero)],
        ]);

        // ── Tax returns: file the earlier quarter, leave the last one in draft ─
        $filed = app(SaveTaxReturn::class)->handle([
            'tax_agency_id' => $agency->id,
            'period_start' => $q1->toDateString(),
            'period_end' => $q1->endOfQuarter()->toDateString(),
            'filing_reference' => 'HMRC-'.$q1->format('Y').'-Q'.$q1->quarter,
            'notes' => 'Filed through HMRC online services.',
        ]);
        app(TaxReturnFiler::class)->file($filed);

        app(SaveTaxReturn::class)->handle([
            'tax_agency_id' => $agency->id,
            'period_start' => $q2->toDateString(),
            'period_end' => $q2->endOfQuarter()->toDateString(),
            'notes' => 'Draft — review the nine boxes, then file.',
        ]);

        return $company;
    }

    protected function seedLaxey(User $user): Company
    {
        $company = app(CreateCompany::class)->handle(
            user: $user,
            name: 'Laxey Craft Brewery Ltd',
            country: Country::UnitedKingdom,
            regionCode: 'IM',
            attributes: [
                'slug' => 'iom-demo',
                'legal_name' => 'Laxey Craft Brewery Limited',
                'organization_type' => OrganizationType::Corporation->value,
                'industry' => Industry::General->value,
                'company_registration_number' => '012345C',
                'tax_number' => 'GB003456789',
                'address_line1' => 'Glen Road',
                'address_city' => 'Laxey',
                'address_postal_code' => 'IM4 7AA',
                'timezone' => 'Europe/Isle_of_Man',
            ],
        );

        $this->bind($user, $company);
        $this->showTaxNumberOnForms($company);

        TaxAgency::query()->firstOrFail()->update(['registration_number' => 'GB003456789']);

        [$standard] = $this->codes();
        $sales = $this->account('4000');
        $bank = $this->account('1000');
        $quarter = CarbonImmutable::now($company->timezone)->startOfQuarter()->subQuarter();

        $ale = $this->item('Cask ale — firkin', 9800, $standard, $sales);
        $pub = $this->customer('The Mines Tavern', 'landlord@minestavern.example', 'Captain’s Hill', 'Laxey', 'IM4 7AJ', 'IM');
        $hops = $this->vendor('Ramsey Malt & Hops', 'IM8 1AA', 'Ramsey');

        $invoice = $this->invoice($pub, $quarter->addDays(10)->toDateString(), [[$ale, 12]]);
        $this->receipt($pub, $invoice, $quarter->addDays(40)->toDateString(), $bank);
        $this->bill($hops, $quarter->addDays(5)->toDateString(), 'Malt and hops', 54000, $standard, '5000');

        return $company;
    }

    protected function bind(User $user, Company $company): void
    {
        Auth::login($user);
        app()->instance('current_company', $company);
    }

    protected function showTaxNumberOnForms(Company $company): void
    {
        InvoiceSetting::query()->updateOrCreate(
            ['company_id' => $company->id],
            [...InvoiceSetting::defaults(), 'company_id' => $company->id, 'show_tax_number' => true],
        );
    }

    /**
     * @return array{0: TaxCode, 1: TaxCode, 2: TaxCode, 3: TaxCode}
     */
    protected function codes(): array
    {
        $codes = TaxCode::query()->get()->keyBy('code');

        return [$codes['VAT'], $codes['VAT-R'], $codes['ZR'], $codes['EX']];
    }

    protected function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    protected function customer(string $name, string $email, string $line1, string $city, string $postcode, string $region = 'ENG'): Contact
    {
        return Contact::factory()->customer()->emailOptedIn()->create([
            'company_id' => app('current_company')->id,
            'display_name' => $name,
            'company_name' => $name,
            'email' => $email,
            'billing_line1' => $line1,
            'billing_city' => $city,
            'billing_region' => $region,
            'billing_postal_code' => $postcode,
            'billing_country' => 'GB',
        ]);
    }

    protected function vendor(string $name, string $postcode, string $city): Contact
    {
        return Contact::factory()->vendor()->create([
            'company_id' => app('current_company')->id,
            'display_name' => $name,
            'company_name' => $name,
            'billing_city' => $city,
            'billing_postal_code' => $postcode,
            'billing_country' => 'GB',
        ]);
    }

    protected function item(string $name, int $priceCents, TaxCode $code, Account $income): Item
    {
        return Item::factory()->create([
            'company_id' => app('current_company')->id,
            'name' => $name,
            'description' => $name,
            'default_price_cents' => $priceCents,
            'default_tax_code_id' => $code->id,
            'income_account_id' => $income->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(Item $item, int $quantity, ?TaxCode $code = null, ?int $priceCents = null): array
    {
        return [
            'item_id' => $item->id,
            'account_id' => $item->income_account_id,
            'description' => $item->description,
            'quantity' => $quantity,
            'unit_price_cents' => $priceCents ?? (int) $item->default_price_cents,
            'tax_code_id' => $code?->id ?? $item->default_tax_code_id,
        ];
    }

    /**
     * @param  list<array{0: Item, 1: int}>  $lines  [item, quantity]
     */
    protected function invoice(Contact $customer, string $date, array $lines, int $documentDiscountCents = 0): Invoice
    {
        $invoice = app(SaveInvoice::class)->handle([
            'contact_id' => $customer->id,
            'invoice_date' => $date,
            'document_discount_cents' => $documentDiscountCents,
            'lines' => array_map(fn (array $line) => $this->line($line[0], $line[1]), $lines),
        ]);

        app(InvoicePoster::class)->post($invoice);

        return $invoice->fresh();
    }

    protected function receipt(Contact $customer, Invoice $invoice, string $date, Account $bank): void
    {
        $amount = (int) $invoice->fresh()->total_cents;

        $receipt = app(SaveReceipt::class)->handle([
            'contact_id' => $customer->id,
            'receipt_date' => $date,
            'deposit_to_account_id' => $bank->id,
            'amount_cents' => $amount,
            'applications' => [['invoice_id' => $invoice->id, 'amount_cents' => $amount]],
        ]);

        app(ReceiptPoster::class)->post($receipt);
    }

    protected function bill(Contact $vendor, string $date, string $description, int $netCents, TaxCode $code, string $accountCode = '6040'): Bill
    {
        $bill = app(SaveBill::class)->handle([
            'contact_id' => $vendor->id,
            'bill_date' => $date,
            'lines' => [[
                'account_id' => $this->account($accountCode)->id,
                'description' => $description,
                'quantity' => 1,
                'unit_price_cents' => $netCents,
                'tax_code_id' => $code->id,
            ]],
        ]);

        app(BillPoster::class)->post($bill);

        return $bill->fresh();
    }

    protected function payBill(Contact $vendor, Bill $bill, string $date, Account $bank): void
    {
        $amount = (int) $bill->fresh()->total_cents;

        $payment = app(SaveBillPayment::class)->handle([
            'contact_id' => $vendor->id,
            'payment_date' => $date,
            'paid_from_account_id' => $bank->id,
            'amount_cents' => $amount,
            'applications' => [['bill_id' => $bill->id, 'amount_cents' => $amount]],
        ]);

        app(BillPaymentPoster::class)->post($payment);
    }

    /**
     * @param  list<array{0: Account, 1: string, 2: int, 3: ?TaxCode}>  $lines  [account, description, net, code]
     */
    protected function expense(string $date, string $payee, array $lines, Account $bank): void
    {
        $expense = app(SaveExpense::class)->handle([
            'payment_account_id' => $bank->id,
            'expense_date' => $date,
            'payee_name' => $payee,
            'lines' => array_map(fn (array $line) => [
                'account_id' => $line[0]->id,
                'description' => $line[1],
                'amount_cents' => $line[2],
                'tax_code_id' => $line[3]?->id,
                'tax_override_cents' => null,
            ], $lines),
        ]);

        app(ExpensePoster::class)->post($expense);
    }
}
