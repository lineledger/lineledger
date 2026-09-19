<?php

namespace App\Services\Banking;

use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\PayrollRemittance;
use App\Models\SalesReceipt;
use App\Models\TaxReturnPayment;
use App\Models\Transfer;
use App\Support\Banking\BankLineMemo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites the bank-side journal line memo of already-posted subledger documents
 * so they read "Deposit: August 2026 Interac Deposits" instead of a bare "Deposit".
 *
 * New postings get this from the posters; this catches everything posted before.
 * It only ever replaces a memo that is still exactly the old bare label, on a line
 * sitting on that document's own bank account — so an operator-edited memo, and
 * every other leg of the entry, are left alone. Amounts are untouched, which is
 * why it does not repost: the GL is identical afterwards, only the wording differs.
 * Idempotent — a second run matches nothing. A dry run counts the same lines the
 * live run would rewrite, and writes nothing.
 */
class BankLineMemoBackfiller
{
    /**
     * Source document => the column(s) naming the bank account its bank leg hits.
     * A transfer writes two, one per side.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const BANK_ACCOUNT_COLUMNS = [
        Deposit::class => ['bank_account_id'],
        Cheque::class => ['bank_account_id'],
        Expense::class => ['payment_account_id'],
        Transfer::class => ['from_account_id', 'to_account_id'],
        CustomerReceipt::class => ['deposit_to_account_id'],
        SalesReceipt::class => ['deposit_to_account_id'],
        BillPayment::class => ['paid_from_account_id'],
        TaxReturnPayment::class => ['bank_account_id'],
        PayrollRemittance::class => ['bank_account_id'],
    ];

    /**
     * @param  bool  $dryRun  Count the lines a live run would rewrite, without writing.
     * @return array{updated: int}
     */
    public function backfill(int $companyId, bool $dryRun = false): array
    {
        $updated = 0;

        foreach (self::BANK_ACCOUNT_COLUMNS as $sourceType => $columns) {
            $query = $sourceType::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNotNull('journal_entry_id');

            if (in_array(SoftDeletes::class, class_uses_recursive($sourceType), true)) {
                // A voided document keeps its original entry in the register, so
                // its rows want the same wording.
                $query->withTrashed();
            }

            $query->chunkById(200, function ($documents) use ($columns, $dryRun, &$updated) {
                foreach ($documents as $document) {
                    $updated += $this->rewrite($document, $columns, $dryRun);
                }
            });
        }

        return ['updated' => $updated];
    }

    /**
     * @param  list<string>  $columns
     */
    private function rewrite(Deposit|Cheque|Expense|Transfer|CustomerReceipt|SalesReceipt|BillPayment|TaxReturnPayment|PayrollRemittance $document, array $columns, bool $dryRun): int
    {
        $label = BankLineMemo::labelFor($document);
        $memo = BankLineMemo::forSource($document);

        // No operator memo on the document — nothing to add.
        if ($label === null || $memo === null || $memo === $label) {
            return 0;
        }

        $accountIds = array_values(array_filter(array_map(
            fn (string $column): ?int => $document->{$column} === null ? null : (int) $document->{$column},
            $columns,
        )));

        if ($accountIds === []) {
            return 0;
        }

        $lines = DB::table('journal_lines')
            ->where('journal_entry_id', $document->journal_entry_id)
            ->whereIn('account_id', $accountIds)
            ->where('memo', $label);

        return $dryRun ? $lines->count() : $lines->update(['memo' => $memo]);
    }
}
