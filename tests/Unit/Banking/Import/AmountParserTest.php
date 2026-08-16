<?php

use App\Services\Banking\Import\Support\AmountParser;

it('parses anglo thousands and decimals', function () {
    expect(AmountParser::toCents('1,234.56'))->toBe(123456)
        ->and(AmountParser::toCents('-12.50'))->toBe(-1250)
        ->and(AmountParser::toCents('$1 234.50'))->toBe(123450)
        ->and(AmountParser::toCents('2000'))->toBe(200000);
});

it('parses european decimals when told the separator is a comma', function () {
    expect(AmountParser::toCents('1.234,56', ','))->toBe(123456)
        ->and(AmountParser::toCents('12,50', ','))->toBe(1250);
});

it('treats parentheses and DR/CR suffixes as signs', function () {
    expect(AmountParser::toCents('(45.00)'))->toBe(-4500)
        ->and(AmountParser::toCents('45.00 CR'))->toBe(4500)
        ->and(AmountParser::toCents('45.00 DR'))->toBe(-4500);
});

it('returns null for blank or unparseable input', function () {
    expect(AmountParser::toCents(null))->toBeNull()
        ->and(AmountParser::toCents(''))->toBeNull()
        ->and(AmountParser::toCents('  '))->toBeNull()
        ->and(AmountParser::toCents('n/a'))->toBeNull();
});
