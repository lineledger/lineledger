<?php

use App\Support\Money;

it('parses decimal strings to cents', function (string $input, int $cents) {
    expect(Money::fromString($input)->cents)->toBe($cents);
})->with([
    ['0', 0],
    ['1', 100],
    ['1.5', 150],
    ['1.55', 155],
    ['1234.56', 123456],
    ['1,234.56', 123456],
    ['-12.34', -1234],
    ['  10.10  ', 1010],
]);

it('rejects invalid money strings', function () {
    Money::fromString('not money');
})->throws(InvalidArgumentException::class);

it('returns null instead of throwing for unparseable input', function (string $input) {
    expect(Money::tryFromString($input))->toBeNull();
})->with([
    'half-typed decimal' => ['6.'],
    'too many decimals' => ['6.789'],
    'non-numeric' => ['not money'],
    'empty' => [''],
    'lone dot' => ['.'],
]);

it('parses valid input the same as fromString', function () {
    expect(Money::tryFromString('1,234.56')?->cents)->toBe(123456);
    expect(Money::tryFromString('6')?->cents)->toBe(600);
});

it('adds and subtracts exactly without floats', function () {
    $a = Money::fromCents(123456);
    $b = Money::fromCents(7644);

    expect($a->add($b)->cents)->toBe(131100);
    expect($a->sub($b)->cents)->toBe(115812);
});

it('refuses to mix currencies', function () {
    Money::fromCents(100, 'CAD')->add(Money::fromCents(100, 'USD'));
})->throws(InvalidArgumentException::class);

it('formats to decimal string', function () {
    expect(Money::fromCents(123456)->toDecimalString())->toBe('1234.56');
    expect(Money::fromCents(0)->toDecimalString())->toBe('0.00');
    expect(Money::fromCents(-50)->toDecimalString())->toBe('-0.50');
    expect(Money::fromCents(5)->toDecimalString())->toBe('0.05');
});

it('round trips fromString to toDecimalString', function () {
    expect(Money::fromString('99.99')->toDecimalString())->toBe('99.99');
    expect(Money::fromString('0.01')->toDecimalString())->toBe('0.01');
});
