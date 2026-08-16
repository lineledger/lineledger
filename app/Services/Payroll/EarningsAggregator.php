<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Data\EarningsBreakdown;

/**
 * Reduces a pay-period's earning and pre-tax deduction lines into the
 * {@see EarningsBreakdown} the engine consumes, driven entirely by per-line
 * flags so new earning/deduction types need no calculator changes.
 */
class EarningsAggregator
{
    /**
     * @param  array<int, array{amount_cents: int, is_pensionable?: bool, is_insurable?: bool, is_qpip_insurable?: bool, is_taxable?: bool, is_bonus_method?: bool, add_to_bases_only?: bool}>  $earnings
     * @param  array<int, array{amount_cents: int, reduces_taxable?: bool}>  $preTaxDeductions
     */
    public function aggregate(array $earnings, array $preTaxDeductions = []): EarningsBreakdown
    {
        $gross = 0;
        $pensionable = 0;
        $insurable = 0;
        $qpipInsurable = 0;
        $taxableEarnings = 0;
        $bonusTaxable = 0;

        foreach ($earnings as $line) {
            $amount = (int) $line['amount_cents'];

            // A bases-only earning is a taxable employer benefit: it feeds the
            // statutory bases below (per its flags) so the tax is taken out of net
            // pay, but it is non-cash, so it never adds to gross.
            if (! ($line['add_to_bases_only'] ?? false)) {
                $gross += $amount;
            }

            if ($line['is_pensionable'] ?? true) {
                $pensionable += $amount;
            }

            $insurableLine = $line['is_insurable'] ?? true;

            if ($insurableLine) {
                $insurable += $amount;
            }

            // QPIP's base follows the EI flag unless the item says otherwise.
            if ($line['is_qpip_insurable'] ?? $insurableLine) {
                $qpipInsurable += $amount;
            }

            if ($line['is_taxable'] ?? true) {
                $taxableEarnings += $amount;

                // Bonus/retro lumps taxed by the T4127 bonus method.
                if ($line['is_bonus_method'] ?? false) {
                    $bonusTaxable += $amount;
                }
            }
        }

        $preTaxTotal = 0;

        foreach ($preTaxDeductions as $deduction) {
            if ($deduction['reduces_taxable'] ?? false) {
                $preTaxTotal += (int) $deduction['amount_cents'];
            }
        }

        return new EarningsBreakdown(
            grossCents: $gross,
            pensionableCents: $pensionable,
            insurableCents: $insurable,
            // GROSS taxable earnings: the income-tax calculator subtracts the
            // T4127 F factor (deductionsPerPeriodCents) exactly once. Netting
            // it here too double-deducted every pre-tax deduction and
            // systematically under-withheld income tax.
            taxableCents: max(0, $taxableEarnings),
            deductionsPerPeriodCents: $preTaxTotal,
            qpipInsurableCents: $qpipInsurable,
            // Pre-tax deductions net against REGULAR income (T4127's F factor),
            // so the bonus slice is clamped to what remains after them.
            bonusTaxableCents: min($bonusTaxable, max(0, $taxableEarnings - $preTaxTotal)),
        );
    }
}
