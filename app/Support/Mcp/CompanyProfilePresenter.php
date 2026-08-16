<?php

namespace App\Support\Mcp;

use App\Mcp\Resources\CompanyProfileResource;
use App\Mcp\Tools\CompanyProfileTool;
use App\Models\Company;
use App\Services\Reporting\ReportCalculator;
use App\Support\Tax\FilingProfile;
use Carbon\CarbonImmutable;

/**
 * Renders a company's profile as plain text for the MCP server. Shared by
 * {@see CompanyProfileResource} and its companion
 * {@see CompanyProfileTool} so the two stay identical and the
 * formatting is tested once. Surfaces the fiscal-year start and the derived
 * current fiscal-year start/end dates, which an MCP client needs to frame
 * reporting windows correctly.
 */
class CompanyProfilePresenter
{
    /**
     * Human labels for the feature-flag columns, in display order.
     *
     * @var array<string, string>
     */
    private const FEATURE_LABELS = [
        'features_employees' => 'Employees',
        'features_payroll' => 'Payroll',
        'features_inventory' => 'Inventory',
        'features_fixed_assets' => 'Fixed assets',
        'features_estimates' => 'Estimates',
        'features_sales_orders' => 'Sales orders',
        'features_purchase_orders' => 'Purchase orders',
        'features_recurring_invoices' => 'Recurring invoices',
        'features_recurring_bills' => 'Recurring bills',
        'features_classes' => 'Classes',
        'features_locations' => 'Locations',
        'features_funds' => 'Funds',
        'features_budgets' => 'Budgets',
        'features_membership' => 'Membership',
        'features_fundraising' => 'Donations & grants',
    ];

    public function __construct(private ReportCalculator $calculator) {}

    public function render(Company $company): string
    {
        $today = $company->currentDateTime();
        $fyStart = $this->calculator->fiscalYearStart($company, $today);
        $fyEnd = $fyStart->addYear()->subDay();
        $startMonth = CarbonImmutable::create(2000, (int) $company->fiscal_year_start_month, 1)->format('F');

        $lines = [
            "Company profile for {$company->name}",
            '',
            'Identity',
            "  Name: {$company->name}",
        ];

        if (filled($company->legal_name)) {
            $lines[] = "  Legal name: {$company->legal_name}";
        }

        $lines[] = '  Organization type: '.($company->organization_type?->label() ?? 'Not specified');
        $lines[] = '  Legal structure: '.($company->resolvedLegalStructure()?->label() ?? '—');
        $lines[] = '  Industry: '.($company->industry?->label() ?? '—');

        $jurisdiction = $company->jurisdiction;
        $lines[] = '';
        $lines[] = 'Location';
        $lines[] = "  Country: {$jurisdiction->label()}";
        $lines[] = "  {$jurisdiction->regionLabel()}: ".($company->address_region ?: '—');
        $address = array_filter([
            $company->address_line1,
            $company->address_line2,
            $company->address_city,
            $company->address_postal_code,
        ]);
        $lines[] = '  Address: '.($address === [] ? '—' : implode(', ', $address));
        $lines[] = '  Timezone: '.($company->timezone ?: 'UTC');

        $lines[] = '';
        $lines[] = 'Currency & accounting';
        $lines[] = "  Home currency: {$company->currency_code}";
        $lines[] = '  Multi-currency: '.($company->isMulticurrencyEnabled() ? 'Enabled' : 'Disabled');
        $lines[] = '  Inventory costing: '.($company->costing_method?->label() ?? '—');

        $lines[] = '';
        $lines[] = 'Fiscal year';
        $lines[] = "  Starts: {$startMonth} (month {$company->fiscal_year_start_month})";
        $lines[] = "  Current fiscal year: {$fyStart->toFormattedDateString()} – {$fyEnd->toFormattedDateString()}";
        $lines[] = "  Today (company time): {$today->toFormattedDateString()}";
        if ($company->lock_date !== null) {
            $lines[] = "  Books locked on/before: {$company->lock_date->toFormattedDateString()}";
        }

        $lines[] = '';
        $lines[] = 'Tax & filing';
        $lines[] = '  Tax/registration number: '.($company->tax_number ?: '—');
        if (filled($company->charity_registration_number)) {
            $lines[] = "  Charity registration number: {$company->charity_registration_number}";
        }
        if ($company->organization_type?->isNonProfit()) {
            $lines[] = '  Contribution method: '.($company->contribution_method?->label() ?? 'Deferral (default)');
        }
        $lines = array_merge($lines, $this->filingLines($company));

        $lines[] = '';
        $lines[] = 'Enabled features: '.$this->enabledFeatures($company);

        return implode("\n", $lines);
    }

    /**
     * The entity-aware CRA filing profile (which returns apply).
     *
     * @return array<int, string>
     */
    private function filingLines(Company $company): array
    {
        $profile = FilingProfile::for($company);
        $forms = $profile->forms();

        if ($forms === []) {
            return ['  CRA filing: none (non-Canadian, or an organization type with no CRA return).'];
        }

        $lines = ['  CRA filing profile:'];
        foreach ($forms as $entry) {
            $form = $entry['form'];
            $primary = $entry['primary'] ? ' (primary)' : '';
            $lines[] = "    - {$form->code()} — {$form->label()}{$primary}";
        }
        if ($profile->mapsGifiCodes()) {
            $lines[] = '    GIFI line mapping applies (Schedule 100/125).';
        }

        return $lines;
    }

    private function enabledFeatures(Company $company): string
    {
        $enabled = [];
        foreach (self::FEATURE_LABELS as $column => $label) {
            if ($company->{$column}) {
                $enabled[] = $label;
            }
        }

        return $enabled === [] ? 'none' : implode(', ', $enabled);
    }
}
