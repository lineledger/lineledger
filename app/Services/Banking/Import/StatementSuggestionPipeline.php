<?php

namespace App\Services\Banking\Import;

use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Classification\CategorySuggester;
use App\Services\Classification\Contracts\TransactionClassifier;
use App\Services\Classification\Support\DescriptionNormalizer;
use Illuminate\Support\Collection;

/**
 * Pre-fills a suggested category (`suggested_account_id`), payee
 * (`suggested_contact_id`), open bill (`suggested_bill_id`) and a `match_reason`
 * on an import's still-uncategorized lines, in descending order of confidence:
 *
 *   1. the company's explicit bank rules (existing, user-authored);
 *   2. how the same merchant was categorized before (deterministic history —
 *      exact description, then the same merchant key);
 *   3. an open vendor bill for exactly this amount (vendor-scoped when the
 *      payee is known, else only when unambiguous company-wide);
 *   4. the payee's default expense account, for lines with a payee but no account;
 *   5. an AI guess for merchants with no history (gated, batched).
 *
 * Each step only fills what is still empty on Unmatched lines, so a
 * higher-priority source is never overwritten and re-running the pipeline is
 * idempotent. Suggest-only — nothing is posted; the user confirms on review.
 */
final class StatementSuggestionPipeline
{
    public function __construct(
        private readonly BankRuleEngine $rules,
        private readonly CategorySuggester $history,
        private readonly OpenBillMatcher $bills,
        private readonly TransactionClassifier $ai,
    ) {}

    public function fill(BankStatementImport $import): void
    {
        $this->rules->apply($import);
        $this->applyHistory($import);
        $this->applyOpenBills($import);
        $this->applyContactDefaults($import);
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
            (int) $import->account_id,
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
                'suggested_contact_id' => $suggestion->contactId ?? $line->suggested_contact_id,
                'suggested_tax_code_id' => $line->isOutflow() ? ($line->suggested_tax_code_id ?? $suggestion->taxCodeId) : null,
                'suggested_secondary_tax_code_id' => $line->isOutflow() ? ($line->suggested_secondary_tax_code_id ?? $suggestion->secondaryTaxCodeId) : null,
                'suggestion_source' => StatementSuggestionSource::History->value,
                'match_reason' => $suggestion->reason,
            ])->save();
        }
    }

    /**
     * Offer the open bill an outflow appears to settle. With a known payee the
     * search is vendor-scoped; without one, only a company-wide match that is
     * unambiguous (one bill for that amount) is offered, and it also names the
     * payee. Keeps whatever account/source an earlier layer set.
     */
    private function applyOpenBills(BankStatementImport $import): void
    {
        $lines = $import->lines()
            ->where('match_status', StatementLineMatchStatus::Unmatched->value)
            ->whereNull('suggested_bill_id')
            ->whereNull('suggested_bill_allocations')
            ->where('amount_cents', '<', 0)
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $offers = $this->bills->forLines($lines, [], allowCompanyWide: true);

        foreach ($lines as $line) {
            $candidates = $offers[$line->id] ?? null;

            if ($candidates === null || $candidates->count() !== 1) {
                continue;
            }

            /** @var Bill $bill */
            $bill = $candidates->first();

            $reason = __('Matches open bill :no from :vendor for the same amount.', [
                'no' => $bill->bill_no ?: ($bill->vendor_reference ?: '#'.$bill->id),
                'vendor' => $bill->contact->display_name ?? __('vendor'),
            ]);

            $line->forceFill([
                'suggested_bill_id' => $bill->id,
                'suggested_contact_id' => $line->suggested_contact_id ?? $bill->contact_id,
                'suggestion_source' => $line->suggestion_source->value ?? StatementSuggestionSource::OpenBill->value,
                'match_reason' => trim(($line->match_reason ? $line->match_reason.' ' : '').$reason),
            ])->save();
        }

        // No single bill matched but the payee is known: offer the exact-sum set
        // of their open bills, when there is one (vendor-scoped only).
        foreach ($lines as $line) {
            if ($line->suggested_bill_id !== null || $line->suggested_contact_id === null) {
                continue;
            }

            $allocation = $this->bills->allocationFor($line, (int) $line->suggested_contact_id);

            if ($allocation === null) {
                continue;
            }

            $vendor = Contact::withoutGlobalScopes()->find($line->suggested_contact_id)->display_name ?? __('vendor');
            $total = array_sum(array_column($allocation, 'amount_cents'));
            $reason = __('Matches :count open bills from :vendor totalling :amount.', [
                'count' => count($allocation),
                'vendor' => $vendor,
                'amount' => number_format($total / 100, 2),
            ]);

            $line->forceFill([
                'suggested_bill_allocations' => $allocation,
                'suggestion_source' => $line->suggestion_source->value ?? StatementSuggestionSource::OpenBill->value,
                'match_reason' => trim(($line->match_reason ? $line->match_reason.' ' : '').$reason),
            ])->save();
        }
    }

    /**
     * A line whose payee is known but whose account (or, for money out, tax
     * code) is not gets the payee's default expense account and tax code (or
     * their most-used ones). Only what is still empty is filled.
     */
    private function applyContactDefaults(BankStatementImport $import): void
    {
        $lines = $import->lines()
            ->where('match_status', StatementLineMatchStatus::Unmatched->value)
            ->whereNotNull('suggested_contact_id')
            ->where(fn ($q) => $q->whereNull('suggested_account_id')
                ->orWhere(fn ($q2) => $q2->whereNull('suggested_tax_code_id')->where('amount_cents', '<', 0)))
            ->get();

        foreach ($lines as $line) {
            $suggestion = $this->history->fromContact((int) $import->company_id, (int) $line->suggested_contact_id);
            $changes = [];

            if ($suggestion !== null && $line->suggested_account_id === null) {
                $changes['suggested_account_id'] = $suggestion->accountId;
                $changes['suggestion_source'] = $line->suggestion_source->value ?? StatementSuggestionSource::ContactDefault->value;
                $changes['match_reason'] = trim(($line->match_reason ? $line->match_reason.' ' : '').$suggestion->reason);
            }

            if ($line->isOutflow() && $line->suggested_tax_code_id === null) {
                // The tax code can come with the account suggestion, or stand alone
                // as the contact's default when they have no default account yet.
                $taxCodeId = $suggestion->taxCodeId
                    ?? $this->history->defaultTaxCodeFor((int) $import->company_id, (int) $line->suggested_contact_id);

                if ($taxCodeId !== null) {
                    $changes['suggested_tax_code_id'] = $taxCodeId;
                    $changes['suggested_secondary_tax_code_id'] = $suggestion?->secondaryTaxCodeId;
                }
            }

            if ($changes !== []) {
                $line->forceFill($changes)->save();
            }
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
                'suggestion_source' => $line->suggestion_source->value ?? StatementSuggestionSource::Ai->value,
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
