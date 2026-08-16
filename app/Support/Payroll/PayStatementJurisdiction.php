<?php

namespace App\Support\Payroll;

/**
 * The pay-statement rules of each Canadian employment/labour-standards
 * jurisdiction: the legal NAME of the statement, the governing LEGISLATION, the
 * record-RETENTION period, and the set of line items the statute REQUIRES (which
 * the employer cannot hide). Source: National Payroll Institute, Pay Statement
 * Guidelines (Oct 2025) — Appendix 1 (legislation), Appendix 2 (required items),
 * Appendix 3 (retention).
 *
 * A pay statement must follow the standards of the jurisdiction where the work is
 * performed; {@see forProvince()} resolves the employee's province (or the
 * federal Canada Labour Code Part III rules when the employer is federally
 * regulated). Unknown codes fall back to the NPI minimum so a statement always
 * renders.
 *
 * Item-key vocabulary (the union of Appendix 2 rows) is {@see ITEM_KEYS}; the
 * universal minimum every statement carries is {@see BASELINE}. Per-jurisdiction
 * `required` sets list only the statute-specific additions on top of the baseline.
 */
final class PayStatementJurisdiction
{
    /** Every line-item key a statement can show; labels in {@see itemLabels()}. */
    public const ITEM_KEYS = [
        'employer_name', 'employer_address', 'employee_name', 'occupation',
        'pay_period_dates', 'payment_date', 'rate', 'hours',
        'regular_wages', 'overtime_wages', 'overtime_rate', 'overtime_banked',
        'vacation_pay', 'holiday_pay', 'other_earnings', 'bonus', 'commissions', 'allowances',
        'gross_earnings', 'itemized_deductions', 'net_pay',
        'declared_tips', 'allocated_tips', 'ytd',
    ];

    /**
     * The NPI minimum present on every pay statement (consolidated chart, p.6).
     * Each jurisdiction's `required` set is the union of this and its Appendix-2
     * specifics, so core items (gross, deductions, net) are always locked on.
     */
    private const BASELINE = ['employee_name', 'pay_period_dates', 'gross_earnings', 'itemized_deductions', 'net_pay'];

    /**
     * @return array<string, array{name: string, legislation: string, retention: string, retention_months: int, requires_french: bool, required: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            'FED' => self::entry(__('Pay Statement'), 'Canada Labour Code, Part III, s. 254(1)', '3 years', 36, false,
                ['hours', 'rate']),

            'AB' => self::entry(__('Pay Statement'), 'Employment Standards Code (AB), s. 14(1)–(2)', '3 years from date record made', 36, false,
                ['hours', 'rate', 'regular_wages', 'overtime_wages', 'overtime_rate', 'overtime_banked', 'vacation_pay', 'holiday_pay', 'other_earnings']),

            'BC' => self::entry(__('Wage Statement'), 'Employment Standards Act (BC), s. 27(1)–(4)', '2 years after termination of employment', 24, false,
                ['employer_name', 'employer_address', 'hours', 'rate', 'overtime_rate', 'overtime_banked', 'other_earnings', 'bonus', 'allowances']),

            'MB' => self::entry(__('Pay Statement'), 'Employment Standards Code (MB), s. 135(4)–(5)', '3 years from date record made', 36, false,
                ['hours', 'rate', 'regular_wages', 'overtime_wages']),

            'NB' => self::entry(__('Statement'), 'Employment Standards Act (NB), s. 36(1)', '36 months', 36, false,
                []),

            'NL' => self::entry(__('Statement of Earnings'), 'Labour Standards Act (NL), s. 35(a)–(e)', '4 years', 48, false,
                ['hours', 'rate']),

            'NT' => self::entry(__('Pay Statement'), 'Employment Standards Act, SNWT 2007, s. 19(1)–(3)', '2 years', 24, false,
                ['hours', 'rate', 'holiday_pay']),

            'NS' => self::entry(__('Pay Stub'), 'Labour Standards Code (NS), reg. s. 9(1) / s. 7', '36 months', 36, false,
                ['hours', 'rate']),

            'NU' => self::entry(__('Pay Statement'), 'Labour Standards Act (NU), s. 48(1)–(3)', '2 years', 24, false,
                ['hours', 'rate']),

            'ON' => self::entry(__('Statement re: Wages'), 'Employment Standards Act, 2000 (ON), s. 12(1)–(3)', '3 years from date record made', 36, false,
                ['rate', 'allowances', 'other_earnings']),

            'PE' => self::entry(__('Pay Statement'), 'Employment Standards Act (PE), s. 5.3', '36 months', 36, false,
                ['employer_name', 'employer_address', 'rate', 'hours', 'bonus', 'other_earnings', 'vacation_pay']),

            'QC' => self::entry(__('Pay Sheet'), 'Act respecting labour standards (QC), s. 46; Charter of the French Language, s. 41/89', '3 years after work is performed', 36, true,
                ['employer_name', 'occupation', 'payment_date', 'hours', 'rate', 'overtime_wages', 'overtime_rate', 'bonus', 'vacation_pay', 'holiday_pay', 'allowances', 'commissions', 'declared_tips', 'allocated_tips']),

            'SK' => self::entry(__('Written Statement'), 'Saskatchewan Employment Act, s. 2-37', '5 years after termination of employment', 60, false,
                ['employer_name', 'hours', 'rate', 'holiday_pay', 'vacation_pay']),

            'YT' => self::entry(__("Employee's Statement"), 'Employment Standards Act (YT), s. 63(a)–(e)', '12 months', 12, false,
                ['hours', 'rate']),
        ];
    }

    /**
     * The jurisdiction profile for an employee's province of employment, or the
     * federal Canada Labour Code Part III profile when the employer is federally
     * regulated. Unknown/blank codes fall back to the NPI minimum.
     *
     * @return array{name: string, legislation: string, retention: string, retention_months: int, requires_french: bool, required: array<int, string>}
     */
    public static function forProvince(string $code, bool $federallyRegulated = false): array
    {
        if ($federallyRegulated) {
            return self::all()['FED'];
        }

        return self::all()[mb_strtoupper(trim($code))] ?? self::fallback();
    }

    /**
     * Whether a line item is legislatively required (locked on, cannot be hidden)
     * for a jurisdiction.
     */
    public static function requires(string $code, string $itemKey, bool $federallyRegulated = false): bool
    {
        return in_array($itemKey, self::forProvince($code, $federallyRegulated)['required'], true);
    }

    /**
     * Human labels for the line-item keys, for the settings toggles and statement.
     *
     * @return array<string, string>
     */
    public static function itemLabels(): array
    {
        return [
            'employer_name' => __('Employer name'),
            'employer_address' => __('Employer address'),
            'employee_name' => __('Employee name'),
            'occupation' => __('Occupation'),
            'pay_period_dates' => __('Pay period dates'),
            'payment_date' => __('Payment date'),
            'rate' => __('Rate'),
            'hours' => __('Hours'),
            'regular_wages' => __('Regular wages'),
            'overtime_wages' => __('Overtime wages'),
            'overtime_rate' => __('Overtime rate'),
            'overtime_banked' => __('Banked overtime + balance'),
            'vacation_pay' => __('Vacation pay'),
            'holiday_pay' => __('Holiday pay'),
            'other_earnings' => __('Other earnings'),
            'bonus' => __('Bonus'),
            'commissions' => __('Commissions'),
            'allowances' => __('Allowances'),
            'gross_earnings' => __('Gross earnings'),
            'itemized_deductions' => __('Itemized deductions'),
            'net_pay' => __('Net pay'),
            'declared_tips' => __('Declared tips'),
            'allocated_tips' => __('Allocated tips'),
            'ytd' => __('Year-to-date totals'),
        ];
    }

    /**
     * Build a jurisdiction entry: the baseline required items unioned with the
     * statute-specific additions (deduped, original key order preserved).
     *
     * @param  array<int, string>  $specificRequired
     * @return array{name: string, legislation: string, retention: string, retention_months: int, requires_french: bool, required: array<int, string>}
     */
    private static function entry(string $name, string $legislation, string $retention, int $retentionMonths, bool $requiresFrench, array $specificRequired): array
    {
        $required = array_values(array_unique([...self::BASELINE, ...$specificRequired]));

        return [
            'name' => $name,
            'legislation' => $legislation,
            'retention' => $retention,
            'retention_months' => $retentionMonths,
            'requires_french' => $requiresFrench,
            'required' => $required,
        ];
    }

    /**
     * NPI-minimum fallback for an unrecognized jurisdiction code.
     *
     * @return array{name: string, legislation: string, retention: string, retention_months: int, requires_french: bool, required: array<int, string>}
     */
    private static function fallback(): array
    {
        return self::entry(__('Pay Statement'), __('Employment/labour standards of the jurisdiction of employment'), '3 years', 36, false,
            ['hours', 'rate']);
    }
}
