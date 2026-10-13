<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a contact's account statement straight from the general ledger: every
 * journal line posted to the contact's AR/AP control account(s). This captures
 * invoices, receipts, credit memos, bills, payments AND any other movement
 * (refund cheques, GJ adjustments) so the running balance always ties to the
 * control account in the ledger rather than to summed documents.
 *
 * The signed balance is debit − credit on the control account: an AR balance
 * owed to us reads positive, an AP balance we owe reads negative.
 *
 * Shared by the staff contact-statement report and the customer payment portal.
 */
class ContactStatementBuilder
{
    /**
     * Maps a journal entry's polymorphic source type to its display label, show
     * route and the table/column carrying its human-facing document number.
     *
     * @var array<class-string, array{label: string, route: string, param: string, table: string, number: string}>
     */
    public const DOCUMENT_META = [
        Invoice::class => ['label' => 'Invoice', 'route' => 'invoices.show', 'param' => 'invoice', 'table' => 'invoices', 'number' => 'invoice_no'],
        CustomerReceipt::class => ['label' => 'Receipt', 'route' => 'receipts.show', 'param' => 'receipt', 'table' => 'customer_receipts', 'number' => 'receipt_no'],
        CreditMemo::class => ['label' => 'Credit Memo', 'route' => 'credit-memos.show', 'param' => 'credit_memo', 'table' => 'credit_memos', 'number' => 'credit_memo_no'],
        Cheque::class => ['label' => 'Cheque', 'route' => 'cheques.show', 'param' => 'cheque', 'table' => 'cheques', 'number' => 'cheque_no'],
        Deposit::class => ['label' => 'Deposit', 'route' => 'deposits.show', 'param' => 'deposit', 'table' => 'deposits', 'number' => 'deposit_no'],
        Bill::class => ['label' => 'Bill', 'route' => 'bills.show', 'param' => 'bill', 'table' => 'bills', 'number' => 'bill_no'],
        BillPayment::class => ['label' => 'Payment', 'route' => 'bill-payments.show', 'param' => 'payment', 'table' => 'bill_payments', 'number' => 'payment_no'],
    ];

    /**
     * @return array{
     *     opening: int,
     *     lines: array<int, array{date: string, doc_no: string, type: string, memo: string, sales_rep: string, debit: int, credit: int, running: int, route_name: string, route_param: string, route_value: int, customer_po: string}>,
     *     period_debit: int,
     *     period_credit: int,
     *     closing: int,
     * }
     */
    public function build(Company $company, Contact $contact, AccountSubtype $subtype, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $accountIds = Account::query()
            ->where('company_id', $company->id)
            ->where('subtype', $subtype->value)
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return $this->finalizeStatement([], 0);
        }

        // Mirror the general ledger exactly: include every posted line on the AR/AP
        // control account for this contact, including a voided document's original
        // entry and its reversal. They net to zero, so the statement ties to the GL
        // instead of dropping the original while keeping the (non-voided) reversal.
        $base = fn () => DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $accountIds)
            ->where('jl.contact_id', $contact->id);

        $opening = (int) $base()
            ->where('je.entry_date', '<', $start)
            ->sum(DB::raw('jl.debit_cents - jl.credit_cents'));

        $rows = $base()
            ->whereBetween('je.entry_date', [$start, $end])
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_no')
            ->get([
                'jl.debit_cents', 'jl.credit_cents', 'jl.memo as line_memo',
                'je.id as entry_id', 'je.entry_no', 'je.entry_date',
                'je.memo as entry_memo', 'je.source_type', 'je.source_id',
            ]);

        $numbers = $this->resolveDocumentNumbers($rows);
        $reps = $this->resolveSalesReps($rows, $company);
        $pos = $this->resolveCustomerPos($rows, $company);

        $events = [];

        foreach ($rows as $r) {
            $debit = (int) $r->debit_cents;
            $credit = (int) $r->credit_cents;

            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $date = CarbonImmutable::parse($r->entry_date);
            $meta = self::DOCUMENT_META[$r->source_type] ?? null;

            if ($meta !== null && $r->source_id !== null) {
                $events[] = [
                    'date' => $date->toDateString(),
                    'sort' => $date->timestamp,
                    'doc_no' => $numbers[$r->source_type][$r->source_id] ?? $r->entry_no,
                    'type' => $meta['label'],
                    'memo' => $r->entry_memo ?? $r->line_memo ?? '',
                    'sales_rep' => $reps[$r->source_type][$r->source_id] ?? '',
                    'customer_po' => $pos[$r->source_type][$r->source_id] ?? '',
                    'debit' => $debit,
                    'credit' => $credit,
                    'route_name' => $meta['route'],
                    'route_param' => $meta['param'],
                    'route_value' => $r->source_id,
                ];

                continue;
            }

            $events[] = [
                'date' => $date->toDateString(),
                'sort' => $date->timestamp,
                'doc_no' => $r->entry_no,
                'type' => __('Journal'),
                'memo' => $r->entry_memo ?? $r->line_memo ?? '',
                'sales_rep' => '',
                'customer_po' => '',
                'debit' => $debit,
                'credit' => $credit,
                'route_name' => 'journal.show',
                'route_param' => 'entry',
                'route_value' => $r->entry_id,
            ];
        }

        return $this->finalizeStatement($events, $opening);
    }

    /**
     * Bulk-resolve document numbers for the recognized source documents in a row
     * set, keyed as [source_type][source_id] => number.
     *
     * @param  Collection<int, object>  $rows
     * @return array<class-string, array<int, string>>
     */
    private function resolveDocumentNumbers(Collection $rows): array
    {
        $numbers = [];

        foreach ($rows->whereNotNull('source_id')->groupBy('source_type') as $type => $group) {
            $meta = self::DOCUMENT_META[$type] ?? null;

            if ($meta === null) {
                continue;
            }

            $numbers[$type] = DB::table($meta['table'])
                ->whereIn('id', $group->pluck('source_id')->unique()->all())
                ->pluck($meta['number'], 'id')
                ->all();
        }

        return $numbers;
    }

    /**
     * Bulk-resolve the sales rep credited on each source document, keyed as
     * [source_type][source_id] => rep display name.
     *
     * Only invoices and credit memos carry sales_rep_id — receipts, cheques,
     * deposits, bills and bill payments have no such column, so they resolve to
     * an empty string. Two document lookups plus one name lookup, never N+1.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array<class-string, array<int, string>>
     */
    private function resolveSalesReps(Collection $rows, Company $company): array
    {
        $tables = [Invoice::class => 'invoices', CreditMemo::class => 'credit_memos'];

        $repIds = [];
        $byType = [];

        foreach ($tables as $type => $table) {
            $ids = $rows->where('source_type', $type)->pluck('source_id')->filter()->unique()->all();

            if ($ids === []) {
                continue;
            }

            $byType[$type] = DB::table($table)
                ->whereIn('id', $ids)
                ->whereNotNull('sales_rep_id')
                ->pluck('sales_rep_id', 'id')
                ->all();

            $repIds = array_merge($repIds, array_values($byType[$type]));
        }

        if ($repIds === []) {
            return [];
        }

        $names = DB::table('contacts')
            ->where('company_id', $company->id)
            ->whereIn('id', array_unique($repIds))
            ->pluck('display_name', 'id');

        $reps = [];

        foreach ($byType as $type => $map) {
            foreach ($map as $sourceId => $repId) {
                $reps[$type][(int) $sourceId] = (string) ($names[$repId] ?? '');
            }
        }

        return $reps;
    }

    /**
     * Bulk-resolve the customer's P.O. number on each source document, keyed as
     * [source_type][source_id] => P.O. Only invoices carry customer_po among
     * the documents that post to AR, so everything else resolves to an empty
     * string. One lookup, never N+1.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array<class-string, array<int, string>>
     */
    private function resolveCustomerPos(Collection $rows, Company $company): array
    {
        $ids = $rows->where('source_type', Invoice::class)->pluck('source_id')->filter()->unique()->all();

        if ($ids === []) {
            return [];
        }

        $pos = DB::table('invoices')
            ->where('company_id', $company->id)
            ->whereIn('id', $ids)
            ->whereNotNull('customer_po')
            ->pluck('customer_po', 'id');

        $resolved = [];

        foreach ($pos as $id => $po) {
            $po = trim((string) $po);

            if ($po !== '') {
                $resolved[Invoice::class][(int) $id] = $po;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array{opening: int, lines: array<int, array<string, mixed>>, period_debit: int, period_credit: int, closing: int}
     */
    private function finalizeStatement(array $events, int $opening): array
    {
        usort($events, fn ($a, $b) => $a['sort'] <=> $b['sort'] ?: strcmp($a['doc_no'], $b['doc_no']));

        $running = $opening;
        $periodDebit = 0;
        $periodCredit = 0;
        $lines = [];

        foreach ($events as $e) {
            $running += $e['debit'] - $e['credit'];
            $periodDebit += $e['debit'];
            $periodCredit += $e['credit'];

            $lines[] = [
                'date' => $e['date'],
                'doc_no' => $e['doc_no'],
                'type' => $e['type'],
                'memo' => $e['memo'],
                'sales_rep' => $e['sales_rep'],
                'customer_po' => $e['customer_po'],
                'debit' => $e['debit'],
                'credit' => $e['credit'],
                'running' => $running,
                'route_name' => $e['route_name'],
                'route_param' => $e['route_param'],
                'route_value' => $e['route_value'],
            ];
        }

        return [
            'opening' => $opening,
            'lines' => $lines,
            'period_debit' => $periodDebit,
            'period_credit' => $periodCredit,
            'closing' => $running,
        ];
    }
}
