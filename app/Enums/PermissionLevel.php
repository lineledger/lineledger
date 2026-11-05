<?php

namespace App\Enums;

/**
 * A role's access to one activity, as QuickBooks Desktop Enterprise shows it:
 * None, Full (every action the activity has), or Partial (the actions ticked).
 */
enum PermissionLevel: string
{
    case None = 'none';
    case Full = 'full';
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::None => __('None'),
            self::Full => __('Full'),
            self::Partial => __('Partial'),
        };
    }
}
