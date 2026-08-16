<?php

namespace App\Actions\Accounting;

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Merge\AccountReferenceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merges a duplicate account (the loser) into a surviving account: every
 * reference in {@see AccountReferenceRegistry::columns()} is repointed to the
 * survivor, the survivor's cached balance is recomputed, and the loser is
 * deactivated and soft-deleted. Irreversible by design.
 *
 * Unlike the Save* actions (one entity + a data array), this is a two-entity
 * operation — handle(loser, survivor) — following the ReverseJournalEntry
 * precedent of an Action whose subject is a relationship between two records.
 *
 * The company lock_date deliberately does NOT block a merge: no GL amounts or
 * dates change — posted journal lines keep their debits/credits and entry
 * dates and only their account grouping moves — so locked-period totals are
 * unaffected (trial balance, P&L and balance sheet stay identical at the
 * type level; only per-account detail regroups).
 */
final class MergeAccounts
{
    public function __construct(protected AccountingAuditRecorder $recorder) {}

    public function handle(Account $loser, Account $survivor): Account
    {
        if ($loser->id === $survivor->id) {
            throw ValidationException::withMessages([
                'merge' => __('An account cannot be merged into itself.'),
            ]);
        }

        if ($loser->company_id !== $survivor->company_id
            || (app()->bound('current_company') && $loser->company_id !== app('current_company')->id)) {
            throw ValidationException::withMessages([
                'merge' => __('Both accounts must belong to the current company.'),
            ]);
        }

        if (! $survivor->is_active || $survivor->trashed()) {
            throw ValidationException::withMessages([
                'merge' => __('The surviving account must be active.'),
            ]);
        }

        // Merging INTO a system survivor is allowed; merging one away is not.
        if ($loser->is_system) {
            throw ValidationException::withMessages([
                'merge' => __('System accounts cannot be merged away.'),
            ]);
        }

        if ($loser->subtype !== $survivor->subtype) {
            throw ValidationException::withMessages([
                'merge' => __('Accounts must have the same type to be merged.'),
            ]);
        }

        if ($loser->currency_code !== $survivor->currency_code) {
            throw ValidationException::withMessages([
                'merge' => __('Accounts must have the same currency to be merged.'),
            ]);
        }

        if ($this->survivorDescendsFromLoser($loser, $survivor)) {
            throw ValidationException::withMessages([
                'merge' => __('An account cannot be merged into one of its own sub-accounts. Choose a different surviving account.'),
            ]);
        }

        // budget_lines is unique on (budget_id, account_id) — a budget holding
        // lines for BOTH accounts would collide on repoint, and silently
        // dropping or summing budget figures is not the merge's call to make.
        $conflictingBudgets = DB::table('budget_lines')
            ->join('budgets', 'budgets.id', '=', 'budget_lines.budget_id')
            ->where('budget_lines.account_id', $loser->id)
            ->whereIn('budget_lines.budget_id', function ($query) use ($survivor) {
                $query->select('budget_id')
                    ->from('budget_lines')
                    ->where('account_id', $survivor->id);
            })
            ->distinct()
            ->pluck('budgets.name');

        if ($conflictingBudgets->isNotEmpty()) {
            throw ValidationException::withMessages([
                'merge' => __('Both accounts have lines in the same budget(s): :budgets. Remove one account\'s lines from those budgets first.', [
                    'budgets' => $conflictingBudgets->implode(', '),
                ]),
            ]);
        }

        if (in_array($loser->subtype, [AccountSubtype::Bank, AccountSubtype::CreditCard], true)
            && DB::table('bank_reconciliations')->where('account_id', $loser->id)->exists()) {
            throw ValidationException::withMessages([
                'merge' => __('This account has reconciliation history and cannot be merged away.'),
            ]);
        }

        return DB::transaction(function () use ($loser, $survivor): Account {
            $moved = [];

            AuditMute::silence(function () use ($loser, $survivor, &$moved): void {
                // report_group_account_maps is unique on (report_group_id, account_id):
                // where a group already maps the survivor, the loser's row is
                // redundant after the merge — drop it before the blind UPDATE.
                // Materialized first: MySQL (error 1093) cannot delete from a
                // table referenced in the same statement's subquery.
                $survivorGroupIds = DB::table('report_group_account_maps')
                    ->where('account_id', $survivor->id)
                    ->pluck('report_group_id');

                if ($survivorGroupIds->isNotEmpty()) {
                    DB::table('report_group_account_maps')
                        ->where('account_id', $loser->id)
                        ->whereIn('report_group_id', $survivorGroupIds)
                        ->delete();
                }

                foreach (AccountReferenceRegistry::columns() as $ref) {
                    $count = DB::table($ref['table'])
                        ->where($ref['column'], $loser->id)
                        ->update([$ref['column'] => $survivor->id]);

                    if ($count > 0) {
                        $moved["{$ref['table']}.{$ref['column']}"] = $count;
                    }
                }

                $survivor->recomputeBalance();

                $loser->forceFill(['is_active' => false, 'balance_cents' => 0])->saveQuietly();
                $loser->delete();
            });

            $this->recorder->record($loser->company_id, AuditAction::AccountMerged, $loser, [
                'merged_into' => ['id' => $survivor->id, 'name' => $survivor->name, 'code' => $survivor->code],
                'moved' => $moved,
            ]);

            $this->recorder->record($survivor->company_id, AuditAction::AccountMerged, $survivor, [
                'absorbed' => ['id' => $loser->id, 'name' => $loser->name, 'code' => $loser->code],
                'moved' => $moved,
            ]);

            return $survivor->refresh();
        });
    }

    /**
     * Walk the survivor's parent chain; if the loser appears, the merge would
     * orphan the survivor (its ancestor is about to be deleted) or create a
     * parent cycle via the registry's accounts.parent_id repoint.
     */
    private function survivorDescendsFromLoser(Account $loser, Account $survivor): bool
    {
        $seen = [];
        $parentId = $survivor->parent_id;

        while ($parentId !== null && ! isset($seen[$parentId])) {
            if ((int) $parentId === (int) $loser->id) {
                return true;
            }

            $seen[$parentId] = true;
            $parentId = Account::withoutGlobalScopes()
                ->whereKey($parentId)
                ->value('parent_id');
        }

        return false;
    }
}
