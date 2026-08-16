<?php

namespace App\Services\Reporting;

use App\Enums\AccountType;
use App\Enums\BillPaymentStatus;
use App\Enums\NormalBalance;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPaymentApplication;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\ReceiptApplication;
use App\Support\Currency;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cash-basis P&L activity: income and expense are recognized when payment is
 * applied, not when the document is issued (the QuickBooks cash-basis model).
 *
 * Composition, per account over a period:
 *
 * 1. Cash-natured GL activity — every posted income/expense journal line
 *    EXCEPT income-type lines on Invoice-sourced entries and expense-type
 *    lines on Bill-sourced entries (and their void reversals). What remains —
 *    cheques, deposits, receipts' FX gain/loss, payroll, manual journal
 *    entries, credit memos, vendor credits, and invoice-sourced COGS — is
 *    already cash-timed. COGS deliberately stays at sale date: inventory is
 *    the standard exception to cash accounting (QBO does the same).
 *
 * 2. Payment-derived recognition — each receipt application (by its parent
 *    receipt's date) recognizes the paid share of the invoice, allocated
 *    pro-rata across the invoice's lines into their accounts/dimensions.
 *    Mirrored for bill payment applications. The tax slice is excluded: tax
 *    collected stays a liability under either basis. Foreign documents
 *    convert at their locked rate, matching how posting clears AR/AP — the
 *    receipt-vs-document rate difference is already a realized FX line
 *    captured by step 1.
 *
 * Documented v1 approximations: credit memos / vendor credits recognize at
 * their document date (nothing records when they're consumed); unapplied
 * receipt/payment amounts are not income/expense (no QBO-style "Unapplied
 * cash payment income" synthetic row); per-line rounding is not forced to
 * tie to the payment total.
 */
class CashBasisCalculator
{
    /**
     * Cash-basis activity per account (natural sign, cents) — a drop-in
     * replacement for looping ReportCalculator::periodChange() on the
     * income statement.
     *
     * @return array<int, int> account_id => cents
     */
    public function periodChangesByAccount(
        Company $company,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $classId = null,
        ?int $locationId = null,
        ?int $fundId = null,
    ): array {
        $accounts = $this->profitAndLossAccounts($company);

        $map = $this->cashNaturalGlActivity($company, $start, $end, $classId, $locationId, $fundId);

        $this->addReceiptRecognition($map, $accounts, $company, $start, $end, $classId, $locationId, $fundId);
        $this->addBillPaymentRecognition($map, $accounts, $company, $start, $end, $classId, $locationId, $fundId);

        return $map;
    }

    /**
     * Step 1: posted income/expense journal lines that are already cash-timed.
     * A void reversal carries source_type = null and reverses_entry_id, so the
     * effective source is COALESCE(entry source, reversed entry's source) —
     * otherwise voiding an excluded invoice would leave half the pair behind.
     *
     * @return array<int, int>
     */
    private function cashNaturalGlActivity(
        Company $company,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $classId,
        ?int $locationId,
        ?int $fundId,
    ): array {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('journal_entries as rev', 'rev.id', '=', 'je.reverses_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.company_id', $company->id)
            ->where('jl.is_posted', true)
            ->whereBetween('jl.entry_date', [$start, $end])
            ->whereIn('a.type', [AccountType::Income->value, AccountType::Expense->value])
            // The trailing '' keeps the comparison two-valued: a manual entry
            // has no source at all, and NOT (… AND NULL) would drop the row.
            ->whereRaw("NOT (a.type = ? AND COALESCE(je.source_type, rev.source_type, '') = ?)", [
                AccountType::Income->value, Invoice::class,
            ])
            ->whereRaw("NOT (a.type = ? AND COALESCE(je.source_type, rev.source_type, '') = ?)", [
                AccountType::Expense->value, Bill::class,
            ])
            ->when($classId !== null, fn ($q) => $q->where('jl.class_id', $classId))
            ->when($locationId !== null, fn ($q) => $q->where('jl.location_id', $locationId))
            ->when($fundId !== null, fn ($q) => $q->where('jl.fund_id', $fundId))
            ->groupBy('jl.account_id', 'a.normal_balance')
            ->selectRaw('jl.account_id AS account_id, a.normal_balance AS normal_balance, COALESCE(SUM(jl.debit_cents - jl.credit_cents), 0) AS signed_total')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $signed = (int) $row->signed_total;
            $map[(int) $row->account_id] = $row->normal_balance === NormalBalance::Debit->value ? $signed : -$signed;
        }

        return $map;
    }

    /**
     * Step 2: recognize the paid share of each invoice when the receipt is
     * dated, allocated pro-rata across the invoice's lines (subtotal only —
     * tax stays a liability). An invoice line credits its account, so the
     * natural amount is positive for credit-normal accounts.
     *
     * @param  array<int, int>  $map
     * @param  array<int, NormalBalance>  $accounts
     */
    private function addReceiptRecognition(
        array &$map,
        array $accounts,
        Company $company,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $classId,
        ?int $locationId,
        ?int $fundId,
    ): void {
        $applications = ReceiptApplication::query()
            ->whereHas('receipt', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', ReceiptStatus::Posted->value)
                ->whereBetween('receipt_date', [$start, $end]))
            ->with(['receipt' => fn ($q) => $q->withoutGlobalScopes(), 'invoice.lines'])
            ->get();

        foreach ($applications as $application) {
            $invoice = $application->invoice;

            if ($invoice === null || (int) $invoice->total_cents <= 0) {
                continue;
            }

            $ratio = min(1.0, $application->amount_cents / $invoice->total_cents);

            $isForeign = $invoice->currency_code !== null
                && ! $company->isHomeCurrency($invoice->currency_code)
                && $invoice->fx_rate !== null;

            foreach ($invoice->lines as $line) {
                $normal = $accounts[$line->account_id] ?? null;

                if ($normal === null) {
                    continue; // Non-P&L line (asset, liability) — out of scope.
                }

                if (! $this->lineMatchesDimensions($line, $classId, $locationId, $fundId)) {
                    continue;
                }

                $slice = (int) round($line->line_subtotal_cents * $ratio);

                if ($isForeign) {
                    $slice = Currency::toHomeCents($slice, (string) $invoice->fx_rate);
                }

                $sign = $normal === NormalBalance::Credit ? 1 : -1;

                $map[$line->account_id] = ($map[$line->account_id] ?? 0) + $sign * $slice;
            }
        }
    }

    /**
     * Step 3: the AP mirror — a bill line debits its account, so the natural
     * amount is positive for debit-normal accounts.
     *
     * @param  array<int, int>  $map
     * @param  array<int, NormalBalance>  $accounts
     */
    private function addBillPaymentRecognition(
        array &$map,
        array $accounts,
        Company $company,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $classId,
        ?int $locationId,
        ?int $fundId,
    ): void {
        $applications = BillPaymentApplication::query()
            ->whereHas('payment', fn ($q) => $q
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', BillPaymentStatus::Posted->value)
                ->whereBetween('payment_date', [$start, $end]))
            ->with(['payment' => fn ($q) => $q->withoutGlobalScopes(), 'bill.lines'])
            ->get();

        foreach ($applications as $application) {
            $bill = $application->bill;

            if ($bill === null || (int) $bill->total_cents <= 0) {
                continue;
            }

            $ratio = min(1.0, $application->amount_cents / $bill->total_cents);

            $isForeign = $bill->currency_code !== null
                && ! $company->isHomeCurrency($bill->currency_code)
                && $bill->fx_rate !== null;

            foreach ($bill->lines as $line) {
                $normal = $accounts[$line->account_id] ?? null;

                if ($normal === null) {
                    continue;
                }

                if (! $this->lineMatchesDimensions($line, $classId, $locationId, $fundId)) {
                    continue;
                }

                $slice = (int) round($line->line_subtotal_cents * $ratio);

                if ($isForeign) {
                    $slice = Currency::toHomeCents($slice, (string) $bill->fx_rate);
                }

                $sign = $normal === NormalBalance::Debit ? 1 : -1;

                $map[$line->account_id] = ($map[$line->account_id] ?? 0) + $sign * $slice;
            }
        }
    }

    private function lineMatchesDimensions(object $line, ?int $classId, ?int $locationId, ?int $fundId): bool
    {
        if ($classId !== null && (int) $line->class_id !== $classId) {
            return false;
        }

        if ($locationId !== null && (int) $line->location_id !== $locationId) {
            return false;
        }

        if ($fundId !== null && (int) $line->fund_id !== $fundId) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, NormalBalance> account_id => normal balance
     */
    private function profitAndLossAccounts(Company $company): array
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->pluck('normal_balance', 'id')
            ->all();
    }
}
