<?php

namespace App\Support\Payroll;

/**
 * The named earning types a pay run can carry, with their CRA source-deduction
 * flags and (for overtime) the hourly-rate multiplier. Single source of truth
 * shared by the calculation engine and the run-time earnings UI.
 *
 * Flags: pensionable (CPP/QPP), insurable (EI/QPIP), taxable (income tax),
 * vacationable (counts toward vacation accrual), the default T4 box, the
 * hourly-rate multiplier in basis points (10000 = 1.0×), and whether the type is
 * entered as hours (× the multiplier) rather than a flat amount.
 */
final class EarningTypeCatalogue
{
    /**
     * @return array<string, array{name: string, pensionable: bool, insurable: bool, taxable: bool, vacationable: bool, t4_box: ?string, multiplier_bp: int, hourly: bool, bonus_method: bool}>
     */
    public static function all(): array
    {
        $wage = fn (string $name, int $multiplierBp = 10000, bool $hourly = false, bool $bonusMethod = false): array => [
            'name' => $name,
            'pensionable' => true,
            'insurable' => true,
            'taxable' => true,
            'vacationable' => true,
            't4_box' => '14',
            'multiplier_bp' => $multiplierBp,
            'hourly' => $hourly,
            'bonus_method' => $bonusMethod,
        ];

        return [
            'regular' => $wage(__('Regular pay')),
            'overtime' => $wage(__('Overtime (1.5×)'), 15000, true),
            'overtime_double' => $wage(__('Overtime (2×)'), 20000, true),
            // Banked overtime pays $0 NOW (the hours land in the employee's
            // banked-time balance at the employee's bank multiplier; taxed when
            // taken or paid out), so no bases and no T4 box. The engine
            // special-cases this code — these flags are for the UI/labels.
            'overtime_banked' => [
                'name' => __('Overtime (bank the hours)'),
                'pensionable' => false,
                'insurable' => false,
                'taxable' => false,
                'vacationable' => false,
                't4_box' => null,
                'multiplier_bp' => 15000,
                'hourly' => true,
                'bonus_method' => false,
            ],
            'sick' => $wage(__('Sick pay')),
            'stat_holiday' => $wage(__('Stat holiday pay'), 10000, true),
            // Bonuses and retroactive pay increases are taxed by the CRA T4127
            // bonus method (the annual-tax delta the lump causes), not
            // annualized as ordinary period income.
            'bonus' => $wage(__('Bonus'), bonusMethod: true),
            'retro' => $wage(__('Retroactive pay'), bonusMethod: true),
            'commission' => $wage(__('Commission')),
            'vacation_pay' => $wage(__('Vacation pay')),
            'allowance' => $wage(__('Taxable allowance')),
            'other' => $wage(__('Other earnings')),
            // A reimbursement is not employment income: no CPP/EI/tax, no vacation,
            // no T4 box. (Excluded from the run-time options for now because gross
            // currently includes every earning — true reimbursements belong on an
            // expense cheque/AP, not the T4.)
            'reimbursement' => [
                'name' => __('Reimbursement'),
                'pensionable' => false,
                'insurable' => false,
                'taxable' => false,
                'vacationable' => false,
                't4_box' => null,
                'multiplier_bp' => 10000,
                'hourly' => false,
                'bonus_method' => false,
            ],
        ];
    }

    /**
     * Flags for an earning code. Unknown (free-form) codes default to a fully
     * pensionable/insurable/taxable/vacationable wage, preserving the engine's
     * pre-catalogue behaviour.
     *
     * @return array{name: string, pensionable: bool, insurable: bool, taxable: bool, vacationable: bool, t4_box: ?string, multiplier_bp: int, hourly: bool, bonus_method: bool}
     */
    public static function flags(string $code): array
    {
        return self::all()[$code] ?? [
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'pensionable' => true,
            'insurable' => true,
            'taxable' => true,
            'vacationable' => true,
            't4_box' => '14',
            'multiplier_bp' => 10000,
            'hourly' => false,
            'bonus_method' => false,
        ];
    }

    /**
     * Earning types the operator can add on a pay run at run time (code => label).
     * Excludes regular/vacation_pay (auto-generated) and reimbursement (see above).
     *
     * @return array<string, string>
     */
    public static function runTimeOptions(): array
    {
        $codes = ['overtime', 'overtime_double', 'overtime_banked', 'bonus', 'retro', 'commission', 'sick', 'stat_holiday', 'allowance', 'other'];
        $all = self::all();

        $options = [];
        foreach ($codes as $code) {
            $options[$code] = $all[$code]['name'];
        }

        return $options;
    }

    public static function isHourly(string $code): bool
    {
        return self::flags($code)['hourly'];
    }
}
