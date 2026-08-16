<?php

namespace App\Enums;

enum PayRunStatus: string
{
    /** Header + employee list chosen; everything editable. */
    case Draft = 'draft';

    /** PayrollDeductionEngine has run; amounts populated (incl. overrides). */
    case Calculated = 'calculated';

    /** Run journal entry committed; immutable. */
    case Posted = 'posted';

    /** Every positive-net cheque has been posted. */
    case Paid = 'paid';

    /** Run journal entry reversed and all cheques voided. */
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Calculated => __('Calculated'),
            self::Posted => __('Posted'),
            self::Paid => __('Paid'),
            self::Void => __('Void'),
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Calculated;
    }

    public function isPosted(): bool
    {
        return $this === self::Posted || $this === self::Paid;
    }
}
