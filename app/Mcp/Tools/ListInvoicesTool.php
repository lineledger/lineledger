<?php

namespace App\Mcp\Tools;

use App\Enums\Activity;
use App\Enums\InvoiceStatus;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Invoice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListInvoicesTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'List invoices';

    protected string $description = 'Search and list invoices, optionally filtered by status, invoice number, customer name, sales rep, and issue-date range. Returns the most recent invoices first with invoice number, API id, customer, issue date, status, total, balance due, and the sales rep credited on the invoice (shown as "no rep" when none is set). Totals are in each invoice\'s own currency. To see an invoice\'s lines, payments and tax, use get-invoice-tool. This tool is read-only and never modifies any data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('sales:read')) {
            return $denied;
        }

        if ($denied = $this->requirePermission(Activity::Invoices)) {
            return $denied;
        }

        $limit = (int) ($request->get('limit') ?? 25);
        $limit = max(1, min(100, $limit));

        $status = $request->get('status');
        if ($status !== null && InvoiceStatus::tryFrom($status) === null) {
            $valid = implode(', ', array_column(InvoiceStatus::cases(), 'value'));

            return Response::text("Unknown invoice status \"{$status}\". Valid statuses are: {$valid}.");
        }

        $customer = trim((string) $request->get('customer'));
        $rep = trim((string) $request->get('rep'));
        $number = trim((string) $request->get('number'));
        $start = $request->get('start');
        $end = $request->get('end');

        $invoices = Invoice::query()
            ->with(['contact', 'salesRep'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($number !== '', fn ($q) => $this->whereContains($q, 'invoice_no', $number))
            ->when($customer !== '', fn ($q) => $q->whereHas('contact', fn ($c) => $this->whereContains($c, 'display_name', $customer)))
            ->when($rep !== '', fn ($q) => $q->whereHas('salesRep', fn ($c) => $this->whereContains($c, 'display_name', $rep)))
            ->when($start, fn ($q) => $q->where('invoice_date', '>=', $start))
            ->when($end, fn ($q) => $q->where('invoice_date', '<=', $end))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($invoices->isEmpty()) {
            return Response::text('No invoices matched the given criteria.');
        }

        $lines = $invoices->map(function (Invoice $invoice): string {
            $name = $invoice->contact?->display_name ?? 'Unknown customer';
            $date = $invoice->invoice_date?->toDateString() ?? '';

            return sprintf(
                '- %s (API id %d) | %s | %s | %s | total %s | balance due %s | rep: %s',
                $invoice->invoice_no,
                $invoice->id,
                $name,
                $date,
                $invoice->status->label(),
                $this->moneyIn((int) $invoice->total_cents, $invoice->currency_code),
                $this->moneyIn($invoice->balanceCents(), $invoice->currency_code),
                $invoice->salesRep?->display_name ?? 'no rep',
            );
        })->implode("\n");

        $heading = sprintf(
            'Found %d invoice%s (most recent first). Amounts are in each invoice\'s own currency.',
            $invoices->count(),
            $invoices->count() === 1 ? '' : 's',
        );

        return Response::text($heading."\n\n".$lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Optional invoice status filter. One of: draft, posted, partial, paid, void.'),
            'number' => $schema->string()
                ->description('Optional invoice number to match (partial, case-insensitive), e.g. "1042".'),
            'customer' => $schema->string()
                ->description('Optional customer name to match (partial, case-insensitive).'),
            'rep' => $schema->string()
                ->description('Optional sales rep name to match (partial, case-insensitive). The rep is an employee contact credited with the sale.'),
            'start' => $schema->string()
                ->description('Optional start of the issue-date range (ISO date, e.g. 2026-01-01).'),
            'end' => $schema->string()
                ->description('Optional end of the issue-date range (ISO date, e.g. 2026-12-31).'),
            'limit' => $schema->integer()
                ->description('Maximum number of invoices to return (default 25, max 100).'),
        ];
    }
}
