<?php

namespace App\Services\Banking\Import;

use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Services\Classification\CategorySuggester;
use App\Services\Classification\Contracts\TransactionClassifier;
use App\Services\Classification\Support\DescriptionNormalizer;
use Illuminate\Support\Collection;

/**
 * Pre-fills a suggested category (`suggested_account_id` + `match_reason`) on an
 * import's still-uncategorized lines, in descending order of confidence:
 *
 *   1. the company's explicit bank rules (existing, user-authored);
 *   2. how the same merchant was categorized before (deterministic history);
 *   3. an AI guess for merchants with no history (gated, batched).
 *
 * Each step only touches lines that are still Unmatched with no suggestion, so a
 * higher-priority source is never overwritten and re-running the pipeline is
 * idempotent. Suggest-only — nothing is posted; the user confirms on review.
 */
final class StatementSuggestionPipeline
{
    public function __construct(
        private readonly BankRuleEngine $rules,
        private readonly CategorySuggester $history,
        private readonly TransactionClassifier $ai,
    ) {}

    public function fill(BankStatementImport $import): void
    {
        $this->rules->apply($import);
        $this->applyHistory($import);
        $this->applyAi($import);
    }

    private function applyHistory(BankStatementImport $import): void
    {
        $lines = $this->uncategorizedLines($import);

        if ($lines->isEmpty()) {
            return;
        }

        $suggestions = $this->history->forDescriptions(
            (int) $import->company_id,
            $lines->pluck('description')->map(fn ($d): string => trim((string) $d))->filter()->unique()->values()->all(),
        );

        if ($suggestions === []) {
            return;
        }

        foreach ($lines as $line) {
            $suggestion = $suggestions[DescriptionNormalizer::normalize($line->description)] ?? null;

            if ($suggestion === null) {
                continue;
            }

            $line->forceFill([
                'suggested_account_id' => $suggestion->accountId,
                'match_reason' => $suggestion->reason,
            ])->save();
        }
    }

    private function applyAi(BankStatementImport $import): void
    {
        if (! $this->ai->isEnabled()) {
            return;
        }

        $company = Company::query()->find($import->company_id);

        if ($company === null || ! $company->inboxOcrEnabled()) {
            return;
        }

        $lines = $this->uncategorizedLines($import);

        if ($lines->isEmpty()) {
            return;
        }

        $descriptions = $lines->pluck('description')->map(fn ($d): string => trim((string) $d))->filter()->unique()->values()->all();
        $accounts = $this->selectableAccounts((int) $import->company_id);

        if ($descriptions === [] || $accounts === []) {
            return;
        }

        $mapping = $this->ai->classify(
            $descriptions,
            array_map(fn (array $a): array => ['code' => $a['code'], 'name' => $a['name']], $accounts),
        );

        if ($mapping === []) {
            return;
        }

        $accountIdByCode = [];
        foreach ($accounts as $account) {
            $accountIdByCode[$account['code']] = $account['id'];
        }

        foreach ($lines as $line) {
            $code = $mapping[trim((string) $line->description)] ?? null;
            $accountId = $code !== null ? ($accountIdByCode[$code] ?? null) : null;

            if ($accountId === null) {
                continue;
            }

            $line->forceFill([
                'suggested_account_id' => $accountId,
                'match_reason' => __('Suggested by AI — please confirm.'),
            ])->save();
        }
    }

    /**
     * @return Collection<int, BankStatementLine>
     */
    private function uncategorizedLines(BankStatementImport $import): Collection
    {
        return $import->lines()
            ->where('match_status', StatementLineMatchStatus::Unmatched->value)
            ->whereNull('suggested_account_id')
            ->get();
    }

    /**
     * The company's selectable, active line-item accounts.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function selectableAccounts(int $companyId): array
    {
        return Account::query()
            ->where('company_id', $companyId)
            ->selectableForItemAccount()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a): array => ['id' => (int) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name])
            ->all();
    }
}
