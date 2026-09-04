<?php

namespace App\Services\Classification;

use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\TaxCode;
use App\Services\Classification\Support\DescriptionNormalizer;
use App\Services\Classification\Support\MerchantKey;
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
 *      description was categorized to — exact text first, then the same
 *      merchant key ({@see MerchantKey}: reference numbers, dates and amounts
 *      stripped), carrying the payee that line was recorded against.
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

    /** @var array<int, Collection<int, int>> active purchase tax-code id sets, keyed by company */
    private array $usableTaxCache = [];

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
                taxCodeId: $this->usableTaxCodeId($companyId, $contact->default_tax_code_id),
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
            taxCodeId: $this->usableTaxCodeId($companyId, $taxCodeId),
            confidence: min(85, 60 + $count * 5),
            reason: __('You usually file :name here.', ['name' => $contact->display_name]),
            source: CategorySuggestion::SOURCE_HISTORY,
        );
    }

    /**
     * The contact's explicit default purchase tax code, when it is still an
     * active purchase code — independent of whether they have a default account.
     */
    public function defaultTaxCodeFor(int $companyId, ?int $contactId): ?int
    {
        if ($contactId === null) {
            return null;
        }

        $taxCodeId = Contact::query()
            ->where('company_id', $companyId)
            ->whereKey($contactId)
            ->value('default_tax_code_id');

        return $this->usableTaxCodeId($companyId, $taxCodeId);
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
     * Batched description lookup for a whole import: one bounded query over the
     * company's categorized statement lines, then two passes over it —
     *
     *   1. exact (normalized) description equality;
     *   2. the same merchant key, for descriptions still unresolved — so a new
     *      reference number or date suffix does not break the memory.
     *
     * Within a pass the most recent categorization wins, preferring one made on
     * the same bank account when $accountId is given. Each suggestion carries
     * the payee that prior line was recorded against (when it still exists and
     * is active) and the tax code from its expense, when there was one.
     *
     * @param  list<string>  $rawDescriptions
     * @return array<string, CategorySuggestion> keyed by normalized description
     */
    public function forDescriptions(int $companyId, array $rawDescriptions, ?int $accountId = null): array
    {
        $wantedExact = [];
        /** @var array<string, array<string, true>> merchant key => normalized descriptions */
        $wantedFuzzy = [];

        foreach ($rawDescriptions as $description) {
            $normalized = DescriptionNormalizer::normalize($description);

            if ($normalized === '') {
                continue;
            }

            $wantedExact[$normalized] = true;

            $key = MerchantKey::from($normalized);

            if (MerchantKey::isUsable($key)) {
                $wantedFuzzy[$key][$normalized] = true;
            }
        }

        if ($wantedExact === []) {
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
            ->get(['id', 'account_id', 'suggested_account_id', 'suggested_contact_id', 'created_journal_entry_id', 'description', 'txn_date']);

        /** @var array<string, array{same: ?BankStatementLine, any: ?BankStatementLine}> $exact */
        $exact = [];
        /** @var array<string, array{same: ?BankStatementLine, any: ?BankStatementLine}> $fuzzy */
        $fuzzy = [];

        foreach ($lines as $line) {
            if (! $usable->has((int) $line->suggested_account_id)) {
                continue;
            }

            $normalized = DescriptionNormalizer::normalize($line->description);
            $sameAccount = $accountId !== null && (int) $line->account_id === $accountId;

            if (isset($wantedExact[$normalized])) {
                $this->rememberCandidate($exact, $normalized, $line, $sameAccount);
            }

            if ($wantedFuzzy !== []) {
                $key = MerchantKey::from($normalized);

                if ($key !== '' && isset($wantedFuzzy[$key])) {
                    $this->rememberCandidate($fuzzy, $key, $line, $sameAccount);
                }
            }
        }

        /** @var array<string, array{0: BankStatementLine, 1: string}> $chosen normalized => [line, source] */
        $chosen = [];

        foreach ($exact as $normalized => $slot) {
            $chosen[$normalized] = [$slot['same'] ?? $slot['any'], CategorySuggestion::SOURCE_HISTORY];
        }

        foreach ($wantedFuzzy as $key => $normalizeds) {
            if (! isset($fuzzy[$key])) {
                continue;
            }

            $line = $fuzzy[$key]['same'] ?? $fuzzy[$key]['any'];

            foreach (array_keys($normalizeds) as $normalized) {
                $chosen[$normalized] ??= [$line, CategorySuggestion::SOURCE_FUZZY_HISTORY];
            }
        }

        if ($chosen === []) {
            return [];
        }

        $validContacts = $this->activeContactIds($companyId, array_map(fn (array $c) => $c[0]->suggested_contact_id, $chosen));
        $taxByEntry = $this->taxCodeByEntry($companyId, array_map(fn (array $c) => $c[0]->created_journal_entry_id, $chosen));

        $result = [];

        foreach ($chosen as $normalized => [$line, $source]) {
            $contactId = $line->suggested_contact_id !== null && $validContacts->has((int) $line->suggested_contact_id)
                ? (int) $line->suggested_contact_id
                : null;
            [$taxCodeId, $secondaryTaxCodeId] = $line->created_journal_entry_id !== null
                ? ($taxByEntry[(int) $line->created_journal_entry_id] ?? [null, null])
                : [null, null];
            $desc = Str::limit((string) $line->description, 40);

            $result[$normalized] = new CategorySuggestion(
                accountId: (int) $line->suggested_account_id,
                taxCodeId: $this->usableTaxCodeId($companyId, $taxCodeId),
                confidence: $source === CategorySuggestion::SOURCE_HISTORY ? 80 : 70,
                reason: $source === CategorySuggestion::SOURCE_HISTORY
                    ? __('Matches how you categorized ":desc" before.', ['desc' => $desc])
                    : __('Looks like ":desc", which you filed before.', ['desc' => $desc]),
                source: $source,
                contactId: $contactId,
                secondaryTaxCodeId: $this->usableTaxCodeId($companyId, $secondaryTaxCodeId),
            );
        }

        return $result;
    }

    /**
     * Keep the most recent candidate per key, and separately the most recent one
     * made on the same bank account (lines arrive most-recent first).
     *
     * @param  array<string, array{same: ?BankStatementLine, any: ?BankStatementLine}>  $slots
     */
    private function rememberCandidate(array &$slots, string $key, BankStatementLine $line, bool $sameAccount): void
    {
        $slots[$key] ??= ['same' => null, 'any' => null];

        if ($slots[$key]['any'] === null) {
            $slots[$key]['any'] = $line;
        }

        if ($sameAccount && $slots[$key]['same'] === null) {
            $slots[$key]['same'] = $line;
        }
    }

    /**
     * The given contact ids that still exist (not merged away / trashed) and are
     * active, as a flipped set for O(1) membership.
     *
     * @param  array<string, int|null>  $ids
     * @return Collection<int, int>
     */
    private function activeContactIds(int $companyId, array $ids): Collection
    {
        $ids = collect($ids)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Contact::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->flip();
    }

    /**
     * For lines recorded as an Expense, the primary and secondary tax codes on
     * its first line — what the same payee was taxed at last time.
     *
     * @param  array<string, int|null>  $entryIds
     * @return array<int, array{0: int|null, 1: int|null}>
     */
    private function taxCodeByEntry(int $companyId, array $entryIds): array
    {
        $entryIds = collect($entryIds)->filter()->unique()->values();

        if ($entryIds->isEmpty()) {
            return [];
        }

        $byEntry = [];

        foreach (Expense::query()
            ->where('company_id', $companyId)
            ->whereIn('journal_entry_id', $entryIds)
            ->with('lines:id,expense_id,tax_code_id,secondary_tax_code_id')
            ->get(['id', 'journal_entry_id']) as $expense) {
            $first = $expense->lines->first();
            $byEntry[(int) $expense->journal_entry_id] = [
                $first?->tax_code_id !== null ? (int) $first->tax_code_id : null,
                $first?->secondary_tax_code_id !== null ? (int) $first->secondary_tax_code_id : null,
            ];
        }

        return $byEntry;
    }

    /**
     * A tax code id only if it is still an active purchase code for the company;
     * a stale or sales-only code is dropped rather than suggested.
     */
    private function usableTaxCodeId(int $companyId, mixed $taxCodeId): ?int
    {
        if ($taxCodeId === null || $taxCodeId === '') {
            return null;
        }

        return $this->usableTaxCodeIds($companyId)->has((int) $taxCodeId) ? (int) $taxCodeId : null;
    }

    /**
     * @return Collection<int, int>
     */
    private function usableTaxCodeIds(int $companyId): Collection
    {
        return $this->usableTaxCache[$companyId] ??= TaxCode::query()
            ->where('company_id', $companyId)
            ->usableForPurchases()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->flip();
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
