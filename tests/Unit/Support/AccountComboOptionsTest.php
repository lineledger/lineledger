<?php

use App\Support\Accounting\AccountComboOptions;

it('normalizes model-like objects to id, code and name', function () {
    $options = [
        (object) ['id' => 7, 'code' => '2400', 'name' => 'Client Disbursements'],
        (object) ['id' => '8', 'code' => null, 'name' => 'Uncoded'],
    ];

    expect(AccountComboOptions::normalize($options))->toBe([
        ['id' => 7, 'code' => '2400', 'name' => 'Client Disbursements'],
        ['id' => 8, 'code' => '', 'name' => 'Uncoded'],
    ]);
});

it('normalizes value/label and id/label arrays, preferring a separate code and name', function () {
    $options = [
        ['value' => 1, 'label' => '1010 — Petty Cash', 'code' => '1010', 'name' => 'Petty Cash'],
        ['id' => 2, 'label' => '1020 — Chequing (no ledger entry)', 'code' => '1020', 'name' => 'Chequing (no ledger entry)'],
        ['value' => 3, 'label' => 'Label only'],
    ];

    expect(AccountComboOptions::normalize($options))->toBe([
        ['id' => 1, 'code' => '1010', 'name' => 'Petty Cash'],
        ['id' => 2, 'code' => '1020', 'name' => 'Chequing (no ledger entry)'],
        ['id' => 3, 'code' => '', 'name' => 'Label only'],
    ]);
});

it('normalizes an id => label map', function () {
    expect(AccountComboOptions::normalize([5 => 'Rent', 9 => 'Utilities']))->toBe([
        ['id' => 5, 'code' => '', 'name' => 'Rent'],
        ['id' => 9, 'code' => '', 'name' => 'Utilities'],
    ]);
});

it('drops rows without an id', function () {
    expect(AccountComboOptions::normalize([
        ['label' => 'No id'],
        ['id' => '', 'name' => 'Blank id'],
        (object) ['code' => '1000'],
        null,
    ]))->toBe([]);
});

it('emits JSON that cannot break out of a script element', function () {
    $json = AccountComboOptions::toJson([
        ['id' => 1, 'code' => '1000', 'name' => '</script><script>alert("x")</script> & \'co\''],
    ]);

    expect($json)
        ->not->toContain('<')
        ->not->toContain('>')
        ->not->toContain("'")
        ->toContain('\u003C\/script\u003E')
        ->and(json_decode($json, true))->toBe([
            ['id' => 1, 'code' => '1000', 'name' => '</script><script>alert("x")</script> & \'co\''],
        ]);
});
