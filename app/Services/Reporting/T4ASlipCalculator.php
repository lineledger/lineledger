<?php

namespace App\Services\Reporting;

use App\Enums\BillPaymentStatus;
use App\Enums\ChequeStatus;
use App\Enums\ExpenseStatus;
use App\Models\Company;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Totals year-end disbursements to vendors flagged for T4A reporting (Canada).
 * The Canadian analog of {@see Form1099Calculator}: every flagged contractor's
 * fees-for-services total (T4A Box 048) is the sum of everything actually paid
 * out to them in the calendar year — posted bill payments plus posted cheques
 * coded directly to expense (refund cheques excluded). Amounts in home cents.
 */
class T4ASlipCalculator
{
    /** CRA requires a T4A when fees for services exceed $500 in the year. */
    public const THRESHOLD_CENTS = 50000;

    /**
     * The T4A slips for a calendar year — the contractors whose fees for services
     * met the $500 reporting threshold (the ones a T4A must be issued for). Mirrors
     * {@see T4SlipCalculator::slipsForYear()}.
     *
     * @return array<int, array{contact_id: int, name: string, tax_number: ?string, box048_cents: int, meets_threshold: bool}>
     */
    public function slipsForYear(Company $company, int $year): array
    {
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $end = CarbonImmutable::create($year, 12, 31)->endOfDay();

        return array_values(array_filter(
            $this->rows($company, $start, $end),
            fn (array $row): bool => $row['meets_threshold'],
        ));
    }

    /**
     * Year totals across all reportable T4A slips, for the T4A Summary.
     *
     * @return array{slip_count: int, box048: int}
     */
    public function summary(Company $company, int $year): array
    {
        $slips = $this->slipsForYear($company, $year);

        return [
            'slip_count' => count($slips),
            'box048' => array_sum(array_column($slips, 'box048_cents')),
        ];
    }

    /**
     * @return array<int, array{contact_id: int, name: string, tax_number: ?string, box048_cents: int, meets_threshold: bool}>
     */
    public function rows(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $vendors = Contact::query()
            ->where('company_id', $company->id)
            ->where('is_vendor', true)
            ->where('track_t4a', true)
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'tax_number']);

        if ($vendors->isEmpty()) {
            return [];
        }

        $ids = $vendors->pluck('id')->all();

        $byBillPayment = DB::table('bill_payments')
            ->whereIn('contact_id', $ids)
            ->where('status', BillPaymentStatus::Posted->value)
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('contact_id')
            ->selectRaw('contact_id, SUM(COALESCE(home_amount_cents, amount_cents)) AS total')
            ->pluck('total', 'contact_id');

        $byCheque = DB::table('cheques')
            ->whereIn('payee_contact_id', $ids)
            ->whereNull('credit_memo_id')
            ->where('status', ChequeStatus::Posted->value)
            ->whereBetween('cheque_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('payee_contact_id')
            ->selectRaw('payee_contact_id, SUM(COALESCE(home_amount_cents, amount_cents)) AS total')
            ->pluck('total', 'payee_contact_id');

        // Pay-now expenses (card / EFT / debit, incl. lines recorded from a bank
        // import) are a third, disjoint disbursement path: never a bill payment,
        // never a cheque, so summing all three cannot double count.
        $byExpense = DB::table('expenses')
            ->where('company_id', $company->id)
            ->whereIn('payee_contact_id', $ids)
            ->where('status', ExpenseStatus::Posted->value)
            ->whereNull('deleted_at')
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('payee_contact_id')
            ->selectRaw('payee_contact_id, SUM(COALESCE(home_amount_cents, amount_cents)) AS total')
            ->pluck('total', 'payee_contact_id');

        $rows = [];

        foreach ($vendors as $vendor) {
            $total = (int) ($byBillPayment[$vendor->id] ?? 0)
                + (int) ($byCheque[$vendor->id] ?? 0)
                + (int) ($byExpense[$vendor->id] ?? 0);

            $rows[] = [
                'contact_id' => (int) $vendor->id,
                'name' => (string) $vendor->display_name,
                'tax_number' => $vendor->tax_number,
                'box048_cents' => $total,
                'meets_threshold' => $total >= self::THRESHOLD_CENTS,
            ];
        }

        return $rows;
    }
}
