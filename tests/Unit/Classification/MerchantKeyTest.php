<?php

use App\Services\Classification\Support\DescriptionNormalizer;
use App\Services\Classification\Support\MerchantKey;

it('keeps the payee and drops reference numbers, dates, amounts and branch codes', function (string $input, string $expected) {
    expect(MerchantKey::from($input))->toBe($expected);
})->with([
    ['Pre-Authorized Payment, L SOCIO DIGITAL FEE/FRA    ,', 'pre authorized payment l socio digital fee fra'],
    ['PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812', 'pre authorized payment l socio digital fee fra'],
    ['INTERAC E-TRANSFER 20260903 REF 1234', 'interac e transfer'],
    ['INTERAC E-TRANSFER TO JOHN SMITH 20260903 REF 9981', 'interac e transfer to john smith'],
    ['TIM HORTONS #1234 TORONTO ON', 'tim hortons toronto'],
    ['AMZN Mktp CA*2K4RT8UJ3 AMAZON.CA ON', 'amzn mktp ca amazon ca'],
    ['SQ *THE COFFEE BAR Vancouver BC', 'sq the coffee bar vancouver'],
    ['ATM WITHDRAWAL $200.00 BRANCH 0421', 'atm withdrawal branch'],
    ['PAYROLL DEPOSIT 2026-08-31', 'payroll deposit'],
    ['7-ELEVEN 31022 CALGARY AB', 'eleven calgary'],
    ["Tim Horton's 3 Sep 2026", 'tim hortons'],
    ['HYDRO ONE BILL PAYMENT MAR 15, 2026 CONF 77A1B2', 'hydro one bill payment'],
    ['CHQ 00123', 'chq'],
    ['12345', ''],
    ['', ''],
]);

it('does not mistake a word starting with a month name for a date', function () {
    expect(MerchantKey::from('3 MARINA WAY PARKING'))->toBe('marina way parking');
});

it('treats short keys as unusable so generic descriptions never fuzzy-match', function () {
    expect(MerchantKey::isUsable(MerchantKey::from('CHQ 00123')))->toBeFalse()
        ->and(MerchantKey::isUsable(MerchantKey::from('FEE')))->toBeFalse()
        ->and(MerchantKey::isUsable(MerchantKey::from('HYDRO 123')))->toBeTrue();
});

it('is idempotent', function () {
    $key = MerchantKey::from('PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812');

    expect(MerchantKey::from($key))->toBe($key);
});

it('handles null and multibyte input', function () {
    expect(MerchantKey::from(null))->toBe('')
        ->and(MerchantKey::from('CAFÉ RENÉ #12 MONTRÉAL QC'))->toBe('café rené montréal');
});

it('leaves the fingerprint normalizer untouched', function () {
    expect(DescriptionNormalizer::normalize('  TIM   HORTONS #1234 '))->toBe('tim hortons #1234');
});
