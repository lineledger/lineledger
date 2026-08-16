<?php

use App\Services\Banking\Import\Support\DateFormatGuesser;

it('detects ISO dates unambiguously', function () {
    $guess = DateFormatGuesser::guess(['2026-01-03', '2026-12-31']);

    expect($guess['format'])->toBe('Y-m-d')
        ->and($guess['ambiguous'])->toBeFalse();
});

it('infers month-first when a value has a day past 12', function () {
    $guess = DateFormatGuesser::guess(['01/13/2026', '02/28/2026']);

    expect($guess['format'])->toBe('m/d/Y')
        ->and($guess['ambiguous'])->toBeFalse();
});

it('infers day-first when the first part exceeds 12', function () {
    $guess = DateFormatGuesser::guess(['13/01/2026', '28/02/2026']);

    expect($guess['format'])->toBe('d/m/Y')
        ->and($guess['ambiguous'])->toBeFalse();
});

it('flags day/month ambiguity when every value could be read either way', function () {
    $guess = DateFormatGuesser::guess(['03/04/2026', '05/06/2026']);

    expect($guess['ambiguous'])->toBeTrue();
});
