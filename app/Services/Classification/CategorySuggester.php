<?php

namespace App\Services\Classification;

use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Services\Classification\Support\DescriptionNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Deterministic "based on prior history" category suggester. Given a vendor
 * contact and/or a transaction description, it returns the account (and, where
 * available, tax code) the company has used for the same vendor/merchant before.
 * Local data only — no AI, no egress. Suggest-only: callers pre-fill a review
 * screen with the result; nothing posts on its strength.
 *
 * Priority, highest first:
 *   1. the contact's explicit default expense account (set by the user);
 *   2. the account most used on the contact's prior posted bills/expenses;
 *   3. the account a prior committed statement line with the same (normalized)
 *      description was categorized to.
 *
 * Every candidate is filtered to the accounts that may legitimately back a line
 * item (active, not an AR/AP/Undeposited control account) so a stale or invalid
 * suggestion never reaches the review <select>.
 */
class CategorySuggester
{
    private ?Company $company = null;

    /** @var array<int, Collection<int, int>> usable account-id sets, keyed by company */
    private array $usableCache = [];

    /**
     * Full deterministic chain for one transaction (the receipt path). Returns
     * null when nothing in the company's history fits.
     */
    public function suggest(int $companyId, ?int $contactId, ?string $description): ?CategorySuggestion
    {
        return $this->fromContact($companyId, $contactId)
            ?? $this->fromDescription($companyId, $description);
    }

    /**
     * The contact's explicit default, then the account most used on their prior
     * posted bills/expenses.
     */
    public function fromContact(int $companyId, ?int $contactId): ?CategorySuggestion
    {
        if ($contactId === null) {
            return null;
        }

        $contact = Contact::query()->where('company_id', $companyId)->whereKey($contactId)->first();

        if ($contact === null) {
            return null;
        }

        if ($contact->default_expense_account_id !== null
            && $this->isUsable($companyId, (int) $contact->default_expense_account_id)) {
            return new CategorySuggestion(
                accountId: (int) $contact->default_expense_account_id,
                taxCodeId: $contact->default_tax_code_id !== null ? (int) $contact->default_tax_code_id : null,
                confidence: 95,
                reason: __('Default category for :name.', ['name' => $contact->display_name]),
                source: CategorySuggestion::SOURCE_CONTACT_DEFAULT,
            );
        }

        $records = $this->contactHistoryRecords($companyId, $contactId);

        if ($records === []) {
            return null;
        }

        [$accountId, $taxCodeId, $count] = $this->rank($companyId, $records);

        if ($accountId === null) {
            return null;
        }

        return new CategorySuggestion(
            accountId: $accountId,
            taxCodeId: $taxCodeId,
            confidence: min(85, 60 + $count * 5),
            reason: __('You usually file :name here.', ['name' => $contact->display_name]),
            source: CategorySuggestion::SOURCE_HISTORY,
        );
    }

    /**
     * The account a prior committed statement line with the same (normalized)
     * description was categorized to.
     */
    public function fromDescription(int $companyId, ?string $description): ?CategorySuggestion
    {
        $normalized = DescriptionNormalizer::normalize($description);

        if ($normalized === '') {
            return null;
        }

        return $this->forDescriptions($companyId, [(string) $description])[$normalized] ?? null;
    }

    /**
     * Batched description lookup for a whole import: one query over the company's
     * committed statement lines, the most recent categorization winning for each
     * distinct (normalized) description.
     *
     * @param  list<string>  $rawDescriptions
     * @return array<string, CategorySuggestion> keyed by normalized description
     */
    public function forDescriptions(int $companyId, array $rawDescriptions): array
    {
        $wanted = [];
        foreach ($rawDescriptions as $description) {
            $normalized = DescriptionNormalizer::normalize($description);
            if ($normalized !== '') {
                $wanted[$normalized] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $usable = $this->usableAccountIds($companyId);

        $lines = BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('match_status', StatementLineMatchStatus::Created->value)
            ->whereNotNull('suggested_account_id')
            ->where('txn_date', '>=', $this->since($companyId))
            ->orderByDesc('txn_date')
            ->orderByDesc('id')
            ->limit((int) config('classification.description_history_limit', 1000))
            ->get(['suggested_account_id', 'description', 'txn_date']);

        $result = [];

        foreach ($lines as $line) {
            $normalized = DescriptionNormalizer::normalize($line->description);

            // First (most recent, given the ordering) categorization per merchant wins.
            if (! isset($wanted[$normalized]) || isset($result[$normalized])) {
                continue;
            }

            $accountId = (int) $line->suggested_account_id;

            if (! $usable->has($accountId)) {
                continue;
            }

            $result[$normalized] = new CategorySuggestion(
                accountId: $accountId,
                taxCodeId: null,
                confidence: 80,
                reason: __('Matches how you categorized ":desc" before.', [
                    'desc' => Str::limit((string) $line->description, 40),
                ]),
                source: CategorySuggestion::SOURCE_HISTORY,
            );
        }

        return $result;
    }

    /**
     * Prior posted, non-voided bills/expenses for the contact, flattened to one
     * record per line: {account_id, tax_code_id, date}, most recent first.
     *
     * @return list<array{account_id: int, tax_code_id: ?int, date: string}>
     */
    private function contactHistoryRecords(int $companyId, int $contactId): array
    {
        $maxRows = (int) config('classification.max_history_rows', 200);

        $expenses = Expense::query()
            ->where('company_id', $companyId)
            ->where('payee_contact_id', $contactId)
            ->whereNotNull('posted_at')
            ->whereNull('voided_at')
            ->where('expense_date', '>=', $this->since($companyId))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit($maxRows)
            ->with('lines:id,expense_id,account_id,tax_code_id')
            ->get(['id', 'expense_date']);

        $bills = Bill::query()
            ->where('company_id', $companyId)
            ->where('contact_id', $contactId)
            ->whereNotNull('posted_at')
            ->whereNull('voided_at')
            ->where('bill_date', '>=', $this->since($companyId))
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->limit($maxRows)
            ->with('lines:id,bill_id,account_id,tax_code_id')
            ->get(['id', 'bill_date']);

        $records = [];

        foreach ($expenses as $expense) {
            $date = CarbonImmutable::parse($expense->expense_date)->toDateString();
            foreach ($expense->lines as $line) {
                $records[] = [
                    'account_id' => (int) $line->account_id,
                    'tax_code_id' => $line->tax_code_id !== null ? (int) $line->tax_code_id : null,
                    'date' => $date,
                ];
            }
        }

        foreach ($bills as $bill) {
            $date = CarbonImmutable::parse($bill->bill_date)->toDateString();
            foreach ($bill->lines as $line) {
                $records[] = [
                    'account_id' => (int) $line->account_id,
                    'tax_code_id' => $line->tax_code_id !== null ? (int) $line->tax_code_id : null,
                    'date' => $date,
                ];
            }
        }

        usort($records, fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $records;
    }

    /**
     * Rank flattened history records: most-used account wins, ties broken by the
     * most recent occurrence; the winning account's most common tax code rides
     * along. Records are assumed pre-sorted most-recent first.
     *
     * @param  list<array{account_id: int, tax_code_id: ?int, date: string}>  $records
     * @return array{0: ?int, 1: ?int, 2: int} [accountId, taxCodeId, count]
     */
    private function rank(int $companyId, array $records): array
    {
        $usable = $this->usableAccountIds($companyId);
        $byAccount = [];

        foreach ($records as $index => $record) {
            $accountId = $record['account_id'];

            if (! $usable->has($accountId)) {
                continue;
            }

            if (! isset($byAccount[$accountId])) {
                $byAccount[$accountId] = ['count' => 0, 'first_index' => $index, 'tax' => []];
            }

            $byAccount[$accountId]['count']++;

            if ($record['tax_code_id'] !== null) {
                $taxId = $record['tax_code_id'];
                $byAccount[$accountId]['tax'][$taxId] = ($byAccount[$accountId]['tax'][$taxId] ?? 0) + 1;
            }
        }

        if ($byAccount === []) {
            return [null, null, 0];
        }

        uasort($byAccount, fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: $a['first_index'] <=> $b['first_index']);

        $winnerId = (int) array_key_first($byAccount);
        $winner = $byAccount[$winnerId];

        $taxCodeId = null;
        if ($winner['tax'] !== []) {
            arsort($winner['tax']);
            $taxCodeId = (int) array_key_first($winner['tax']);
        }

        return [$winnerId, $taxCodeId, (int) $winner['count']];
    }

    /**
     * The accounts that may legitimately back a line item for this company,
     * indexed by id for O(1) membership. Cached per company per instance.
     *
     * @return Collection<int, int>
     */
    private function usableAccountIds(int $companyId): Collection
    {
        return $this->usableCache[$companyId] ??= Account::query()
            ->where('company_id', $companyId)
            ->selectableForItemAccount()
            ->where('is_active', true)
            ->pluck('id')
            ->flip();
    }

    private function isUsable(int $companyId, int $accountId): bool
    {
        return $this->usableAccountIds($companyId)->has($accountId);
    }

    private function since(int $companyId): string
    {
        $days = (int) config('classification.history_days', 365);

        return $this->company($companyId)->currentDateTime()->subDays($days)->toDateString();
    }

    private function company(int $companyId): Company
    {
        if ($this->company === null || $this->company->id !== $companyId) {
            $this->company = Company::query()->findOrFail($companyId);
        }

        return $this->company;
    }
}
