<?php

namespace App\Mcp\Tools;

use App\Enums\InvoiceStatus;
use App\Enums\Section;
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

    protected string $description = 'Search and list invoices, optionally filtered by status, customer name, and issue-date range. Returns the most recent invoices first with invoice number, customer, issue date, status, and total. All figures are in the company\'s home currency. This tool is read-only and never modifies any data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('sales:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Customers)) {
            return $denied;
        }

        $limit = (int) ($request->get('limit') ?? 25);
        $limit = max(1, min(100, $limit));

        $status = $request->get('status');
        if ($status !== null && InvoiceStatus::tryFrom($status) === null) {
            $valid = implode(', ', array_column(InvoiceStatus::cases(), 'value'));

            return Response::text("Unknown invoice status \"{$status}\". Valid statuses are: {$valid}.");
        }

        $customer = $request->get('customer');
        $start = $request->get('start');
        $end = $request->get('end');

        $invoices = Invoice::query()
            ->with('contact')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($customer, fn ($q) => $q->whereHas('contact', fn ($c) => $c->where('display_name', 'like', "%{$customer}%")))
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
                '- %s | %s | %s | %s | %s',
                $invoice->invoice_no,
                $name,
                $date,
                $invoice->status->label(),
                $this->money($invoice->total_cents),
            );
        })->implode("\n");

        $heading = sprintf(
            'Found %d invoice%s (most recent first). All amounts are in the company\'s home currency.',
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
            'customer' => $schema->string()
                ->description('Optional customer name to match (partial, case-insensitive).'),
            'start' => $schema->string()
                ->description('Optional start of the issue-date range (ISO date, e.g. 2026-01-01).'),
            'end' => $schema->string()
                ->description('Optional end of the issue-date range (ISO date, e.g. 2026-12-31).'),
            'limit' => $schema->integer()
                ->description('Maximum number of invoices to return (default 25, max 100).'),
        ];
    }
}
