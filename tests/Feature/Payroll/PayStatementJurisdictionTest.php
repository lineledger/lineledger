<?php

use App\Support\Payroll\PayStatementJurisdiction as J;

it('names the statement and cites the legislation per jurisdiction', function () {
    expect(J::forProvince('BC')['name'])->toBe('Wage Statement')
        ->and(J::forProvince('QC')['name'])->toBe('Pay Sheet')
        ->and(J::forProvince('NS')['name'])->toBe('Pay Stub')
        ->and(J::forProvince('ON')['name'])->toBe('Statement re: Wages')
        ->and(J::forProvince('SK')['name'])->toBe('Written Statement')
        ->and(J::forProvince('AB')['legislation'])->toContain('Employment Standards Code')
        ->and(J::forProvince('QC')['requires_french'])->toBeTrue()
        ->and(J::forProvince('AB')['requires_french'])->toBeFalse();
});

it('returns the federal CLC profile when the employer is federally regulated', function () {
    // Even for a BC employee, a federally-regulated employer follows the CLC.
    $fed = J::forProvince('BC', federallyRegulated: true);

    expect($fed['name'])->toBe('Pay Statement')
        ->and($fed['legislation'])->toContain('Canada Labour Code');
});

it('locks the universal baseline items on for every jurisdiction', function () {
    foreach (['FED', 'AB', 'BC', 'MB', 'NB', 'NL', 'NT', 'NS', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'] as $code) {
        foreach (['employee_name', 'pay_period_dates', 'gross_earnings', 'itemized_deductions', 'net_pay'] as $item) {
            expect(J::requires($code, $item))->toBeTrue("$code should require $item");
        }
    }
});

it('encodes the jurisdiction-specific required items from Appendix 2', function () {
    // Quebec uniquely requires occupation + declared/allocated tips.
    expect(J::requires('QC', 'occupation'))->toBeTrue()
        ->and(J::requires('QC', 'declared_tips'))->toBeTrue()
        ->and(J::requires('QC', 'allocated_tips'))->toBeTrue()
        // …which a non-Quebec statement does not.
        ->and(J::requires('AB', 'occupation'))->toBeFalse()
        // Alberta requires banked-overtime and holiday pay; BC requires employer address.
        ->and(J::requires('AB', 'overtime_banked'))->toBeTrue()
        ->and(J::requires('AB', 'holiday_pay'))->toBeTrue()
        ->and(J::requires('BC', 'employer_address'))->toBeTrue()
        // New Brunswick is baseline-only — no rate/hours requirement.
        ->and(J::requires('NB', 'rate'))->toBeFalse()
        ->and(J::requires('NB', 'net_pay'))->toBeTrue();
});

it('falls back to the NPI minimum for an unknown jurisdiction', function () {
    $entry = J::forProvince('ZZ');

    expect($entry['name'])->toBe('Pay Statement')
        ->and($entry['required'])->toContain('net_pay')
        ->and($entry['required'])->toContain('gross_earnings');
});
