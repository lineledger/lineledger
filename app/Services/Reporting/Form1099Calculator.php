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
 * Totals year-end disbursements to vendors flagged for 1099 reporting (US only).
 *
 * A vendor's reportable total is the sum of everything actually paid out to them in
 * the period — posted bill payments plus posted cheques coded directly to expense
 * (a cheque tied to a customer credit-memo refund is excluded). Amounts are summed in
 * home currency (home_amount_cents, falling back to the document amount when null).
 *
 * Single-category model: every flagged vendor is treated as 1099-NEC Box 1
 * (nonemployee compensation); the report does not split amounts across boxes.
 */
class Form1099Calculator
{
    /** The IRS reporting threshold for 1099-NEC, in cents ($600). */
    public const THRESHOLD_CENTS = 60000;

    /**
     * @return array<int, array{contact_id: int, name: string, tax_number: ?string, total_cents: int, meets_threshold: bool}>
     */
    public function rows(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $vendors = Contact::query()
            ->where('company_id', $company->id)
            ->where('is_vendor', true)
            ->where('track_1099', true)
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
                'total_cents' => $total,
                'meets_threshold' => $total >= self::THRESHOLD_CENTS,
            ];
        }

        return $rows;
    }
}
