<?php

namespace App\Services\Migration;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * After a migration, a control-account journal line can be left without a contact even though
 * the document it belongs to has one — the GL replay tags lines by name match, while document
 * reconstruction resolves the customer/vendor separately, so the two can disagree. That makes a
 * customer's GL-driven AR/AP Statement disagree with their aging.
 *
 * This backfills `journal_lines.contact_id` on AR/AP control-account lines from their source
 * document's contact, wherever the line is currently untagged. It is purely an attribution fix:
 * debits/credits and account balances are untouched. Set-based and idempotent.
 */
class ContactLinkBackfiller
{
    /**
     * Source document type => [control-account subtype to tag, that document's table].
     *
     * @var array<class-string, array{0: AccountSubtype, 1: string}>
     */
    private const DOCUMENT_CONTROL = [
        Invoice::class => [AccountSubtype::AccountsReceivable, 'invoices'],
        CustomerReceipt::class => [AccountSubtype::AccountsReceivable, 'customer_receipts'],
        CreditMemo::class => [AccountSubtype::AccountsReceivable, 'credit_memos'],
        Bill::class => [AccountSubtype::AccountsPayable, 'bills'],
        BillPayment::class => [AccountSubtype::AccountsPayable, 'bill_payments'],
    ];

    /**
     * @return array{updated: int}
     */
    public function backfill(int $companyId): array
    {
        $accountIdsBySubtype = [];

        foreach ([AccountSubtype::AccountsReceivable, AccountSubtype::AccountsPayable] as $subtype) {
            $accountIdsBySubtype[$subtype->value] = Account::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('subtype', $subtype->value)
                ->pluck('id')
                ->all();
        }

        $updated = 0;

        foreach (self::DOCUMENT_CONTROL as $sourceType => [$subtype, $table]) {
            $accountIds = $accountIdsBySubtype[$subtype->value];

            if ($accountIds === []) {
                continue;
            }

            // A join-based UPDATE isn't portable: SQLite rewrites it into a
            // `rowid IN (subquery)` form that drops the joined alias from the SET
            // clause. Instead, restrict the rows with a plain IN subquery and pull
            // the contact via a correlated subquery that never touches journal_lines.
            $updated += DB::table('journal_lines')
                ->whereIn('account_id', $accountIds)
                ->whereNull('contact_id')
                ->whereIn('journal_entry_id', function ($query) use ($companyId, $sourceType, $table) {
                    $query->select('je.id')
                        ->from('journal_entries as je')
                        ->join("{$table} as d", 'd.id', '=', 'je.source_id')
                        ->where('je.company_id', $companyId)
                        ->where('je.source_type', $sourceType)
                        ->whereNotNull('d.contact_id');
                })
                ->update([
                    'contact_id' => DB::raw(
                        '(select d.contact_id from journal_entries as je'
                        ." inner join {$table} as d on d.id = je.source_id"
                        .' where je.id = journal_lines.journal_entry_id)'
                    ),
                ]);
        }

        return ['updated' => $updated];
    }
}
