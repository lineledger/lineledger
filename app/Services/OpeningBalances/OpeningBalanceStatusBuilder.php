<?php

namespace App\Services\OpeningBalances;

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\CreditMemo;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\OpeningBalanceState;
use App\Models\VendorCredit;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The reconciliation panel for the Opening Balances workspace: per-account
 * target vs actual, the AR/AP target-vs-detail-vs-GL ties, the per-bank
 * outstanding-item math (whose "statement-side" figure is what the owner types
 * as their first reconciliation's beginning balance), and the OBE residual —
 * the one number that reads zero exactly when the books fully explain the
 * draft trial balance.
 *
 * "Actual" uses the same semantics as ReportCalculator's balance-as-of
 * (posted lines dated <= as-of), so a zero variance here means the Trial
 * Balance report ties too.
 */
class OpeningBalanceStatusBuilder
{
    public function __construct(
        protected OpeningBalanceAccountResolver $openingBalanceAccounts,
        protected OpeningBalanceJournalSynchronizer $synchronizer,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     targets: array{debit: int, credit: int, imbalance: int},
     *     ar: array{target: int, detail: int, gl: int},
     *     ap: array{target: int, detail: int, gl: int},
     *     banks: list<array<string, mixed>>,
     *     obe: array{target: int, gl: int, residual: int},
     *     dirty: bool,
     *     apply_error: ?string
     * }
     */
    public function build(OpeningBalanceState $state): array
    {
        $state->loadMissing('rows.account', 'company');

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $state->company_id)
            ->get(['id', 'code', 'name', 'subtype', 'currency_code'])
            ->keyBy('id');

        $actual = $this->postedSignedByAccount($accounts->keys()->all(), $state);

        $obe = $this->openingBalanceAccounts->resolve((int) $state->company_id);

        $controlSubtypes = [
            AccountSubtype::AccountsReceivable->value,
            AccountSubtype::AccountsPayable->value,
            AccountSubtype::Inventory->value,
        ];

        $rows = [];
        $targetDebit = 0;
        $targetCredit = 0;
        $targetsByAccount = [];

        foreach ($state->rows->sortBy(fn ($row) => (string) $row->account?->code) as $row) {
            $account = $row->account;

            if (! $account) {
                continue;
            }

            $target = $row->signedCents();
            $targetsByAccount[(int) $account->id] = $target;
            $targetDebit += (int) $row->debit_cents;
            $targetCredit += (int) $row->credit_cents;

            $accountActual = $actual[(int) $account->id] ?? 0;

            $rows[] = [
                'account_id' => (int) $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'subtype' => $account->subtype,
                'is_control' => in_array($account->subtype->value, $controlSubtypes, true),
                'target' => $target,
                'actual' => $accountActual,
                'variance' => $target - $accountActual,
            ];
        }

        $sumBySubtype = function (string $subtype) use ($accounts, $actual): int {
            return $accounts
                ->filter(fn (Account $a) => $a->subtype->value === $subtype)
                ->keys()
                ->sum(fn ($id) => $actual[(int) $id] ?? 0);
        };

        $sumTargetBySubtype = function (string $subtype) use ($accounts, $targetsByAccount): int {
            $sum = 0;
            foreach ($targetsByAccount as $accountId => $target) {
                if (($accounts[$accountId]?->subtype->value ?? null) === $subtype) {
                    $sum += $target;
                }
            }

            return $sum;
        };

        $arDetail = $this->arDetailCents($state);
        $apDetail = $this->apDetailCents($state);

        $obeTarget = $obe ? ($targetsByAccount[(int) $obe->id] ?? 0) : 0;
        $obeActual = $obe ? ($actual[(int) $obe->id] ?? 0) : 0;

        return [
            'rows' => $rows,
            'targets' => [
                'debit' => $targetDebit,
                'credit' => $targetCredit,
                'imbalance' => $targetDebit - $targetCredit,
            ],
            'ar' => [
                'target' => $sumTargetBySubtype(AccountSubtype::AccountsReceivable->value),
                'detail' => $arDetail,
                'gl' => $sumBySubtype(AccountSubtype::AccountsReceivable->value),
            ],
            'ap' => [
                'target' => $sumTargetBySubtype(AccountSubtype::AccountsPayable->value),
                'detail' => $apDetail,
                'gl' => $sumBySubtype(AccountSubtype::AccountsPayable->value),
            ],
            'banks' => $this->bankRows($state, $accounts, $targetsByAccount),
            'obe' => [
                'target' => $obeTarget,
                'gl' => $obeActual,
                'residual' => $obeActual - $obeTarget,
            ],
            'dirty' => $this->synchronizer->isDirty($state),
            'apply_error' => $state->apply_error,
        ];
    }

    /**
     * Net opening AR detail in home cents: posted, non-void opening invoices
     * minus opening credit memos. AR-side signed, i.e. positive = owed to us.
     */
    protected function arDetailCents(OpeningBalanceState $state): int
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->where('company_id', $state->company_id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get(['total_cents', 'home_total_cents'])
            ->sum(fn ($i) => (int) ($i->home_total_cents ?? $i->total_cents));

        $memos = CreditMemo::withoutGlobalScopes()
            ->where('company_id', $state->company_id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get(['total_cents', 'home_total_cents'])
            ->sum(fn ($m) => (int) ($m->home_total_cents ?? $m->total_cents));

        return $invoices - $memos;
    }

    /** AP-side signed, i.e. positive = owed by us (a credit-normal balance). */
    protected function apDetailCents(OpeningBalanceState $state): int
    {
        $bills = Bill::withoutGlobalScopes()
            ->where('company_id', $state->company_id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get(['total_cents', 'home_total_cents'])
            ->sum(fn ($b) => (int) ($b->home_total_cents ?? $b->total_cents));

        $credits = VendorCredit::withoutGlobalScopes()
            ->where('company_id', $state->company_id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get(['total_cents', 'home_total_cents'])
            ->sum(fn ($c) => (int) ($c->home_total_cents ?? $c->total_cents));

        return $bills - $credits;
    }

    /**
     * One row per bank / credit-card account carrying a target or outstanding
     * opening items. statement_side = book target + outstanding cheques −
     * deposits in transit: the figure the maintained entry posts to the bank,
     * and the beginning balance the owner should type into their first
     * reconciliation.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, int>  $targetsByAccount
     * @return list<array<string, mixed>>
     */
    protected function bankRows(OpeningBalanceState $state, $accounts, array $targetsByAccount): array
    {
        $cheques = Cheque::withoutGlobalScopes()
            ->where('company_id', $state->company_id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get(['bank_account_id', 'amount_cents', 'home_amount_cents'])
            ->groupBy('bank_account_id')
            ->map(fn ($group) => (int) $group->sum(fn ($c) => (int) ($c->home_amount_cents ?? $c->amount_cents)));

        $deposits = Deposit::withoutGlobalScopes()
            ->where('company_id', $state->company_id)
            ->where('is_opening_balance', true)
            ->whereNotNull('journal_entry_id')
            ->whereNull('voided_at')
            ->get(['bank_account_id', 'amount_cents', 'home_amount_cents'])
            ->groupBy('bank_account_id')
            ->map(fn ($group) => (int) $group->sum(fn ($d) => (int) ($d->home_amount_cents ?? $d->amount_cents)));

        $bankish = [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value];

        $accountIds = collect(array_keys($targetsByAccount))
            ->filter(fn (int $id) => in_array($accounts[$id]?->subtype->value ?? '', $bankish, true))
            ->merge($cheques->keys())
            ->merge($deposits->keys())
            ->unique()
            ->values();

        $rows = [];

        foreach ($accountIds as $accountId) {
            $account = $accounts[(int) $accountId] ?? null;

            if (! $account) {
                continue;
            }

            $target = $targetsByAccount[(int) $accountId] ?? 0;
            $outstandingCheques = $cheques[(int) $accountId] ?? 0;
            $inTransit = $deposits[(int) $accountId] ?? 0;

            $rows[] = [
                'account_id' => (int) $accountId,
                'code' => $account->code,
                'name' => $account->name,
                'book_target' => $target,
                'outstanding_cheques' => $outstandingCheques,
                'deposits_in_transit' => $inTransit,
                'statement_side' => $target + $outstandingCheques - $inTransit,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $accountIds
     * @return array<int, int>
     */
    protected function postedSignedByAccount(array $accountIds, OpeningBalanceState $state): array
    {
        if ($accountIds === []) {
            return [];
        }

        return DB::table('journal_lines')
            ->whereIn('account_id', $accountIds)
            ->where('is_posted', true)
            ->where('entry_date', '<=', $state->asOf()->toDateString())
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(debit_cents - credit_cents) AS signed')
            ->pluck('signed', 'account_id')
            ->map(fn ($signed) => (int) $signed)
            ->all();
    }
}
