<?php

namespace App\Support\Payroll;

/**
 * Type classifications for payroll items, by category, with the default
 * tax-treatment flags each Type seeds (all editable per item afterward).
 *
 * Defaults are reasonable Canadian starting points, not tax advice — e.g.
 * employer-paid private health premiums default to non-taxable (federally),
 * group life / auto / board default to a taxable benefit (T4 box 40). The
 * operator confirms/edits the flags per item.
 */
final class PayrollItemType
{
    /**
     * Type options (slug => label) for a category.
     *
     * @return array<string, string>
     */
    public static function options(string $kind): array
    {
        return match ($kind) {
            'earning' => [
                'allowances' => __('Allowances'), 'bonus' => __('Bonus'), 'commission' => __('Commission'),
                'directors_fees' => __("Director's Fees"), 'expense_advance' => __('Expense/Advance'),
                'miscellaneous' => __('Miscellaneous'), 'overtime' => __('Overtime'), 'paid_leave' => __('Paid Leave'),
                'premium' => __('Premium'), 'regular' => __('Regular'), 'severance_pay' => __('Severance Pay'),
                'sick_pay' => __('Sick Pay'), 'stat_pay' => __('Stat Pay'), 'tips' => __('Tips'),
                'vacation' => __('Vacation'), 'wcb_top_up' => __('WCB-Top Up'),
            ],
            'deduction' => [
                'advance' => __('Advance'), 'bonds' => __('Bonds'), 'charitable_donations' => __('Charitable Donations'),
                'extended_health' => __('Extended Health'), 'housing' => __('Housing'), 'insurance' => __('Insurance'),
                'loan' => __('Loan'), 'meals' => __('Meals'), 'medical' => __('Medical'), 'miscellaneous' => __('Miscellaneous'),
                'pension' => __('Pension'), 'savings_plan' => __('Savings Plan'), 'stock_purchases' => __('Stock Purchases'),
                'tuition' => __('Tuition'), 'union' => __('Union'),
            ],
            'contribution' => [
                'allowances' => __('Allowances'), 'auto' => __('Auto'), 'board' => __('Board'), 'dpsp' => __('DPSP'),
                'extended_health' => __('Extended Health'), 'insurance' => __('Insurance'), 'medical' => __('Medical'),
                'miscellaneous' => __('Miscellaneous'), 'misc_taxable' => __('Misc Taxable'), 'pension' => __('Pension'),
                'sales_tax' => __('Sales Tax'), 'stock_purchases' => __('Stock Purchases'), 'tuition' => __('Tuition'),
            ],
            default => [],
        };
    }

    /** Accrual calculation bases (slug => label). */
    public static function calcBases(): array
    {
        return [
            'hours' => __('Hours'), 'dollars' => __('Dollars'), 'units' => __('Units'), 'miles' => __('Miles'),
            'percent_of_earnings' => __('Percent of Earnings'), 'cents_per_hour' => __('Cents per Hour'),
            'percent_of_hours' => __('Percent of Hours'),
        ];
    }

    /**
     * Default flags + T4 box for a given category Type. Returned keys are a subset
     * of the recurring-item flag columns; callers merge them over the column
     * defaults (earnings start fully taxable/pensionable/insurable; deductions and
     * benefits start with everything off unless noted here).
     *
     * @return array<string, bool|string|null>
     */
    public static function defaults(string $kind, string $type): array
    {
        return match ($kind) {
            'earning' => match ($type) {
                'regular' => ['primary_earnings' => true, 't4_box' => '14'],
                'bonus' => ['tax_as_bonus' => true, 't4_box' => '14'],
                'expense_advance' => ['add_to_net_pay_only' => true, 'taxable_federal' => false, 'taxable_provincial' => false, 'cpp_qpp' => false, 'ei_insurable_earnings' => false, 'wcb_eligible' => false, 't4_box' => null],
                'directors_fees' => ['ei_insurable_earnings' => false, 'ei_insurable_hours' => false, 't4_box' => '14'],
                'severance_pay' => ['cpp_qpp' => false, 'ei_insurable_earnings' => false, 'ei_insurable_hours' => false, 't4_box' => null],
                'stat_pay' => ['stat_holiday_payout' => true, 't4_box' => '14'],
                default => ['t4_box' => '14'],
            },
            'deduction' => match ($type) {
                'pension' => ['pre_tax_federal' => true, 'pre_tax_provincial' => true, 't4_box' => '20'],
                'savings_plan' => ['pre_tax_federal' => true, 'pre_tax_provincial' => true, 't4_box' => null],
                'union' => ['pre_tax_federal' => true, 'pre_tax_provincial' => true, 't4_box' => '44'],
                'charitable_donations' => ['t4_box' => '46'],
                default => [],
            },
            'contribution' => match ($type) {
                // Taxable employer benefits → CPP-pensionable, not EI-insurable, T4 box 40.
                'insurance', 'auto', 'board', 'misc_taxable', 'allowances' => [
                    'taxable_federal' => true, 'taxable_provincial' => true, 'cpp_qpp' => true,
                    'ei_insurable_earnings' => false, 't4_box' => '40',
                ],
                // Non-taxable employer-paid benefits (e.g. private health) default to no tax impact.
                default => [
                    'taxable_federal' => false, 'taxable_provincial' => false, 'cpp_qpp' => false,
                    'ei_insurable_earnings' => false, 'qpip' => false, 't4_box' => null,
                ],
            },
            default => [],
        };
    }

    /**
     * The full flag set for an item, resolved from its category, Type, and (for
     * earnings) its code. Used to seed sensible flags when a caller doesn't send
     * them explicitly. Earnings start fully taxable/pensionable/insurable (refined
     * by the earning catalogue, e.g. reimbursement → none); deductions/benefits
     * start off and are turned on by the Type.
     *
     * @return array<string, bool>
     */
    public static function flagDefaults(string $kind, ?string $type, string $code): array
    {
        $isEarning = $kind === 'earning';

        $flags = [
            'taxable_federal' => $isEarning,
            'taxable_provincial' => $isEarning,
            'cpp_qpp' => $isEarning,
            'qpip' => $isEarning,
            'ei_insurable_earnings' => $isEarning,
            'ei_insurable_hours' => $isEarning,
            'wcb_eligible' => $isEarning,
            'tax_as_bonus' => false,
            'primary_earnings' => false,
            'add_to_net_pay_only' => false,
            'subtract_from_salary' => false,
            'stat_holiday_eligible' => false,
            'stat_holiday_payout' => false,
            'pre_tax_federal' => false,
            'pre_tax_provincial' => false,
        ];

        if ($isEarning) {
            $f = EarningTypeCatalogue::flags($code);
            $flags['taxable_federal'] = $f['taxable'];
            $flags['taxable_provincial'] = $f['taxable'];
            $flags['cpp_qpp'] = $f['pensionable'];
            $flags['qpip'] = $f['insurable'];
            $flags['ei_insurable_earnings'] = $f['insurable'];
            $flags['ei_insurable_hours'] = $f['insurable'];
            $flags['add_to_net_pay_only'] = $code === 'reimbursement';
        }

        if ($type !== null) {
            foreach (self::defaults($kind, $type) as $key => $value) {
                if ($key !== 't4_box' && array_key_exists($key, $flags)) {
                    $flags[$key] = (bool) $value;
                }
            }
        }

        return $flags;
    }
}
