<?php

namespace App\Mcp\Tools;

use App\Enums\Activity;
use App\Enums\InvoiceStatus;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Mcp\DocumentLinePresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class InvoiceLinesTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Search invoice lines';

    protected string $description = 'Search the lines of many invoices at once — e.g. "which invoices billed urns this quarter?" or "what did we bill Acme for travel?". Filter by item (name or SKU), customer, line description, account (code or name), and an issue-date window (period or start/end). Returns matching lines newest first (invoice number, date, customer, description, quantity × unit price, amount, tax codes) plus totals: line and invoice counts, quantity, and amount per currency. Only posted, partially paid, and paid invoices are searched unless "status" says otherwise. For one invoice in full, use get-invoice-tool instead. Read-only; never modifies data.';

    private const DEFAULT_STATUSES = [InvoiceStatus::Posted, InvoiceStatus::Partial, InvoiceStatus::Paid];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('sales:read')) {
            return $denied;
        }

        if ($denied = $this->requirePermission(Activity::Invoices)) {
            return $denied;
        }

        $statuses = $this->statuses($request->get('status'));
        if (is_string($statuses)) {
            return Response::text($statuses);
        }

        $limit = max(1, min(200, (int) ($request->get('limit') ?? 50)));
        $window = $this->dateWindow($request);

        $base = $this->query($request, $statuses, $window);

        $totals = (clone $base)
            ->join('invoices as totals_invoice', 'totals_invoice.id', '=', 'invoice_lines.invoice_id')
            ->groupBy('totals_invoice.currency_code')
            ->selectRaw('totals_invoice.currency_code as currency, COUNT(*) as line_count, COUNT(DISTINCT invoice_lines.invoice_id) as invoice_count, SUM(invoice_lines.line_subtotal_cents) as amount_cents, SUM(invoice_lines.quantity) as quantity')
            ->toBase()
            ->get();

        $lines = (clone $base)
            ->with([...DocumentLinePresenter::RELATIONS, 'invoice.contact'])
            ->orderByDesc(Invoice::query()->select('invoice_date')->whereColumn('invoices.id', 'invoice_lines.invoice_id')->limit(1))
            ->orderByDesc('invoice_lines.invoice_id')
            ->orderBy('invoice_lines.line_order')
            ->limit($limit)
            ->get();

        if ($lines->isEmpty()) {
            return Response::text('No invoice lines matched '.$this->describeFilters($request, $statuses, $window).'.');
        }

        $out = ['Invoice lines matching '.$this->describeFilters($request, $statuses, $window).':'];

        $lineCount = (int) $totals->sum('line_count');
        $home = $this->company()->currency_code;

        foreach ($totals as $row) {
            $currency = $row->currency ?: $home;
            $out[] = sprintf(
                '%s: %d line%s on %d invoice%s, quantity %s, amount %s (before tax)',
                $currency,
                (int) $row->line_count,
                (int) $row->line_count === 1 ? '' : 's',
                (int) $row->invoice_count,
                (int) $row->invoice_count === 1 ? '' : 's',
                DocumentLinePresenter::trimDecimal($row->quantity),
                $this->moneyIn((int) $row->amount_cents, $currency),
            );
        }

        $out[] = '';

        foreach ($lines as $line) {
            /** @var InvoiceLine $line */
            $invoice = $line->invoice;
            $presenter = new DocumentLinePresenter($invoice->currency_code ?: $home);
            $row = $presenter->toArray($line, (int) $line->line_order + 1);

            $out[] = sprintf(
                '- %s (API id %d) | %s | %s | %s | %s × %s = %s | %s (%s)%s',
                $invoice->invoice_no,
                $invoice->id,
                $invoice->invoice_date?->toDateString() ?? '',
                $invoice->contact->display_name ?? 'Unknown customer',
                $row['description'],
                $row['quantity'],
                $presenter->format((int) $line->unit_price_cents),
                $presenter->format((int) $line->line_subtotal_cents),
                $row['tax_codes'] === [] ? 'no tax' : implode(', ', $row['tax_codes']),
                $row['tax_flags'],
                $row['item'] !== null && $row['item'] !== $row['description'] ? " | item: {$row['item']}" : '',
            );
        }

        if ($lineCount > $lines->count()) {
            $out[] = '';
            $out[] = sprintf('Showing the newest %d of %d lines. Narrow the filters or raise "limit" (max 200) to see more.', $lines->count(), $lineCount);
        }

        return Response::text(implode("\n", $out));
    }

    /**
     * @param  list<InvoiceStatus>  $statuses
     * @param  array{start: string, end: string, label: string}|null  $window
     * @return Builder<InvoiceLine>
     */
    private function query(Request $request, array $statuses, ?array $window): Builder
    {
        $customer = trim((string) $request->get('customer'));
        $item = trim((string) $request->get('item'));
        $description = trim((string) $request->get('description'));
        $account = trim((string) $request->get('account'));

        // The line table has no company_id: whereHas('invoice') is what puts
        // CompanyScope on this query. Keep it unconditional.
        return InvoiceLine::query()
            ->whereHas('invoice', function (Builder $q) use ($statuses, $window, $customer): void {
                $q->whereIn('status', array_map(fn (InvoiceStatus $s): string => $s->value, $statuses));

                if ($window !== null) {
                    $q->whereBetween('invoice_date', [$window['start'], $window['end']]);
                }

                if ($customer !== '') {
                    $q->whereHas('contact', fn (Builder $c) => $this->whereContains($c, 'display_name', $customer));
                }
            })
            ->when($item !== '', fn (Builder $q) => $q->whereHas('item', fn (Builder $i) => $i->where(
                fn (Builder $w) => $this->whereContains($this->whereContains($w, 'name', $item), 'sku', $item, 'or'),
            )))
            ->when($description !== '', fn (Builder $q) => $this->whereContains($q, 'invoice_lines.description', $description))
            ->when($account !== '', fn (Builder $q) => $q->whereHas('account', fn (Builder $a) => $a->where(
                fn (Builder $w) => $this->whereContains($w->where('code', $account), 'name', $account, 'or'),
            )));
    }

    /**
     * @return list<InvoiceStatus>|string
     */
    private function statuses(mixed $status): array|string
    {
        if ($status === null || $status === '') {
            return self::DEFAULT_STATUSES;
        }

        if ($status === 'all') {
            return [InvoiceStatus::Draft, InvoiceStatus::Posted, InvoiceStatus::Partial, InvoiceStatus::Paid];
        }

        $one = InvoiceStatus::tryFrom((string) $status);

        if ($one === null) {
            $valid = implode(', ', array_column(InvoiceStatus::cases(), 'value'));

            return "Unknown invoice status \"{$status}\". Valid statuses are: {$valid}, or \"all\" (every status except void).";
        }

        return [$one];
    }

    /**
     * A date window only when the caller asked for one; otherwise every date.
     *
     * @return array{start: string, end: string, label: string}|null
     */
    private function dateWindow(Request $request): ?array
    {
        if ($request->get('period') === null && $request->get('start') === null && $request->get('end') === null) {
            return null;
        }

        $period = $this->resolvePeriod($request);

        return [
            'start' => $period['start']->toDateString(),
            'end' => $period['end']->toDateString(),
            'label' => $period['label'],
        ];
    }

    /**
     * @param  list<InvoiceStatus>  $statuses
     * @param  array{start: string, end: string, label: string}|null  $window
     */
    private function describeFilters(Request $request, array $statuses, ?array $window): string
    {
        $parts = [];

        foreach (['item' => 'item', 'customer' => 'customer', 'description' => 'description', 'account' => 'account'] as $key => $label) {
            $value = trim((string) $request->get($key));
            if ($value !== '') {
                $parts[] = "{$label} \"{$value}\"";
            }
        }

        if ($window !== null) {
            $parts[] = "dated {$window['start']} to {$window['end']}";
        }

        $parts[] = 'status '.implode('/', array_map(fn (InvoiceStatus $s): string => $s->value, $statuses));

        return implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'item' => $schema->string()
                ->description('Optional item name or SKU to match (partial, case-insensitive).'),
            'customer' => $schema->string()
                ->description('Optional customer name to match (partial, case-insensitive).'),
            'description' => $schema->string()
                ->description('Optional text to find in the line description as typed on the invoice (partial, case-insensitive).'),
            'account' => $schema->string()
                ->description('Optional account: an exact account code (e.g. "4010") or part of the account name.'),
            'period' => $schema->string()
                ->description('Optional issue-date window: this_month, last_month, this_quarter, last_quarter, this_year, last_year, or ytd. Omit (with no start/end) to search every date.'),
            'start' => $schema->string()
                ->description('Optional start of the issue-date range (ISO date). Use with "end".'),
            'end' => $schema->string()
                ->description('Optional end of the issue-date range (ISO date). Use with "start".'),
            'status' => $schema->string()
                ->description('Optional invoice status: draft, posted, partial, paid, void, or "all" (every status except void). Default: posted, partial and paid.'),
            'limit' => $schema->integer()
                ->description('Maximum number of lines to list (default 50, max 200). Totals always cover every match.'),
        ];
    }
}
