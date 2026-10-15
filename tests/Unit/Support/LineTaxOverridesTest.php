<?php

use App\Support\Tax\LineTaxOverrides;

describe('fromStored', function () {
    it('keys each stored slot by its tax code', function () {
        expect(LineTaxOverrides::fromStored(12, 15, 400, 675))->toBe([12 => '4.00', 15 => '6.75']);
    });

    it('skips a slot with no override or no code', function () {
        expect(LineTaxOverrides::fromStored(12, 15, null, 675))->toBe([15 => '6.75'])
            ->and(LineTaxOverrides::fromStored(12, null, 400, 675))->toBe([12 => '4.00'])
            ->and(LineTaxOverrides::fromStored(null, null, 400, 675))->toBe([])
            ->and(LineTaxOverrides::fromStored(12, 15, null, null))->toBe([]);
    });

    it('keeps a zero or negative override', function () {
        expect(LineTaxOverrides::fromStored(12, 15, 0, -150))->toBe([12 => '0.00', 15 => '-1.50']);
    });

    it('accepts ids as strings, the way Livewire hands them back', function () {
        expect(LineTaxOverrides::fromStored('12', '', 400, 675))->toBe([12 => '4.00']);
    });
});

describe('prune', function () {
    it('drops overrides for codes no longer on the line', function () {
        $overrides = [12 => '4.00', 15 => '6.75'];

        expect(LineTaxOverrides::prune($overrides, [15, null]))->toBe([15 => '6.75'])
            ->and(LineTaxOverrides::prune($overrides, [null, null]))->toBe([])
            ->and(LineTaxOverrides::prune($overrides, [12, 15]))->toBe($overrides);
    });

    it('matches string and int ids alike and trims values', function () {
        expect(LineTaxOverrides::prune(['12' => ' 4.00 ', 15 => '6.75'], ['12', '']))->toBe([12 => '4.00']);
    });

    it('reads a non-scalar value as blank', function () {
        expect(LineTaxOverrides::prune([12 => ['4.00']], [12]))->toBe([12 => '']);
    });
});

describe('cents', function () {
    it('returns the override for a code in cents', function () {
        expect(LineTaxOverrides::cents([12 => '4.00', 15 => '1,234.5'], 12))->toBe(400)
            ->and(LineTaxOverrides::cents([12 => '4.00', 15 => '1,234.5'], 15))->toBe(123450)
            ->and(LineTaxOverrides::cents([12 => '4.00'], '12'))->toBe(400)
            ->and(LineTaxOverrides::cents([12 => '0'], 12))->toBe(0);
    });

    it('is null without a code, without an override, or for a blank or half-typed value', function () {
        expect(LineTaxOverrides::cents([12 => '4.00'], null))->toBeNull()
            ->and(LineTaxOverrides::cents([12 => '4.00'], ''))->toBeNull()
            ->and(LineTaxOverrides::cents([12 => '4.00'], 15))->toBeNull()
            ->and(LineTaxOverrides::cents([12 => '   '], 12))->toBeNull()
            ->and(LineTaxOverrides::cents([12 => '6.'], 12))->toBeNull()
            ->and(LineTaxOverrides::cents([12 => 'abc'], 12))->toBeNull()
            ->and(LineTaxOverrides::cents([12 => null], 12))->toBeNull()
            ->and(LineTaxOverrides::cents([], 12))->toBeNull();
    });
});

describe('toSlots', function () {
    it('maps each code’s override onto the slot that code now occupies', function () {
        $overrides = [12 => '4.00', 15 => '6.75'];

        expect(LineTaxOverrides::toSlots($overrides, 12, 15))
            ->toBe(['tax_override_cents' => 400, 'secondary_tax_override_cents' => 675]);

        // The first code was unticked, so the second moved up to the primary slot.
        expect(LineTaxOverrides::toSlots($overrides, 15, null))
            ->toBe(['tax_override_cents' => 675, 'secondary_tax_override_cents' => null]);
    });

    it('leaves a slot null when its code has no override', function () {
        expect(LineTaxOverrides::toSlots([15 => '6.75'], 12, 15))
            ->toBe(['tax_override_cents' => null, 'secondary_tax_override_cents' => 675])
            ->and(LineTaxOverrides::toSlots([], null, null))
            ->toBe(['tax_override_cents' => null, 'secondary_tax_override_cents' => null]);
    });

    it('round-trips with fromStored', function () {
        $stored = LineTaxOverrides::fromStored(12, 15, 400, 675);

        expect(LineTaxOverrides::toSlots($stored, 12, 15))
            ->toBe(['tax_override_cents' => 400, 'secondary_tax_override_cents' => 675]);
    });
});
