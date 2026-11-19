<?php

namespace App\Enums;

/**
 * What kind of obligation a deadline tracks. Drives the colour of its chip on
 * the calendar and the grouping on the year tracker; it has no effect on how
 * the dates are worked out.
 */
enum DeadlineCategory: string
{
    case SalesTax = 'sales_tax';
    case PayrollRemittance = 'payroll_remittance';
    case WorkersComp = 'workers_comp';
    case EmployerHealthTax = 'employer_health_tax';
    case IncomeTaxInstalment = 'income_tax_instalment';
    case IncomeTaxReturn = 'income_tax_return';
    case InformationReturn = 'information_return';
    case CorporateFiling = 'corporate_filing';
    case LicenceRenewal = 'licence_renewal';
    case Insurance = 'insurance';
    case LoanOrLease = 'loan_or_lease';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SalesTax => __('Sales tax'),
            self::PayrollRemittance => __('Payroll remittance'),
            self::WorkersComp => __('Workers’ compensation'),
            self::EmployerHealthTax => __('Employer health tax'),
            self::IncomeTaxInstalment => __('Income tax instalment'),
            self::IncomeTaxReturn => __('Income tax return'),
            self::InformationReturn => __('Information return or slips'),
            self::CorporateFiling => __('Corporate filing'),
            self::LicenceRenewal => __('Licence or permit renewal'),
            self::Insurance => __('Insurance'),
            self::LoanOrLease => __('Loan or lease payment'),
            self::Other => __('Other'),
        };
    }

    /**
     * The Flux badge colour for this category.
     */
    public function color(): string
    {
        return match ($this) {
            self::SalesTax => 'sky',
            self::PayrollRemittance => 'violet',
            self::WorkersComp => 'teal',
            self::EmployerHealthTax => 'cyan',
            self::IncomeTaxInstalment => 'indigo',
            self::IncomeTaxReturn => 'blue',
            self::InformationReturn => 'purple',
            self::CorporateFiling => 'fuchsia',
            self::LicenceRenewal => 'lime',
            self::Insurance => 'emerald',
            self::LoanOrLease => 'orange',
            self::Other => 'zinc',
        };
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
