<?php

namespace App\Enums;

use App\Services\Payroll\RemittancePeriodResolver;

/**
 * The CRA-assigned remitter type (by average monthly withholding amount), which
 * sets each source-deduction remittance period's bounds and due date. Also drives
 * the Revenu Québec periods. See {@see RemittancePeriodResolver}.
 */
enum RemittanceFrequency: string
{
    case Quarterly = 'quarterly';
    case Monthly = 'monthly';            // CRA "regular" remitter
    case Accelerated1 = 'accelerated_1'; // Threshold 1 — twice monthly
    case Accelerated2 = 'accelerated_2'; // Threshold 2 — four periods a month

    public function label(): string
    {
        return match ($this) {
            self::Quarterly => __('Quarterly'),
            self::Monthly => __('Monthly (regular)'),
            self::Accelerated1 => __('Accelerated — Threshold 1 (twice monthly)'),
            self::Accelerated2 => __('Accelerated — Threshold 2 (up to 4× monthly)'),
        };
    }

    public function dueDateHint(): string
    {
        return match ($this) {
            self::Quarterly => __('Due the 15th of the month after the quarter.'),
            self::Monthly => __('Due the 15th of the following month.'),
            self::Accelerated1 => __('1st–15th due the 25th; 16th–end due the 10th of next month.'),
            self::Accelerated2 => __('Each period due the 3rd working day after it ends.'),
        };
    }

    /** @return array<string, string> value => label, for select inputs. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
