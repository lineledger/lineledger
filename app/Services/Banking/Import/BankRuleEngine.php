<?php

namespace App\Services\Banking\Import;

use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use Illuminate\Support\Collection;

/**
 * Applies a company's active bank rules to the unmatched lines of an import,
 * setting a suggested contra account (and payee, and an explanatory reason) on
 * each line whose description matches a rule. Never touches the ledger — it only
 * pre-fills what the user sees on review.
 *
 * Rules are evaluated by priority, then by how specific their match type is
 * (an "equals" or merchant-key rule beats a broad "contains" at the same
 * priority), then by age.
 */
class BankRuleEngine
{
    /**
     * @return int the number of lines a rule was applied to
     */
    public function apply(BankStatementImport $import): int
    {
        $rules = $this->activeRules((int) $import->company_id);

        if ($rules->isEmpty()) {
            return 0;
        }

        $applied = 0;

        $lines = $import->lines()
            ->where('match_status', StatementLineMatchStatus::Unmatched->value)
            ->whereNull('suggested_account_id')
            ->get();

        foreach ($lines as $line) {
            $rule = $this->firstMatching($rules, $line->description);

            if ($rule === null) {
                continue;
            }

            $line->forceFill([
                'suggested_account_id' => $rule->action_account_id,
                'suggested_contact_id' => $rule->action_contact_id ?? $line->suggested_contact_id,
                'suggestion_source' => StatementSuggestionSource::Rule->value,
                'match_reason' => __('Categorized by rule ":name".', ['name' => $rule->name]),
            ])->save();

            $applied++;
        }

        return $applied;
    }

    /**
     * The first active rule that matches a description, in evaluation order —
     * what "Always do this" checks before offering to create another rule.
     */
    public function firstMatchingFor(int $companyId, ?string $description): ?BankRule
    {
        return $this->firstMatching($this->activeRules($companyId), $description);
    }

    /**
     * Same as {@see firstMatchingFor()} for many descriptions with one rules
     * query, keyed as given (e.g. by line id). Keys with no match are omitted.
     *
     * @param  array<int|string, string|null>  $descriptionsByKey
     * @return array<int|string, BankRule>
     */
    public function firstMatchingForMany(int $companyId, array $descriptionsByKey): array
    {
        $rules = $this->activeRules($companyId);
        $matches = [];

        if ($rules->isEmpty()) {
            return $matches;
        }

        foreach ($descriptionsByKey as $key => $description) {
            $rule = $this->firstMatching($rules, $description);

            if ($rule !== null) {
                $matches[$key] = $rule;
            }
        }

        return $matches;
    }

    /**
     * Always read fresh: the engine may live inside a long-running worker or a
     * Livewire component that outlives a rule being created mid-request.
     *
     * @return Collection<int, BankRule>
     */
    private function activeRules(int $companyId): Collection
    {
        return BankRule::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->sort(fn (BankRule $a, BankRule $b): int => [$a->priority, $a->match_type->specificity(), $a->id]
                <=> [$b->priority, $b->match_type->specificity(), $b->id])
            ->values();
    }

    /**
     * @param  Collection<int, BankRule>  $rules
     */
    private function firstMatching(Collection $rules, ?string $description): ?BankRule
    {
        foreach ($rules as $rule) {
            if ($rule->matchesDescription($description)) {
                return $rule;
            }
        }

        return null;
    }
}
