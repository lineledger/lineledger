<?php

use App\Services\Restore\IdMapper;

it('round-trips set/get for a recorded id', function () {
    $mapper = new IdMapper;
    $mapper->set('invoices', 17, 4001);

    expect($mapper->get('invoices', 17))->toBe(4001)
        ->and($mapper->has('invoices', 17))->toBeTrue();
});

it('returns null when the requested id has no mapping', function () {
    $mapper = new IdMapper;

    expect($mapper->get('invoices', 999))->toBeNull()
        ->and($mapper->has('invoices', 999))->toBeFalse();
});

it('returns the populated sub-array for a table', function () {
    $mapper = new IdMapper;
    $mapper->set('invoices', 1, 100);
    $mapper->set('invoices', 2, 200);
    $mapper->set('bills', 5, 500);

    expect($mapper->table('invoices'))->toBe([1 => 100, 2 => 200])
        ->and($mapper->table('bills'))->toBe([5 => 500])
        ->and($mapper->table('unknown'))->toBe([]);
});

it('lists the populated table names', function () {
    $mapper = new IdMapper;
    $mapper->set('invoices', 1, 10);
    $mapper->set('bills', 1, 20);

    expect($mapper->tables())->toEqualCanonicalizing(['invoices', 'bills']);
});

it('overwrites a previously recorded mapping when set is called again', function () {
    $mapper = new IdMapper;
    $mapper->set('invoices', 1, 10);
    $mapper->set('invoices', 1, 11);

    expect($mapper->get('invoices', 1))->toBe(11);
});
