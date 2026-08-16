<?php

namespace App\Enums;

/**
 * Lifecycle of a grant. Draft books no GL; posting the award writes the cash-in /
 * deferral entry; the grant is Active while revenue is being recognized and
 * Completed once fully recognized. Void reverses the award and recognitions.
 */
enum GrantStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Void => 'Void',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Active => 'blue',
            self::Completed => 'green',
            self::Void => 'red',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isPosted(): bool
    {
        return $this === self::Active || $this === self::Completed;
    }
}
