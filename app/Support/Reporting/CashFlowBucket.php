<?php

namespace App\Support\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Models\Account;
use App\Models\ReportGroupSection;
use App\Models\ReportSection;

/**
 * Single source of truth for which cash-flow activity an account (or combined
 * line) belongs to on the indirect Statement of Cash Flows. Shared by the report,
 * the section config pages, and the {@see ReportSection::accepts()} /
 * {@see ReportGroupSection::accepts()} validation so they never disagree.
 *
 * Returns null for accounts that are NOT presented as their own activity line:
 *   - Bank (cash itself — it's what the statement explains)
 *   - Income / Expense (collapsed into the single "Net Income" operating line)
 *
 * Every other balance-sheet account maps to exactly one activity, so the indirect
 * statement always reconciles to the period change in cash.
 */
class CashFlowBucket
{
    /**
     * The activity an account is presented under, honoring a per-account
     * {@see Account::$cash_flow_activity} override when one is set.
     *
     * The override is respected ONLY when the account already maps to an
     * activity by type/subtype (non-null default). Accounts that are not their
     * own activity line — Bank (cash itself) and Income/Expense (collapsed into
     * Net Income) — stay excluded regardless of any stored override, which is
     * what keeps the indirect statement reconciling to the change in cash.
     *
     * @return 'operating'|'investing'|'financing'|null
     */
    public static function for(Account $account): ?string
    {
        $default = self::forValues($account->type, $account->subtype);

        if ($default === null) {
            return null;
        }

        return $account->cash_flow_activity?->value ?? $default;
    }

    /**
     * Activity from a bare type/subtype pair — used for combined report lines, which
     * carry the same type/subtype enums as accounts but aren't Account models.
     *
     * @return 'operating'|'investing'|'financing'|null
     */
    public static function forValues(AccountType $type, ?AccountSubtype $subtype): ?string
    {
        // Cash itself and all P&L accounts are not their own activity lines.
        if ($subtype === AccountSubtype::Bank) {
            return null;
        }

        if ($type === AccountType::Income || $type === AccountType::Expense) {
            return null;
        }

        return match ($subtype) {
            AccountSubtype::AccountsReceivable,
            AccountSubtype::UndepositedFunds,
            AccountSubtype::Inventory,
            AccountSubtype::CurrentAsset,
            AccountSubtype::AccountsPayable,
            AccountSubtype::CreditCard,
            AccountSubtype::TaxPayable,
            AccountSubtype::CurrentLiability => 'operating',
            AccountSubtype::FixedAsset,
            AccountSubtype::OtherAsset => 'investing',
            AccountSubtype::LongTermLiability,
            AccountSubtype::OtherLiability,
            AccountSubtype::Equity,
            AccountSubtype::RetainedEarnings,
            AccountSubtype::UnrestrictedNetAssets,
            AccountSubtype::RestrictedNetAssets,
            AccountSubtype::EndowmentNetAssets => 'financing',
            // Total fallback by type so every balance-sheet account is classified,
            // which is what keeps the statement reconciling.
            default => match ($type) {
                AccountType::Asset => 'operating',
                AccountType::Liability => 'operating',
                AccountType::Equity => 'financing',
                default => null,
            },
        };
    }

    /**
     * The activities in presentation order, keyed by group_key. Sourced from
     * {@see CashFlowActivity} so the override enum and the report never diverge.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (CashFlowActivity::cases() as $activity) {
            $labels[$activity->value] = $activity->label();
        }

        return $labels;
    }
}
