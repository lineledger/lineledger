<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Support\Currency;
use Carbon\CarbonImmutable;

/**
 * The customer-facing statement in its two flavours: the open invoices owed as
 * of a date, or the account activity over a period. Both carry the aging strip.
 *
 * Every query filters company_id explicitly — statements also render inside
 * queued email notifications, where `current_company` is unbound and
 * CompanyScope is inert.
 *
 * Amounts are home-currency cents; foreign invoices convert at their locked
 * rate, matching the aging builder so the strip and the rows agree.
 */
class CustomerStatementBuilder
{
    public function __construct(
        protected ContactStatementBuilder $statements,
        protected OpenDocumentAgingBuilder $aging,
    ) {}

    /**
     * Open (unpaid) invoices as of $asOf. Rows always sum to `total_due`, the
     * contact's GL balance from the aging row: when open invoices alone don't
     * reach it, one adjustment row absorbs the difference — credits on account
     * (credit memos, unapplied receipts) when negative, other AR activity
     * (e.g. journal entries) when positive.
     *
     * With a $start, only invoices dated $start–$asOf are listed; the open
     * balance of those dated before $start leads as one "Balance forward" row,
     * so the total still ties to the aging total. A null $start has no lower
     * bound.
     *
     * @return array{
     *   rows: array<int, array{kind: 'invoice'|'forward'|'adjustment', invoice_no: string, memo: string, customer_po: string, terms: string, invoice_date: ?string, due_date: ?string, total: int, paid: int, balance: int, days_past_due: int, label: ?string}>,
     *   aging: array{current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int},
     *   total_due: int,
     *   start: ?string,
     *   as_of: string,
     * }
     */
    public function openInvoices(Company $company, Contact $contact, CarbonImmutable $asOf, ?CarbonImmutable $start = null): array
    {
        $asOfDate = $asOf->toDateString();
        $startDate = $start?->toDateString();

        $forward = 0;
        $rows = [];

        $invoices = Invoice::query()
            ->where('company_id', $company->id)
            ->where('contact_id', $contact->id)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->where('invoice_date', '<=', $asOfDate)
            ->whereRaw('total_cents - amount_paid_cents - reconciled_cents > 0')
            ->with(['terms' => fn ($query) => $query->where('company_id', $company->id)])
            ->orderBy('invoice_date')
            ->orderBy('invoice_no')
            ->get();

        foreach ($invoices as $invoice) {
            $total = (int) $invoice->total_cents;
            $balance = $invoice->balanceCents();

            if ($invoice->currency_code !== null && ! $company->isHomeCurrency($invoice->currency_code) && $invoice->fx_rate !== null) {
                $total = Currency::toHomeCents($total, (string) $invoice->fx_rate);
                $balance = Currency::toHomeCents($balance, (string) $invoice->fx_rate);
            }

            $invoiceDate = $invoice->invoice_date?->toDateString();

            if ($startDate !== null && $invoiceDate !== null && $invoiceDate < $startDate) {
                $forward += $balance;

                continue;
            }

            $rows[] = [
                'kind' => 'invoice',
                'invoice_no' => (string) $invoice->invoice_no,
                'memo' => trim((string) $invoice->memo),
                'customer_po' => trim((string) $invoice->customer_po),
                'terms' => (string) $invoice->terms?->name,
                'invoice_date' => $invoiceDate,
                'due_date' => $invoice->due_date?->toDateString(),
                'total' => $total,
                'paid' => $total - $balance,
                'balance' => $balance,
                'days_past_due' => $this->daysPastDue($invoice->due_date?->toDateString(), $asOf),
                'label' => null,
            ];
        }

        if ($forward !== 0) {
            array_unshift($rows, $this->summaryRow('forward', __('Balance forward'), $forward));
        }

        $aging = $this->aging->summaryRowForContact($company, 'ar', $asOf, $contact);
        $totalDue = $aging['total'];

        $difference = $totalDue - array_sum(array_column($rows, 'balance'));

        if ($difference !== 0) {
            $rows[] = $this->summaryRow('adjustment', $difference < 0 ? __('Credits on account') : __('Other balance'), $difference);
        }

        return [
            'rows' => $rows,
            'aging' => $aging,
            'total_due' => $totalDue,
            'start' => $startDate,
            'as_of' => $asOfDate,
        ];
    }

    /**
     * The account activity over [$start, $end] — the GL-backed running-balance
     * statement — plus the aging strip as of $end.
     *
     * @return array{
     *   statement: array{opening: int, lines: array<int, array<string, mixed>>, period_debit: int, period_credit: int, closing: int},
     *   aging: array{current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int},
     *   start: string,
     *   end: string,
     * }
     */
    public function activity(Company $company, Contact $contact, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'statement' => $this->statements->build($company, $contact, AccountSubtype::AccountsReceivable, $start, $end),
            'aging' => $this->aging->summaryRowForContact($company, 'ar', $end, $contact),
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
    }

    /**
     * Whole days an invoice is overdue as of $asOf — 0 when not yet due (or
     * undated). Counted like the aging detail, so the two agree.
     */
    private function daysPastDue(?string $dueDate, CarbonImmutable $asOf): int
    {
        if ($dueDate === null) {
            return 0;
        }

        $days = -$asOf->startOfDay()->diffInDays(CarbonImmutable::parse($dueDate), false);

        return $days > 0 ? (int) ceil($days) : 0;
    }

    /**
     * A label row (balance forward or adjustment) shaped like an invoice row.
     *
     * @param  'forward'|'adjustment'  $kind
     * @return array{kind: 'forward'|'adjustment', invoice_no: string, memo: string, customer_po: string, terms: string, invoice_date: null, due_date: null, total: int, paid: int, balance: int, days_past_due: int, label: string}
     */
    private function summaryRow(string $kind, string $label, int $balance): array
    {
        return [
            'kind' => $kind,
            'invoice_no' => '',
            'memo' => '',
            'customer_po' => '',
            'terms' => '',
            'invoice_date' => null,
            'due_date' => null,
            'total' => $balance,
            'paid' => 0,
            'balance' => $balance,
            'days_past_due' => 0,
            'label' => $label,
        ];
    }
}
