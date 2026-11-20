<?php

namespace Tests\Support\Mcp;

use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\Country;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\TaxCode;
use App\Services\Posting\InvoicePoster;

/**
 * Two Statement of Account invoices shaped like a BC funeral home's, built through
 * SaveInvoice + InvoicePoster so tax and the general ledger are computed by
 * the app itself, not typed in. Services post to income; pass-through
 * disbursements post to a clearing liability, as the house does.
 *
 * INV 90/001 — services 5,070.00 (GST; the casket also PST), disbursements
 * 4,397.00 (exempt): GST 253.50, PST 118.65, total 9,839.15.
 *
 * INV 90/002 — 11 lines, 10,175.00 before tax: GST on 10,100.00 = 505.00,
 * PST on 1,900.00 = 133.00, total 10,813.00.
 */
final class StatementOfAccountFixture
{
    public Company $company;

    public TaxCode $gst;

    public TaxCode $pst;

    public TaxCode $exempt;

    public Account $income;

    public Account $disbursements;

    public function __construct()
    {
        $this->company = Company::factory()->forCountry(Country::Canada, 'BC')->create();
        app()->instance('current_company', $this->company);

        $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
        $this->pst = TaxCode::query()->where('code', 'PST-BC')->firstOrFail();
        $this->exempt = TaxCode::query()->where('code', 'EX')->firstOrFail();
        $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
        $this->disbursements = Account::create([
            'code' => 'ZZ-2400',
            'name' => 'Client Disbursements',
            'type' => AccountType::Liability,
            'subtype' => AccountSubtype::CurrentLiability,
            'normal_balance' => NormalBalance::Credit,
        ]);
    }

    public function customer(string $name, array $attributes = []): Contact
    {
        return Contact::factory()->create([
            'company_id' => $this->company->id,
            'is_customer' => true,
            'display_name' => $name,
            ...$attributes,
        ]);
    }

    /**
     * A repatriation file: 13 lines, four of them disbursements.
     */
    public function repatriationInvoice(?Contact $customer = null): Invoice
    {
        $customer ??= $this->customer('Sample, Jordan - 90/001', [
            'billing_line1' => '100 Sample Road',
            'billing_city' => 'Kelowna',
            'billing_region' => 'BC',
            'billing_postal_code' => 'V1Y 0A1',
            'email' => 'family@example.com',
        ]);

        $casket = Item::create(['name' => 'Metal Transfer Casket', 'sku' => 'CSK-MT', 'type' => 'non_inventory', 'default_price_cents' => 169500]);

        $g = [$this->gst->id];
        $gp = [$this->gst->id, $this->pst->id];
        $e = [$this->exempt->id];

        return $this->post($customer, 'INV 90/001', '2026-10-02', [
            ['Professional & Staff Services - Ship Out', 1, 45200, $g],
            ['Completing Documents', 1, 13300, $g],
            ['Transfer Services', 1, 19500, $g],
            ['Facilities - General', 1, 21500, $g],
            ['Automotive - Transfer to Airport', 1, 29500, $g],
            ['Preparation (Embalming) and Dressing', 1, 69500, $g],
            ['Visitation', 1, 79500, $g],
            ['Casket: Metal Transfer Casket', 1, 169500, $gp, null, $casket->id],
            ['Staffing - Additional', 1, 59500, $g],
            ['Consumer Protection BC Fee', 1, 4800, $e, $this->disbursements],
            ['Death Certificate', 2, 2700, $e, $this->disbursements],
            ['Rental Casket', 1, 129500, $e, $this->disbursements],
            ['Airfare - Family Travel', 1, 300000, [], $this->disbursements],
        ]);
    }

    /**
     * A full-service file: 11 lines, three of them disbursements.
     */
    public function fullServiceInvoice(?Contact $customer = null): Invoice
    {
        $customer ??= $this->customer('Example, Robin - 90/002');

        $g = [$this->gst->id];
        $gp = [$this->gst->id, $this->pst->id];
        $e = [$this->exempt->id];

        return $this->post($customer, 'INV 90/002', '2026-09-08', [
            ['Funeral Services', 1, 349500, $g],
            ['Preparation', 1, 69500, $g],
            ['Staffing', 1, 59500, $g],
            ['Facilities', 1, 69500, $g],
            ['Documents', 1, 2000, $g],
            ['Casket', 1, 129500, $gp],
            ['Urn', 1, 35000, $gp],
            ['Memorial Cards', 1, 25500, $gp],
            ['Cemetery Opening and Closing', 1, 270000, $g, $this->disbursements],
            ['Consumer Protection BC Fee', 1, 4800, $e, $this->disbursements],
            ['Death Certificate', 1, 2700, $e, $this->disbursements],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3: list<int>, 4?: ?Account, 5?: ?int}>  $lines  description, qty, unit cents, tax code ids, account, item id
     */
    public function post(Contact $customer, string $number, string $date, array $lines): Invoice
    {
        $invoice = app(SaveInvoice::class)->handle([
            'contact_id' => $customer->id,
            'invoice_no' => $number,
            'invoice_date' => $date,
            'lines' => array_map(fn (array $l): array => [
                'description' => $l[0],
                'quantity' => (string) $l[1],
                'unit_price_cents' => $l[2],
                'tax_code_id' => $l[3][0] ?? null,
                'secondary_tax_code_id' => $l[3][1] ?? null,
                'account_id' => ($l[4] ?? null)?->id ?? $this->income->id,
                'item_id' => $l[5] ?? null,
            ], $lines),
        ]);

        app(InvoicePoster::class)->post($invoice);

        return $invoice->fresh();
    }
}
