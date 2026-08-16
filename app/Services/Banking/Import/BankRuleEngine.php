<?php

namespace App\Services\Banking\Import;

use App\Enums\StatementLineMatchStatus;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use Illuminate\Support\Collection;

/**
 * Applies a company's active bank rules to the unmatched lines of an import,
 * setting a suggested contra account (and an explanatory reason) on each line
 * whose description matches a rule. Never touches the ledger — it only pre-fills
 * the "Add" account the user sees on review.
 */
class BankRuleEngine
{
    /**
     * @return int the number of lines a rule was applied to
     */
    public function apply(BankStatementImport $import): int
    {
        $rules = BankRule::query()
            ->where('company_id', $import->company_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $applied = 0;

        $lines = $import->lines()
            ->where('match_status', StatementLineMatchStatus::Unmatched->value)
            ->whereNull('suggested_account_id')
            ->get();

        foreach ($lines as $line) {
            $rule = $this->firstMatching($rules, $line);

            if ($rule === null) {
                continue;
            }

            $line->forceFill([
                'suggested_account_id' => $rule->action_account_id,
                'match_reason' => __('Categorized by rule ":name".', ['name' => $rule->name]),
            ])->save();

            $applied++;
        }

        return $applied;
    }

    /**
     * @param  Collection<int, BankRule>  $rules
     */
    private function firstMatching($rules, BankStatementLine $line): ?BankRule
    {
        foreach ($rules as $rule) {
            if ($rule->matchesDescription($line->description)) {
                return $rule;
            }
        }

        return null;
    }
}
