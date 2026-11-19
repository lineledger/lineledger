<?php

namespace App\Enums;

/**
 * Which deadline email a notification log row records.
 */
enum DeadlineNotificationKind: string
{
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
    case Digest = 'digest';
}
