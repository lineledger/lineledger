<?php

use App\Support\Quantity;

it('strips trailing zeros for display', function () {
    expect(Quantity::format('1.0000'))->toBe('1')
        ->and(Quantity::format('2.5000'))->toBe('2.5')
        ->and(Quantity::format('10.0000'))->toBe('10')
        ->and(Quantity::format('0.2500'))->toBe('0.25');
});

it('preserves a genuine zero instead of coercing to one', function () {
    expect(Quantity::format('0.0000'))->toBe('0')
        ->and(Quantity::format('0'))->toBe('0')
        ->and(Quantity::format(0))->toBe('0')
        ->and(Quantity::format(0.0))->toBe('0');
});

it('falls back to zero for blank or null input', function () {
    expect(Quantity::format(''))->toBe('0')
        ->and(Quantity::format(null))->toBe('0');
});
