<?php

namespace App\Actions\Sales;

use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Migration\Importers\OpenInvoicesImporter;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Posts a customer's opening Accounts-Receivable balance as a synthetic
 * opening-balance invoice — the same mechanism the QuickBooks open-invoices
 * importer uses ({@see OpenInvoicesImporter}).
 *
 * The single line targets Opening Balance Equity, so {@see InvoicePoster}
 * naturally posts:
 *   DR  Accounts Receivable        amount
 *   CR  Opening Balance Equity     amount
 *
 * No revenue, tax or COGS is recognised — the sale that created the balance
 * happened in the previous system. The invoice ages from $asOf so it lands in
 * the correct AR-Aging bucket and ties to the AR control account to the penny.
 * The amount is denominated in the customer's own currency; the poster locks the
 * FX rate and routes to the matching AR control account for foreign customers.
 */
final class PostCustomerOpeningBalance
{
    public function __construct(
        protected DocumentNumberGenerator $numbers,
        protected InvoicePoster $poster,
    ) {}

    public function handle(Contact $contact, int $amountCents, CarbonImmutable $asOf): Invoice
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Opening balance must be greater than zero.');
        }

        return DB::transaction(function () use ($contact, $amountCents, $asOf): Invoice {
            $company = $contact->company;

            $obe = app(OpeningBalanceAccountResolver::class)->resolveOrFail((int) $company->id);

            $invoice = Invoice::create([
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'invoice_no' => $this->numbers->next($company, Invoice::class, 'invoice_no', 'OB'),
                'invoice_date' => $asOf,
                'due_date' => $asOf,
                'status' => InvoiceStatus::Draft,
                'subtotal_cents' => $amountCents,
                'tax_cents' => 0,
                'total_cents' => $amountCents,
                'amount_paid_cents' => 0,
                'currency_code' => $contact->currency_code,
                'memo' => 'Opening balance',
                'is_opening_balance' => true,
            ]);

            $invoice->lines()->create([
                'account_id' => $obe->id,
                'description' => 'Opening balance',
                'quantity' => '1.0000',
                'unit_price_cents' => $amountCents,
                'line_subtotal_cents' => $amountCents,
                'line_tax_cents' => 0,
                'line_total_cents' => $amountCents,
                'line_order' => 0,
            ]);

            $this->poster->post($invoice);

            return $invoice->refresh();
        });
    }
}
