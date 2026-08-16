<?php

use App\Support\Currency;

it('converts foreign cents to home cents at a rate', function () {
    expect(Currency::toHomeCents(100_000, '1.35'))->toBe(135_000)
        ->and(Currency::toHomeCents(100_000, '1.40'))->toBe(140_000)
        ->and(Currency::toHomeCents(0, '1.35'))->toBe(0);
});

it('rounds half up without float drift', function () {
    // 12345 * 1.234 = 15233.73 -> 15234
    expect(Currency::toHomeCents(12_345, '1.234'))->toBe(15_234)
        // 100 * 1.005 = 100.5 -> 101 (half rounds up)
        ->and(Currency::toHomeCents(100, '1.005'))->toBe(101);
});

it('converts negative amounts symmetrically', function () {
    expect(Currency::toHomeCents(-100_000, '1.35'))->toBe(-135_000)
        // -100.5 rounds away from zero to -101 under half-up magnitude
        ->and(Currency::toHomeCents(-100, '1.005'))->toBe(-101);
});

it('treats a rate of 1 as identity', function () {
    expect(Currency::toHomeCents(987_654, '1'))->toBe(987_654);
});

it('offers only two-decimal currencies as selectable', function () {
    $selectable = Currency::selectable();

    expect($selectable)->toHaveKey('USD')
        ->and($selectable)->toHaveKey('CAD')
        ->and($selectable)->not->toHaveKey('JPY');
});
