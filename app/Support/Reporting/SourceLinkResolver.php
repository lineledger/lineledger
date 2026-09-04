<?php

namespace App\Support\Reporting;

use App\Models\BankReconciliation;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceState;
use App\Models\TaxReturn;
use App\Models\Transfer;
use App\Models\VendorCredit;
use Illuminate\Support\Facades\Route;

/**
 * Resolves the originating document behind a journal entry to a viewable URL —
 * the "QuickZoom" drill target. There is no enforced morph map, so source_type
 * holds the fully-qualified model class. Returns null when nothing is linkable.
 */
class SourceLinkResolver
{
    /**
     * source model class => its show route name (each {company}/{model}).
     *
     * @var array<class-string, string>
     */
    private const ROUTES = [
        Invoice::class => 'invoices.show',
        Bill::class => 'bills.show',
        CustomerReceipt::class => 'receipts.show',
        Deposit::class => 'deposits.show',
        BillPayment::class => 'bill-payments.show',
        Cheque::class => 'cheques.show',
        Expense::class => 'expenses.show',
        CreditMemo::class => 'credit-memos.show',
        VendorCredit::class => 'vendor-credits.show',
        TaxReturn::class => 'tax-returns.show',
        Transfer::class => 'transfers.show',
        BankReconciliation::class => 'banking.reconciliations.show',
    ];

    /**
     * source model class => its create route name, for the source types whose
     * form supports `?from=` duplication. Other types have no duplicate flow.
     *
     * @var array<class-string, string>
     */
    private const DUPLICATE_ROUTES = [
        Bill::class => 'bills.create',
        Deposit::class => 'deposits.create',
    ];

    /**
     * The linkable source model classes — the whitelist behind the Transactions
     * report's source-type filter.
     *
     * @return list<class-string>
     */
    public static function sourceTypes(): array
    {
        return array_keys(self::ROUTES);
    }

    public function urlFor(JournalEntry $entry, Company $company): ?string
    {
        // Manual general-journal entries have no source document.
        if ($entry->source_type === null) {
            return Route::has('journal.show')
                ? route('journal.show', [$company->slug, $entry->getRouteKey()])
                : null;
        }

        // The maintained opening-balances entry drills to its workspace, not a
        // document (the workspace route carries no id).
        if ($entry->source_type === OpeningBalanceState::class) {
            return Route::has('opening-balances.index')
                ? route('opening-balances.index', [$company->slug])
                : null;
        }

        // A reconciliation still in progress is worked on from the reconcile
        // screen (where its service charge / interest can be edited); only a
        // completed one has a read-only detail page.
        if ($entry->source_type === BankReconciliation::class) {
            return $this->reconciliationUrl($entry, $company);
        }

        $name = self::ROUTES[$entry->source_type] ?? null;

        if ($name === null || $entry->source_id === null || ! Route::has($name)) {
            return null;
        }

        try {
            return route($name, [$company->slug, $entry->source_id]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function label(JournalEntry $entry): string
    {
        if ($entry->source_type === OpeningBalanceState::class) {
            return __('Opening balances');
        }

        if ($entry->source_type === BankReconciliation::class) {
            return __('Bank reconciliation');
        }

        return $entry->source_type === null
            ? __('Journal entry')
            : class_basename($entry->source_type);
    }

    private function reconciliationUrl(JournalEntry $entry, Company $company): ?string
    {
        if ($entry->source_id === null) {
            return null;
        }

        $rec = BankReconciliation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->find($entry->source_id);

        if ($rec === null) {
            return null;
        }

        try {
            if ($rec->isInProgress() && Route::has('banking.reconcile')) {
                return route('banking.reconcile', ['company' => $company->slug, 'account' => $rec->account_id]);
            }

            return Route::has('banking.reconciliations.show')
                ? route('banking.reconciliations.show', [$company->slug, $rec->id])
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * URL that duplicates the originating document itself (not the journal entry),
     * for source types whose create form supports `?from=`. Returns null when the
     * source type has no duplicate flow, or for manual entries (those duplicate
     * through journal.create directly).
     */
    public function duplicateUrlFor(JournalEntry $entry, Company $company): ?string
    {
        if ($entry->source_type === null || $entry->source_id === null) {
            return null;
        }

        $name = self::DUPLICATE_ROUTES[$entry->source_type] ?? null;

        if ($name === null || ! Route::has($name)) {
            return null;
        }

        try {
            return route($name, ['company' => $company->slug, 'from' => $entry->source_id]);
        } catch (\Throwable) {
            return null;
        }
    }
}
