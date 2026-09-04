<?php

namespace App\Support\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Models\Account;
use App\Models\ReportGroupLine;
use App\Models\ReportGroupSection;
use App\Models\ReportSection;

/**
 * Single source of truth for which cash-flow activity an account (or combined
 * line) belongs to on the indirect Statement of Cash Flows. Shared by the report,
 * the section config pages, and the {@see ReportSection::accepts()} /
 * {@see ReportGroupSection::accepts()} validation so they never disagree.
 *
 * Both an {@see Account} and a combined {@see ReportGroupLine} may carry a
 * per-row `cash_flow_activity` override; {@see for()} and {@see forLine()} apply
 * it under the same rule.
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
     * The activity a combined report-group line is presented under, honoring the
     * line's own {@see ReportGroupLine::$cash_flow_activity} override under the
     * same rule as {@see for()}: only a line that already maps to an activity by
     * type/subtype may be re-routed; Bank and P&L lines stay excluded.
     *
     * @return 'operating'|'investing'|'financing'|null
     */
    public static function forLine(ReportGroupLine $line): ?string
    {
        $default = self::forValues($line->type, $line->subtype);

        if ($default === null) {
            return null;
        }

        return $line->cash_flow_activity?->value ?? $default;
    }

    /**
     * Normalize a requested override against the type/subtype default: null when
     * the row has no activity of its own (Bank / P&L), when the value isn't an
     * activity, or when it merely restates the default — so only real re-routes
     * are stored and "override set" always means "differs from the default".
     *
     * @return 'operating'|'investing'|'financing'|null
     */
    public static function normalizeOverride(AccountType $type, ?AccountSubtype $subtype, ?string $activity): ?string
    {
        $default = self::forValues($type, $subtype);

        if ($default === null || $activity === null || CashFlowActivity::tryFrom($activity) === null) {
            return null;
        }

        return $activity === $default ? null : $activity;
    }

    /**
     * Activity from a bare type/subtype pair — the override-blind default, used
     * wherever the row's own override must be ignored (e.g. deciding whether a
     * row may carry one at all).
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
