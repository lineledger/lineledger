<?php

namespace App\Support\Reporting;

use App\Enums\OrganizationType;
use App\Models\Company;

/**
 * The vocabulary of a financial statement, resolved from a company's
 * {@see OrganizationType}. The single source of truth for the words that differ
 * between for-profit and non-profit presentation, so every surface — the
 * on-screen Balance Sheet / Income Statement, their PDF and XLSX downloads, the
 * report charts, and the combined multi-company statements — reads the same.
 *
 * For-profit entities are labelled to match the way a reader of their statements
 * would expect ("Owner's Equity", "Shareholders' Equity", "Net Income");
 * non-profits report "Net Assets" and the "Excess (deficiency) of revenue over
 * expenses". The non-profit wording mirrors the dedicated ASNPO statements
 * (Statement of Financial Position / Operations / Changes in Net Assets) verbatim
 * so the standard and dedicated reports never drift.
 *
 * Relabelling only: the underlying accounts keep their AccountType classification
 * and the numbers are untouched.
 */
final readonly class StatementLabels
{
    private ?OrganizationType $type;

    /**
     * Accepts the resolved enum or its backing string. The Company model casts
     * organization_type to OrganizationType at runtime, but static analysis sees
     * the method-based cast as a plain string, so normalize either form here.
     */
    public function __construct(OrganizationType|string|null $type)
    {
        $this->type = $type instanceof OrganizationType
            ? $type
            : ($type !== null ? OrganizationType::tryFrom($type) : null);
    }

    public static function for(Company $company): self
    {
        return new self($company->organization_type);
    }

    public static function forType(?OrganizationType $type): self
    {
        return new self($type);
    }

    /**
     * Labels for a consolidated statement spanning several companies. The
     * non-profit vocabulary applies only when every member company is a
     * non-profit; a mixed (or empty) group keeps the generic for-profit wording,
     * since there is no single entity type to speak for.
     *
     * @param  iterable<Company>  $companies
     */
    public static function forGroup(iterable $companies): self
    {
        $any = false;
        foreach ($companies as $company) {
            $any = true;
            if (! self::for($company)->isNonProfit()) {
                return new self(OrganizationType::Other);
            }
        }

        return new self($any ? OrganizationType::NonProfit : OrganizationType::Other);
    }

    public function isNonProfit(): bool
    {
        return $this->type?->isNonProfit() ?? false;
    }

    /**
     * The equity section heading, named for the entity type: "Net Assets" for
     * non-profits, "Owner's Equity" / "Partners' Equity" / "Shareholders' Equity"
     * for the for-profit tiers, "Equity" otherwise.
     */
    public function equityHeading(): string
    {
        return $this->type?->equitySectionLabel() ?? __('Equity');
    }

    /** The short equity word for inline totals and chart series. */
    public function equityShort(): string
    {
        return $this->isNonProfit() ? __('Net Assets') : __('Equity');
    }

    /** Chart axis label pairing liabilities with the equity word. */
    public function liabilitiesAndEquity(): string
    {
        return $this->isNonProfit() ? __('Liabilities & Net Assets') : __('Liabilities & Equity');
    }

    /** The accounting-identity chart title. */
    public function accountingEquation(): string
    {
        return $this->isNonProfit()
            ? __('Assets = Liabilities + Net Assets')
            : __('Assets = Liabilities + Equity');
    }

    /** The grand-total line at the foot of the balance sheet. */
    public function totalLiabilitiesAndEquity(): string
    {
        return $this->isNonProfit()
            ? __('Total Liabilities & Net Assets')
            : __('Total Liabilities & Equity');
    }

    /** The accumulated-surplus equity line / subtype label. */
    public function retainedEarnings(): string
    {
        return $this->isNonProfit() ? __('Unrestricted Net Assets') : __('Retained Earnings');
    }

    /** The prior-years roll-forward row beneath {@see retainedEarnings()}. */
    public function retainedEarningsPriorRow(): string
    {
        return $this->isNonProfit()
            ? __('Net assets (prior years)')
            : __('Retained earnings (prior years)');
    }

    /** The bottom-line result of operations. */
    public function netIncome(): string
    {
        return $this->isNonProfit()
            ? __('Excess (deficiency) of revenue over expenses')
            : __('Net Income');
    }

    /** The year-to-date result of operations carried onto the balance sheet. */
    public function netIncomeYtd(): string
    {
        return $this->isNonProfit()
            ? __('Excess (deficiency) of revenue over expenses')
            : __('Net income (YTD)');
    }

    /** Revenue less cost of goods sold. */
    public function grossProfit(): string
    {
        return $this->isNonProfit() ? __('Gross surplus') : __('Gross Profit');
    }

    /** The income-statement waterfall chart title. */
    public function profitBridge(): string
    {
        return $this->isNonProfit() ? __('Surplus bridge') : __('Profit bridge');
    }
}
