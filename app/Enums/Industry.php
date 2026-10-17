<?php

namespace App\Enums;

/**
 * The industry a company operates in, chosen during the setup wizard. Drives
 * which industry-specific operating accounts are layered on top of the
 * jurisdiction core when seeding the chart of accounts (see ChartTemplateBuilder).
 * Stored on the company for reference; does not affect posting behaviour.
 */
enum Industry: string
{
    case General = 'general';
    case Contractor = 'contractor';
    case NonProfit = 'non_profit';
    case Manufacturing = 'manufacturing';
    case Retail = 'retail';
    case ProfessionalServices = 'professional_services';
    case HealthWellness = 'health_wellness';
    case Restaurant = 'restaurant';
    case RealEstate = 'real_estate';
    case Freelancer = 'freelancer';

    public function label(): string
    {
        return match ($this) {
            self::General => __('General business'),
            self::Contractor => __('Contractor / Construction'),
            self::NonProfit => __('Non-profit'),
            self::Manufacturing => __('Manufacturing'),
            self::Retail => __('Retail'),
            self::ProfessionalServices => __('Professional services'),
            self::HealthWellness => __('Health & Wellness'),
            self::Restaurant => __('Restaurant / Food & Beverage'),
            self::RealEstate => __('Real estate / Property management'),
            self::Freelancer => __('Freelancer / Creative'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::General => __('A balanced starter chart that fits most small businesses.'),
            self::Contractor => __('Job costing with materials, subcontractors, labour, and holdbacks.'),
            self::NonProfit => __('Donations, grants, program and fundraising tracking with net assets.'),
            self::Manufacturing => __('Raw materials, work in process, finished goods, and overhead.'),
            self::Retail => __('Merchandise sales, cost of goods, shrinkage, and card fees.'),
            self::ProfessionalServices => __('Service fees, retainers, and reimbursable project costs.'),
            self::HealthWellness => __('Appointment fees, retail product, prepaid packages, gift cards, and tips.'),
            self::Restaurant => __('Food and beverage sales, tips, food cost, and kitchen supplies.'),
            self::RealEstate => __('Commissions, rental income, security deposits, and trust funds held.'),
            self::Freelancer => __('Service income, client deposits, software, and equipment for solo creatives.'),
        };
    }

    /**
     * The modules pre-enabled on the wizard's Features step for this industry —
     * a starting suggestion the owner can still override. Keys map to the
     * company's feature_* columns; any module omitted defaults to off.
     *
     * @return array{inventory: bool, employees: bool, fixed_assets: bool, estimates: bool, sales_orders: bool, recurring_invoices: bool, recurring_bills: bool, classes: bool, locations: bool, budgets: bool, membership: bool, fundraising: bool}
     */
    public function recommendedFeatures(): array
    {
        $on = fn (string ...$keys): array => array_merge(
            array_fill_keys(['inventory', 'employees', 'fixed_assets', 'estimates', 'sales_orders', 'recurring_invoices', 'recurring_bills', 'classes', 'locations', 'budgets', 'membership', 'fundraising'], false),
            array_fill_keys($keys, true),
        );

        return match ($this) {
            self::General, self::Freelancer => $on(),
            self::Contractor, self::Manufacturing => $on('inventory', 'fixed_assets', 'estimates', 'employees'),
            self::NonProfit => $on('employees', 'fixed_assets', 'membership', 'fundraising'),
            self::RealEstate => $on('employees', 'fixed_assets'),
            self::Retail => $on('inventory', 'fixed_assets'),
            self::ProfessionalServices => $on('employees', 'estimates'),
            self::HealthWellness => $on('recurring_invoices', 'membership'),
            self::Restaurant => $on('inventory', 'fixed_assets', 'employees'),
        };
    }

    /**
     * @return array<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $i) => ['value' => $i->value, 'label' => $i->label(), 'description' => $i->description()],
            self::cases(),
        );
    }
}
