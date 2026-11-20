<?php

namespace App\Mcp\Tools;

use App\Enums\Activity;
use App\Enums\ReceiptStatus;
use App\Enums\StatementSection;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ReceiptApplication;
use App\Support\Mcp\ContactCardPresenter;
use App\Support\Mcp\DocumentLinePresenter;
use App\Support\Mcp\DocumentTaxSummary;
use App\Support\Mcp\StatementSections;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetInvoiceTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Get invoice';

    protected string $description = 'One invoice in full: header (number, API id, dates, status, terms, sales rep, customer and API id), the customer\'s bill-to address, every line in invoice order (description as typed on the invoice, item and API id, quantity, unit price, amount, income or liability account, tax codes, tax flags such as G/P/GP/E, and statement section services or disbursement), a per-tax-code summary (taxable base, rate, tax), totals, the payments applied, and the balance due. Look the invoice up by its number (e.g. "INV 1042") or numeric API id. Amounts are in the invoice\'s own currency. Pass format "json" for a machine-readable object. Use this — not account ledgers — to see what an invoice contains. Read-only; never modifies data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('sales:read')) {
            return $denied;
        }

        if ($denied = $this->requirePermission(Activity::Invoices)) {
            return $denied;
        }

        $needle = trim((string) $request->get('invoice'));

        if ($needle === '') {
            return Response::error('Please pass the invoice number (e.g. "INV 1042") or its numeric API id.');
        }

        $invoice = $this->findInvoice($needle);

        if (is_string($invoice)) {
            return Response::text($invoice);
        }

        $detail = $this->detail($invoice);

        return $request->get('format') === 'json'
            ? Response::json($detail)
            : Response::text($this->render($invoice, $detail));
    }

    /**
     * The exact number wins (case-insensitive); a number that matches no
     * invoice is then tried as an API id; otherwise up to five near matches.
     */
    private function findInvoice(string $needle): Invoice|string
    {
        $exact = Invoice::query()->whereRaw('LOWER(invoice_no) = ?', [mb_strtolower($needle)])->limit(6)->get();

        if ($exact->count() === 1) {
            return $exact->first();
        }

        if ($exact->count() > 1) {
            return "Several invoices are numbered \"{$needle}\". Pass the API id of the one you mean:\n".$this->listInvoices($exact);
        }

        if (ctype_digit($needle) && ($byId = Invoice::query()->find((int) $needle)) instanceof Invoice) {
            return $byId;
        }

        $near = $this->whereContains(Invoice::query(), 'invoice_no', $needle)
            ->orderByDesc('invoice_date')
            ->limit(5)
            ->get();

        if ($near->isEmpty()) {
            return "No invoice is numbered \"{$needle}\".";
        }

        return "No invoice is numbered exactly \"{$needle}\". Close matches:\n".$this->listInvoices($near);
    }

    /**
     * @param  EloquentCollection<int, Invoice>  $invoices
     */
    private function listInvoices(EloquentCollection $invoices): string
    {
        $invoices->loadMissing('contact');

        return $invoices->map(fn (Invoice $i): string => sprintf(
            '- %s (API id %d) | %s | %s | %s',
            $i->invoice_no,
            $i->id,
            $i->contact->display_name ?? 'Unknown customer',
            $i->invoice_date?->toDateString() ?? '',
            $i->status->label(),
        ))->implode("\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'contact' => fn ($q) => $q->with(ContactCardPresenter::RELATIONS),
            'terms', 'salesRep', 'salesOrder',
            'lines' => fn ($q) => $q->with(DocumentLinePresenter::RELATIONS)->orderBy('line_order')->orderBy('id'),
            'receiptApplications.receipt.paymentMethod',
        ]);

        $currency = $invoice->currency_code ?: $this->company()->currency_code;
        $lines = new DocumentLinePresenter($currency);

        $payments = $this->postedApplications($invoice)
            ->map(fn (ReceiptApplication $a): array => [
                'date' => $a->receipt?->receipt_date?->toDateString(),
                'number' => $a->receipt?->receipt_no,
                'receipt_id' => $a->customer_receipt_id,
                'method' => $a->receipt?->paymentMethod?->name,
                'reference' => $a->receipt?->reference,
                'amount' => $lines->decimal((int) $a->amount_cents),
            ])
            ->values()
            ->all();

        $foreign = $invoice->isForeignCurrency();

        return [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->invoice_no,
                'status' => $invoice->status->value,
                'date' => $invoice->invoice_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'terms' => $invoice->terms?->name,
                'sales_rep' => $invoice->salesRep?->display_name,
                'customer_po' => $invoice->customer_po,
                'sales_order' => $invoice->salesOrder?->order_no,
                'currency' => $currency,
                'fx_rate' => $foreign ? DocumentLinePresenter::trimDecimal($invoice->fx_rate) : null,
                'home_total' => $foreign ? $this->decimalHome((int) $invoice->home_total_cents) : null,
            ],
            'customer' => $invoice->contact !== null ? ContactCardPresenter::toArray($invoice->contact) : null,
            'lines' => $invoice->lines->values()->map(fn (InvoiceLine $line, int $i): array => $lines->toArray($line, $i + 1))->all(),
            'tax' => array_map(fn (array $t): array => [
                'code' => $t['code'],
                'rate' => $t['rate'],
                'base' => $lines->decimal($t['base_cents']),
                'amount' => $lines->decimal($t['tax_cents']),
            ], DocumentTaxSummary::for($invoice->lines)),
            // Tax charged as lines of its own, posted straight to a tax-payable
            // account (imported invoices do this): the line amount is the tax.
            'tax_lines' => $this->taxLines($invoice)->map(fn (InvoiceLine $line): array => [
                'description' => $lines->description($line),
                'account' => $line->account !== null ? trim("{$line->account->code} {$line->account->name}") : null,
                'amount' => $lines->decimal((int) $line->line_subtotal_cents),
            ])->values()->all(),
            'totals' => [
                'subtotal' => $lines->decimal((int) $invoice->subtotal_cents),
                'discount' => (int) $invoice->document_discount_cents !== 0 ? $lines->decimal((int) $invoice->document_discount_cents) : null,
                'tax' => $lines->decimal((int) $invoice->tax_cents),
                'total' => $lines->decimal((int) $invoice->total_cents),
                'paid' => $lines->decimal((int) $invoice->amount_paid_cents),
                'reconciled' => (int) $invoice->reconciled_cents !== 0 ? $lines->decimal((int) $invoice->reconciled_cents) : null,
                'balance_due' => $lines->decimal($invoice->balanceCents()),
            ],
            'payments' => $payments,
            'memo' => $invoice->memo,
            'customer_message' => $invoice->customer_message,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function render(Invoice $invoice, array $detail): string
    {
        $currency = $detail['invoice']['currency'];
        $money = fn (int $cents): string => $this->moneyIn($cents, $currency);
        $presenter = new DocumentLinePresenter($currency);

        $out = ["Invoice {$invoice->invoice_no} (API id {$invoice->id}) — {$invoice->status->label()}"];

        $header = array_filter([
            'Date '.($detail['invoice']['date'] ?? '—'),
            'Due '.($detail['invoice']['due_date'] ?? '—'),
            $detail['invoice']['terms'] !== null ? "Terms {$detail['invoice']['terms']}" : null,
            'Rep: '.($detail['invoice']['sales_rep'] ?? 'no rep'),
            $detail['invoice']['customer_po'] ? "Customer PO {$detail['invoice']['customer_po']}" : null,
            $detail['invoice']['sales_order'] ? "Sales order {$detail['invoice']['sales_order']}" : null,
        ]);
        $out[] = implode(' · ', $header);

        if ($detail['invoice']['fx_rate'] !== null) {
            $out[] = "Currency {$currency} at {$detail['invoice']['fx_rate']}; home-currency total ".$this->money((int) $invoice->home_total_cents).'.';
        }

        $out[] = '';
        $out[] = $invoice->contact !== null
            ? ContactCardPresenter::toText($invoice->contact, 'Customer (bill to — the customer\'s current address)')
            : 'Customer: unknown';

        $out[] = '';
        $out[] = 'Lines:';
        foreach ($invoice->lines->values() as $i => $line) {
            $out[] = $presenter->toText($line, $i + 1);
        }

        if ($detail['tax'] !== []) {
            $out[] = '';
            $out[] = 'Tax by code:';
            foreach (DocumentTaxSummary::for($invoice->lines) as $tax) {
                $out[] = "- {$tax['code']} {$tax['rate']}%: on ".$money($tax['base_cents']).' → '.$money($tax['tax_cents']);
            }
        }

        $taxLines = $this->taxLines($invoice);
        if ($taxLines->isNotEmpty()) {
            $out[] = '';
            $out[] = 'Tax charged as separate lines (posted to tax-payable accounts; already in the subtotal below):';
            foreach ($taxLines as $line) {
                $out[] = '- '.$presenter->description($line).': '.$money((int) $line->line_subtotal_cents);
            }
            if ($detail['tax'] === []) {
                $out[] = 'No line carries a tax code, so per-line tax flags (G/P) cannot be derived for this invoice.';
            }
        }

        $out[] = '';
        $out[] = 'Subtotal: '.$money((int) $invoice->subtotal_cents);
        if ((int) $invoice->document_discount_cents !== 0) {
            $out[] = 'Discount: -'.$money((int) $invoice->document_discount_cents);
        }
        $out[] = 'Tax: '.$money((int) $invoice->tax_cents);
        $out[] = 'Total: '.$money((int) $invoice->total_cents);

        $out[] = '';
        if ($detail['payments'] === []) {
            $out[] = 'Payments applied: none';
        } else {
            $out[] = 'Payments applied:';
            foreach ($this->postedApplications($invoice) as $application) {
                $receipt = $application->receipt;
                $method = $receipt->paymentMethod->name ?? 'no method';
                $reference = $receipt->reference ? " · ref {$receipt->reference}" : '';
                $out[] = "- {$receipt->receipt_date?->toDateString()} {$receipt->receipt_no} (API id {$receipt->id}) · {$method}{$reference}: ".$money((int) $application->amount_cents);
            }
        }
        if ((int) $invoice->reconciled_cents !== 0) {
            $out[] = 'Settled by journal entry: '.$money((int) $invoice->reconciled_cents);
        }
        $out[] = 'Balance due: '.$money($invoice->balanceCents());

        foreach (['memo' => 'Memo', 'customer_message' => 'Message to customer'] as $key => $label) {
            if (filled($detail[$key])) {
                $out[] = "{$label}: {$detail[$key]}";
            }
        }

        return implode("\n", $out);
    }

    /**
     * Lines posted to a tax-payable account: tax charged as a line of its own.
     *
     * @return EloquentCollection<int, InvoiceLine>
     */
    private function taxLines(Invoice $invoice): EloquentCollection
    {
        return $invoice->lines->filter(fn (InvoiceLine $line): bool => StatementSections::for($line) === StatementSection::Tax)->values();
    }

    /**
     * Applications of posted receipts only (a draft or voided receipt pays
     * nothing), oldest first.
     *
     * @return Collection<int, ReceiptApplication>
     */
    private function postedApplications(Invoice $invoice): Collection
    {
        return $invoice->receiptApplications
            ->filter(fn (ReceiptApplication $a): bool => $a->receipt?->status === ReceiptStatus::Posted)
            ->sortBy(fn (ReceiptApplication $a): string => ($a->receipt?->receipt_date?->toDateString() ?? '').'#'.str_pad((string) $a->id, 12, '0', STR_PAD_LEFT))
            ->values();
    }

    private function decimalHome(int $cents): string
    {
        return (new DocumentLinePresenter($this->company()->currency_code))->decimal($cents);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice' => $schema->string()
                ->description('The invoice number exactly as shown (e.g. "INV 1042"), or its numeric API id. Required.'),
            'format' => $schema->string()
                ->description('Optional output format: "text" (default) or "json".'),
        ];
    }
}
