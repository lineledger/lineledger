<?php

namespace App\Enums;

enum RecurrenceEndType: string
{
    case Never = 'never';
    case OnDate = 'on_date';
    case AfterOccurrences = 'after_occurrences';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Never (until paused)',
            self::OnDate => 'On date',
            self::AfterOccurrences => 'After number of occurrences',
        };
    }
}
