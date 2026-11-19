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
            self::Never => __('Never (until paused)'),
            self::OnDate => __('On date'),
            self::AfterOccurrences => __('After number of occurrences'),
        };
    }
}
