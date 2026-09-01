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
     * @return array{
     *   rows: array<int, array{kind: 'invoice'|'adjustment', invoice_no: string, invoice_date: ?string, due_date: ?string, total: int, balance: int, label: ?string}>,
     *   aging: array{current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int},
     *   total_due: int,
     *   as_of: string,
     * }
     */
    public function openInvoices(Company $company, Contact $contact, CarbonImmutable $asOf): array
    {
        $rows = Invoice::query()
            ->where('company_id', $company->id)
            ->where('contact_id', $contact->id)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->where('invoice_date', '<=', $asOf)
            ->whereRaw('total_cents - amount_paid_cents - reconciled_cents > 0')
            ->orderBy('invoice_date')
            ->orderBy('invoice_no')
            ->get()
            ->map(function (Invoice $invoice) use ($company): array {
                $total = (int) $invoice->total_cents;
                $balance = $invoice->balanceCents();

                if ($invoice->currency_code !== null && ! $company->isHomeCurrency($invoice->currency_code) && $invoice->fx_rate !== null) {
                    $total = Currency::toHomeCents($total, (string) $invoice->fx_rate);
                    $balance = Currency::toHomeCents($balance, (string) $invoice->fx_rate);
                }

                return [
                    'kind' => 'invoice',
                    'invoice_no' => (string) $invoice->invoice_no,
                    'invoice_date' => $invoice->invoice_date?->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'total' => $total,
                    'balance' => $balance,
                    'label' => null,
                ];
            })
            ->values()
            ->all();

        $aging = $this->aging->summaryRowForContact($company, 'ar', $asOf, $contact);
        $totalDue = $aging['total'];

        $difference = $totalDue - array_sum(array_column($rows, 'balance'));

        if ($difference !== 0) {
            $rows[] = [
                'kind' => 'adjustment',
                'invoice_no' => '',
                'invoice_date' => null,
                'due_date' => null,
                'total' => $difference,
                'balance' => $difference,
                'label' => $difference < 0 ? __('Credits on account') : __('Other balance'),
            ];
        }

        return [
            'rows' => $rows,
            'aging' => $aging,
            'total_due' => $totalDue,
            'as_of' => $asOf->toDateString(),
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
}
