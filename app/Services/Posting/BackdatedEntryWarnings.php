<?php

namespace App\Services\Posting;

use App\Enums\AccountSubtype;
use App\Enums\BankReconciliationStatus;
use App\Models\Company;
use App\Support\Reporting\ReportDatePresets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Soft, advisory checks for a back-dated posting — reasons a user should
 * pause before committing, never reasons to refuse (the hard gates are the
 * lock date, filed tax periods and reconciled lines, enforced by the posters).
 *
 * Interactive forms show these in a confirm-before-posting dialog; the API and
 * scheduled jobs don't ask.
 */
class BackdatedEntryWarnings
{
    /**
     * @param  iterable<int|null>  $accountIds  Account IDs the entry touches (nulls are ignored).
     * @return list<string>
     */
    public function for(Company $company, iterable $accountIds, CarbonInterface $entryDate): array
    {
        $entryDate = CarbonImmutable::parse($entryDate)->startOfDay();
        $warnings = [];

        foreach ($this->reconciledThrough($company, $accountIds, $entryDate) as $row) {
            $warnings[] = __('This entry is dated on or before the :account reconciliation through :date. Its :account line will show as an uncleared item on your next reconciliation of that account.', [
                'account' => $row->account_name,
                'date' => CarbonImmutable::parse($row->statement_date)->toFormattedDateString(),
            ]);
        }

        $fiscalYearStart = ReportDatePresets::fiscalYearStart(
            $company->currentDateTime()->startOfDay(),
            (int) ($company->fiscal_year_start_month ?? 1),
        );

        if ($entryDate->lessThan($fiscalYearStart)) {
            $warnings[] = __('This entry posts to a prior fiscal year (the current one began :date). Prior-year figures, including reports already shared with your accountant, will change.', [
                'date' => $fiscalYearStart->toFormattedDateString(),
            ]);
        }

        return $warnings;
    }

    /**
     * The latest completed reconciliation, per touched bank / credit-card
     * account, whose statement date is on or after the entry date.
     *
     * @param  iterable<int|null>  $accountIds
     * @return list<object{account_name: string, statement_date: string}>
     */
    protected function reconciledThrough(Company $company, iterable $accountIds, CarbonImmutable $entryDate): array
    {
        $ids = collect($accountIds)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('bank_reconciliations')
            ->join('accounts', 'accounts.id', '=', 'bank_reconciliations.account_id')
            ->where('bank_reconciliations.company_id', $company->id)
            ->where('bank_reconciliations.status', BankReconciliationStatus::Completed->value)
            ->whereIn('bank_reconciliations.account_id', $ids)
            ->whereIn('accounts.subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->whereDate('bank_reconciliations.statement_date', '>=', $entryDate->toDateString())
            ->groupBy('accounts.id', 'accounts.name', 'accounts.code')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.name as account_name, MAX(bank_reconciliations.statement_date) as statement_date')
            ->get()
            ->all();
    }
}
