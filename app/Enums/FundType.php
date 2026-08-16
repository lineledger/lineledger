<?php

namespace App\Enums;

/**
 * The restriction class of a fund under the ASNPO restricted fund method. Drives
 * how a fund's net assets present on the Statement of Changes in Net Assets.
 */
enum FundType: string
{
    case General = 'general';
    case Restricted = 'restricted';
    case Endowment = 'endowment';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General fund',
            self::Restricted => 'Restricted fund',
            self::Endowment => 'Endowment fund',
        };
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $t) => ['value' => $t->value, 'label' => $t->label()],
            self::cases(),
        );
    }
}
