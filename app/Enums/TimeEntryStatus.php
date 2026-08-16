<?php

namespace App\Enums;

enum TimeEntryStatus: string
{
    /** Logged (typically self-logged by the employee), awaiting staff approval. */
    case Pending = 'pending';

    /** Approved by staff — eligible to be pulled into payroll and/or billed. */
    case Approved = 'approved';

    /** Rejected by staff — never paid or billed. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
        };
    }
}
