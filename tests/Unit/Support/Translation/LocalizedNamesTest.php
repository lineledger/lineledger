<?php

use App\Support\Translation\LocalizedNames;
use Tests\TestCase;

uses(TestCase::class);

it('includes the English source and French catalog translation', function () {
    expect(LocalizedNames::of('Chequing'))
        ->toContain('Chequing')
        ->toContain('Compte de chèques');
});
