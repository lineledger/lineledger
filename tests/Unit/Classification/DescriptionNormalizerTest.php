<?php

use App\Services\Banking\Import\Support\StatementFingerprint;
use App\Services\Classification\Support\DescriptionNormalizer;

it('lower-cases, trims and collapses whitespace', function () {
    expect(DescriptionNormalizer::normalize('  TIM   Hortons  #123 '))->toBe('tim hortons #123')
        ->and(DescriptionNormalizer::normalize(null))->toBe('')
        ->and(DescriptionNormalizer::normalize("A\tB\nC"))->toBe('a b c');
});

it('keeps statement fingerprints byte-identical to the inline algorithm', function () {
    $legacy = sha1(implode('|', [5, '2026-01-01', -450, 'coffee shop']));

    expect(StatementFingerprint::for(5, '2026-01-01', -450, 'COFFEE   SHOP', null))->toBe($legacy);
});

it('still lets a FITID dominate the fingerprint', function () {
    expect(StatementFingerprint::for(5, '2026-01-01', -450, 'whatever', 'FIT-1'))
        ->toBe(sha1('5|fitid|FIT-1'));
});
