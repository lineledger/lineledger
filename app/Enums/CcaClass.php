<?php

namespace App\Enums;

/**
 * Common CRA capital cost allowance (CCA) classes and their declining-balance
 * rates, used to compute the T2125 Area A schedule. An asset category is mapped
 * to one class; assets in that category pool together for CCA. This is a curated
 * subset of the full CCA class list covering what small businesses typically own.
 */
enum CcaClass: string
{
    case Class1 = '1';
    case Class8 = '8';
    case Class10 = '10';
    case Class10_1 = '10.1';
    case Class12 = '12';
    case Class14_1 = '14.1';
    case Class43 = '43';
    case Class50 = '50';
    case Class53 = '53';

    /** Declining-balance CCA rate. */
    public function rate(): float
    {
        return match ($this) {
            self::Class1 => 0.04,
            self::Class8 => 0.20,
            self::Class10 => 0.30,
            self::Class10_1 => 0.30,
            self::Class12 => 1.00,
            self::Class14_1 => 0.05,
            self::Class43 => 0.30,
            self::Class50 => 0.55,
            self::Class53 => 0.50,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Class1 => 'Class 1 — Buildings (4%)',
            self::Class8 => 'Class 8 — Furniture & equipment (20%)',
            self::Class10 => 'Class 10 — Vehicles & general equipment (30%)',
            self::Class10_1 => 'Class 10.1 — Passenger vehicles (30%)',
            self::Class12 => 'Class 12 — Tools, software, small assets (100%)',
            self::Class14_1 => 'Class 14.1 — Goodwill & intangibles (5%)',
            self::Class43 => 'Class 43 — Manufacturing equipment (30%)',
            self::Class50 => 'Class 50 — Computer hardware & systems software (55%)',
            self::Class53 => 'Class 53 — Manufacturing machinery (50%)',
        };
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
