<?php

use App\Enums\AccountSubtype;
use App\Support\Gifi\GifiCatalog;
use App\Support\Gifi\GifiCodeCorrections;

/*
 * The GIFI catalog against CRA's guide RC4088 (tests/fixtures/gifi). A label
 * that drifts from CRA's name sends a preparer to the wrong line, and a CRA
 * total item offered as a line puts an account's balance on a figure the tax
 * software computes itself.
 */

function rc4088Names(): array
{
    return json_decode((string) file_get_contents(base_path('tests/fixtures/gifi/rc4088-names.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** RC4088's total items: the sums tax software works out from the lines. */
const RC4088_TOTAL_ITEMS = ['1599', '2008', '2009', '2178', '2179', '2589', '2599', '3139', '3450', '3499', '3620', '3640', '3849', '8089', '8299', '8519', '9367', '9368', '9369', '9999'];

it('labels every code with its CRA name', function () {
    $names = rc4088Names();

    foreach (GifiCatalog::all() as $entry) {
        expect(array_key_exists($entry['code'], $names))->toBeTrue("GIFI {$entry['code']} is not in the RC4088 fixture.")
            ->and($entry['label'])->toBe($names[$entry['code']]['en'], "GIFI {$entry['code']} is labelled differently from RC4088.");
    }
});

it('offers no CRA total item as a line', function () {
    expect(array_intersect(GifiCatalog::codes(), RC4088_TOTAL_ITEMS))->toBe([]);
});

it('defaults every subtype to a code in the catalog', function () {
    foreach (AccountSubtype::cases() as $subtype) {
        $code = GifiCatalog::defaultForSubtype($subtype);

        if ($code !== null) {
            expect(GifiCatalog::find($code))->not->toBeNull("{$subtype->value} defaults to {$code}, which is not in the catalog.");
        }
    }
});

it('corrects every old code onto a code in the catalog', function () {
    foreach (GifiCodeCorrections::MAP as $old => $new) {
        expect(GifiCatalog::find($new))->not->toBeNull("{$old} is corrected to {$new}, which is not in the catalog.");
    }
});

it('maps the labels the old catalog showed to CRA’s codes for them', function () {
    expect(GifiCodeCorrections::correct('8620'))->toBe('8690')   // "Insurance"
        ->and(GifiCatalog::find('8690')['label'])->toBe('Insurance')
        ->and(GifiCodeCorrections::correct('8690'))->toBe('8710') // "Interest and bank charges"
        ->and(GifiCatalog::find('8710')['label'])->toBe('Interest and bank charges')
        ->and(GifiCodeCorrections::correct('2589'))->toBe('2420') // "Other long-term assets"
        ->and(GifiCatalog::find('2420')['label'])->toBe('Other long-term assets')
        ->and(GifiCodeCorrections::correct('9367'))->toBe('8670') // "Amortization of tangible assets"
        ->and(GifiCatalog::find('8670')['label'])->toBe('Amortization of tangible assets')
        ->and(GifiCodeCorrections::correct('3849'))->toBe('3600')
        ->and(GifiCodeCorrections::correct('9270'))->toBe('9270')
        ->and(GifiCodeCorrections::correct(null))->toBeNull();
});

it('puts retained earnings and other long-term balances on reportable lines', function () {
    expect(GifiCatalog::defaultForSubtype(AccountSubtype::RetainedEarnings))->toBe('3600')
        ->and(GifiCatalog::defaultForSubtype(AccountSubtype::OtherAsset))->toBe('2420')
        ->and(GifiCatalog::defaultForSubtype(AccountSubtype::OtherLiability))->toBe('3320')
        ->and(GifiCatalog::defaultForSubtype(AccountSubtype::Bank))->toBe('1000');
});
