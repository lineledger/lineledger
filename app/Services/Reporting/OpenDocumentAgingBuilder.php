<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The aging math shared by the AR and AP aging reports, in two shapes:
 *
 * - summary(): per-contact bucket rows — the classic aging summary.
 * - detail(): every open document individually, grouped by bucket, plus an
 *   Adjustments section (per-contact GL reconciling rows + the unattributed
 *   catch-all).
 *
 * Both shapes preserve the reports' reconciliation guarantee: with "owing
 * only" off, the grand total equals the AR/AP control-account balance to the
 * penny. The "owing only" filter drops a NET-credit contact's rows in both
 * shapes (documents and adjustment alike), so toggling between summary and
 * detail never changes the grand total.
 *
 * Amounts are home-currency cents; foreign documents convert at their locked
 * rate so rows stay consistent with the GL reconciliation.
 */
class OpenDocumentAgingBuilder
{
    public const BUCKETS = ['current', 'b1_30', 'b31_60', 'b61_90', 'b90_plus'];

    /**
     * @return array{
     *   rows: array<int, array{contact_id: int, name: string, current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int}>,
     *   totals: array{current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int},
     * }
     */
    public function summary(Company $company, string $kind, CarbonImmutable $asOf, bool $owingOnly): array
    {
        /** @var array<int|string, array{contact_id: int, name: string, current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int}> $byContact */
        $byContact = [];

        foreach ($this->openDocumentRows($company, $kind, $asOf) as $doc) {
            $id = $doc['contact_id'];

            $byContact[$id] ??= [
                'contact_id' => $id,
                'name' => $doc['name'],
                'current' => 0,
                'b1_30' => 0,
                'b31_60' => 0,
                'b61_90' => 0,
                'b90_plus' => 0,
                'total' => 0,
            ];

            $byContact[$id][$doc['bucket']] += $doc['balance'];
            $byContact[$id]['total'] += $doc['balance'];
        }

        // Reconcile each contact to the GL: fold (GL balance − open-document
        // total) into their row so the total ALWAYS equals their ledger /
        // statement balance — payments, journal entries, write-offs and
        // credits all included. The reconciling amount has no due date, so it
        // lands in Current.
        $openDocTotals = [];
        foreach ($byContact as $id => $row) {
            $openDocTotals[$id] = $row['total'];
        }

        foreach ($this->glReconcilingByContact($company, $kind, $asOf, $openDocTotals) as $contactId => $adjustment) {
            if (isset($byContact[$contactId])) {
                $byContact[$contactId]['current'] += $adjustment['amount'];
                $byContact[$contactId]['total'] += $adjustment['amount'];

                continue;
            }

            // Ledger activity but no open document — give them their own row.
            $byContact[$contactId] = [
                'contact_id' => $contactId,
                'name' => $adjustment['name'],
                'current' => $adjustment['amount'],
                'b1_30' => 0,
                'b31_60' => 0,
                'b61_90' => 0,
                'b90_plus' => 0,
                'total' => $adjustment['amount'],
            ];
        }

        // Catch-all so the grand total ties to the control-account balance to
        // the penny: absorbs ledger amounts that can't be attributed to a
        // contact (no contact on the line, or import drift).
        $unattributed = $this->controlBalance($company, $kind, $asOf) - array_sum(array_column($byContact, 'total'));
        if ($unattributed !== 0) {
            $byContact['adj:unattributed'] = [
                'contact_id' => 0,
                'name' => $this->unattributedLabel($kind),
                'current' => $unattributed,
                'b1_30' => 0,
                'b31_60' => 0,
                'b61_90' => 0,
                'b90_plus' => 0,
                'total' => $unattributed,
            ];
        }

        // Drop fully-settled contacts (zero balance). "Owing only" additionally
        // hides credit balances, leaving just the contacts who actually owe.
        $byContact = array_filter($byContact, function (array $row) use ($owingOnly): bool {
            if ($row['total'] === 0) {
                return false;
            }

            return ! ($owingOnly && $row['total'] < 0);
        });

        $totals = ['current' => 0, 'b1_30' => 0, 'b31_60' => 0, 'b61_90' => 0, 'b90_plus' => 0, 'total' => 0];

        foreach ($byContact as $row) {
            foreach ($totals as $k => $v) {
                $totals[$k] += $row[$k];
            }
        }

        return ['rows' => array_values($byContact), 'totals' => $totals];
    }

    /**
     * One contact's aging buckets — the customer-statement aging strip. Built
     * from summary() (not re-derived) so the row keeps the reconciliation
     * guarantee: its total equals the contact's GL / statement balance.
     * "Owing only" stays off so a net-credit contact gets a truthful row;
     * a contact with no balance gets all zeros.
     *
     * @return array{current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int}
     */
    public function summaryRowForContact(Company $company, string $kind, CarbonImmutable $asOf, Contact $contact): array
    {
        foreach ($this->summary($company, $kind, $asOf, owingOnly: false)['rows'] as $row) {
            if ($row['contact_id'] === $contact->id) {
                return [
                    'current' => $row['current'],
                    'b1_30' => $row['b1_30'],
                    'b31_60' => $row['b31_60'],
                    'b61_90' => $row['b61_90'],
                    'b90_plus' => $row['b90_plus'],
                    'total' => $row['total'],
                ];
            }
        }

        return ['current' => 0, 'b1_30' => 0, 'b31_60' => 0, 'b61_90' => 0, 'b90_plus' => 0, 'total' => 0];
    }

    /**
     * Every open document individually, grouped by aging bucket, with the GL
     * reconciling amounts as an explicit Adjustments section so the grand
     * total still ties to the control account.
     *
     * @return array{
     *   buckets: array<string, array{label: string, rows: array<int, array{doc_id: int, doc_no: string, contact_id: int, name: string, doc_date: string, due_date: string, days_overdue: int, balance: int}>, subtotal: int}>,
     *   adjustments: array<int, array{contact_id: int, name: string, amount: int}>,
     *   adjustments_total: int,
     *   grand_total: int,
     * }
     */
    public function detail(Company $company, string $kind, CarbonImmutable $asOf, bool $owingOnly): array
    {
        $docs = $this->openDocumentRows($company, $kind, $asOf);

        $openDocTotals = [];
        foreach ($docs as $doc) {
            $openDocTotals[$doc['contact_id']] = ($openDocTotals[$doc['contact_id']] ?? 0) + $doc['balance'];
        }

        $adjustments = [];
        foreach ($this->glReconcilingByContact($company, $kind, $asOf, $openDocTotals) as $contactId => $adjustment) {
            $adjustments[] = [
                'contact_id' => $contactId,
                'name' => $adjustment['name'],
                'amount' => $adjustment['amount'],
            ];
        }

        $unattributed = $this->controlBalance($company, $kind, $asOf)
            - array_sum($openDocTotals)
            - array_sum(array_column($adjustments, 'amount'));

        if ($unattributed !== 0) {
            $adjustments[] = [
                'contact_id' => 0,
                'name' => $this->unattributedLabel($kind),
                'amount' => $unattributed,
            ];
        }

        // Net per contact (documents + adjustment) drives the same row filter
        // the summary applies: zero-net contacts disappear, and "owing only"
        // also hides net-credit contacts — documents AND adjustment together,
        // so both views always share the same grand total.
        $netByContact = $openDocTotals;
        foreach ($adjustments as $adjustment) {
            $key = $adjustment['contact_id'];
            $netByContact[$key] = ($netByContact[$key] ?? 0) + $adjustment['amount'];
        }

        $hidden = [];
        foreach ($netByContact as $contactId => $net) {
            if ($net === 0 || ($owingOnly && $net < 0)) {
                $hidden[$contactId] = true;
            }
        }

        $docs = array_values(array_filter($docs, fn (array $doc): bool => ! isset($hidden[$doc['contact_id']])));
        $adjustments = array_values(array_filter($adjustments, fn (array $a): bool => ! isset($hidden[$a['contact_id']])));

        $buckets = [];
        foreach (self::BUCKETS as $bucket) {
            $buckets[$bucket] = ['label' => $this->bucketLabel($bucket), 'rows' => [], 'subtotal' => 0];
        }

        foreach ($docs as $doc) {
            $bucket = $doc['bucket'];
            unset($doc['bucket']);
            $buckets[$bucket]['rows'][] = $doc;
            $buckets[$bucket]['subtotal'] += $doc['balance'];
        }

        $adjustmentsTotal = array_sum(array_column($adjustments, 'amount'));

        return [
            'buckets' => $buckets,
            'adjustments' => $adjustments,
            'adjustments_total' => $adjustmentsTotal,
            'grand_total' => array_sum(array_column($buckets, 'subtotal')) + $adjustmentsTotal,
        ];
    }

    /**
     * Open documents as flat rows with their bucket assigned, home-currency.
     *
     * @return array<int, array{doc_id: int, doc_no: string, contact_id: int, name: string, doc_date: string, due_date: string, days_overdue: int, balance: int, bucket: string}>
     */
    private function openDocumentRows(Company $company, string $kind, CarbonImmutable $asOf): array
    {
        // Map each model to a common plain shape up front so the shared
        // bucketing below is model-agnostic. Names are parent-qualified so a
        // sub-customer/job row reads "Parent : Job".
        $documents = $kind === 'ar'
            ? Invoice::query()
                ->where('company_id', $company->id)
                ->with('contact.parent')
                ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
                ->where('invoice_date', '<=', $asOf)
                ->get()
                ->map(fn (Invoice $doc): array => [
                    'doc_id' => $doc->id,
                    'doc_no' => (string) $doc->invoice_no,
                    'contact_id' => $doc->contact_id,
                    'name' => $doc->contact?->qualifiedName() ?? '(unknown)',
                    'doc_date' => $doc->invoice_date,
                    'due_date' => $doc->due_date,
                    'currency_code' => $doc->currency_code,
                    'fx_rate' => $doc->fx_rate,
                    'balance' => $doc->balanceCents(),
                ])
            : Bill::query()
                ->where('company_id', $company->id)
                ->vendor()
                ->with('contact.parent')
                ->whereIn('status', [BillStatus::Posted->value, BillStatus::Partial->value])
                ->where('bill_date', '<=', $asOf)
                ->get()
                ->map(fn (Bill $doc): array => [
                    'doc_id' => $doc->id,
                    'doc_no' => (string) $doc->bill_no,
                    'contact_id' => $doc->contact_id,
                    'name' => $doc->contact?->qualifiedName() ?? '(unknown)',
                    'doc_date' => $doc->bill_date,
                    'due_date' => $doc->due_date,
                    'currency_code' => $doc->currency_code,
                    'fx_rate' => $doc->fx_rate,
                    'balance' => $doc->balanceCents(),
                ]);

        $rows = [];

        foreach ($documents as $doc) {
            $balance = $doc['balance'];
            if ($balance <= 0) {
                continue;
            }

            if ($doc['currency_code'] !== null && ! $company->isHomeCurrency($doc['currency_code']) && $doc['fx_rate'] !== null) {
                $balance = Currency::toHomeCents($balance, (string) $doc['fx_rate']);
            }

            $dueDate = CarbonImmutable::parse($doc['due_date']);
            // Signed and possibly fractional (dates can carry a time part);
            // bucket on the exact value — matching the summary's historical
            // boundaries — and only round up for display.
            $daysOverdue = -$asOf->diffInDays($dueDate, false);

            $rows[] = [
                'doc_id' => $doc['doc_id'],
                'doc_no' => $doc['doc_no'],
                'contact_id' => $doc['contact_id'],
                'name' => $doc['name'],
                'doc_date' => CarbonImmutable::parse($doc['doc_date'])->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'days_overdue' => $daysOverdue > 0 ? (int) ceil($daysOverdue) : 0,
                'balance' => $balance,
                'bucket' => match (true) {
                    $daysOverdue <= 0 => 'current',
                    $daysOverdue <= 30 => 'b1_30',
                    $daysOverdue <= 60 => 'b31_60',
                    $daysOverdue <= 90 => 'b61_90',
                    default => 'b90_plus',
                },
            ];
        }

        return $rows;
    }

    /**
     * Per contact, the amount needed to bring their open-document total up
     * (or down) to their GL control-account balance as of $asOf. AR sums
     * debit − credit; AP is a liability, so credit − debit.
     *
     * @param  array<int, int>  $openDocTotals  contact_id => open document total in cents
     * @return array<int, array{name: string, amount: int}>
     */
    private function glReconcilingByContact(Company $company, string $kind, CarbonImmutable $asOf, array $openDocTotals): array
    {
        $accountIds = $this->controlAccountIds($company, $kind);

        if ($accountIds->isEmpty()) {
            return [];
        }

        $balanceExpr = $kind === 'ar'
            ? 'SUM(jl.debit_cents - jl.credit_cents)'
            : 'SUM(jl.credit_cents - jl.debit_cents)';

        $glByContact = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $accountIds)
            ->whereNotNull('jl.contact_id')
            ->where('je.entry_date', '<=', $asOf)
            ->groupBy('jl.contact_id')
            ->selectRaw("jl.contact_id AS cid, {$balanceExpr} AS bal")
            ->pluck('bal', 'cid');

        $reconciling = [];
        foreach ($glByContact as $contactId => $bal) {
            $contactId = (int) $contactId;
            $amount = (int) $bal - (int) ($openDocTotals[$contactId] ?? 0);

            if ($amount !== 0) {
                $reconciling[$contactId] = ['name' => '(unknown)', 'amount' => $amount];
            }
        }

        if ($reconciling === []) {
            return [];
        }

        $names = DB::table('contacts')
            ->whereIn('id', array_keys($reconciling))
            ->pluck('display_name', 'id');

        foreach ($names as $id => $name) {
            $reconciling[(int) $id]['name'] = $name;
        }

        return $reconciling;
    }

    /**
     * The control-account balance across every posted journal line as of
     * $asOf — the ledger figure the aging grand total reconciles to.
     */
    public function controlBalance(Company $company, string $kind, CarbonImmutable $asOf): int
    {
        $accountIds = $this->controlAccountIds($company, $kind);

        if ($accountIds->isEmpty()) {
            return 0;
        }

        $balanceExpr = $kind === 'ar'
            ? 'jl.debit_cents - jl.credit_cents'
            : 'jl.credit_cents - jl.debit_cents';

        return (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)
            ->where('je.is_posted', true)
            ->whereIn('jl.account_id', $accountIds)
            ->where('je.entry_date', '<=', $asOf)
            ->sum(DB::raw($balanceExpr));
    }

    /**
     * @return Collection<int, int>
     */
    private function controlAccountIds(Company $company, string $kind): Collection
    {
        return Account::query()
            ->where('company_id', $company->id)
            ->where('subtype', $kind === 'ar'
                ? AccountSubtype::AccountsReceivable->value
                : AccountSubtype::AccountsPayable->value)
            ->pluck('id');
    }

    private function unattributedLabel(string $kind): string
    {
        return $kind === 'ar'
            ? __('(Unattributed AR — no customer)')
            : __('(Unattributed AP — no vendor)');
    }

    /**
     * @param  'current'|'b1_30'|'b31_60'|'b61_90'|'b90_plus'  $bucket
     */
    private function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'current' => __('Current'),
            'b1_30' => __('1–30 days'),
            'b31_60' => __('31–60 days'),
            'b61_90' => __('61–90 days'),
            'b90_plus' => __('90+ days'),
        };
    }
}
