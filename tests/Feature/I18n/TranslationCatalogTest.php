<?php

use App\Support\Translation\ChartCopy;
use App\Support\Translation\GifiCopy;
use App\Support\Translation\KeyExtractor;
use App\Support\Translation\TaxCopy;

it('registers starter chart names for translation', function () {
    expect(ChartCopy::names())->not->toBeEmpty();
});

it('registers seeded tax codes for translation', function () {
    expect(TaxCopy::codes())->not->toBeEmpty();
});

it('registers GIFI catalog strings for translation', function () {
    expect(GifiCopy::strings())->not->toBeEmpty();
});

it('has a French catalog entry for every static translation key', function () {
    $catalogPath = lang_path('fr.json');
    expect($catalogPath)->toBeFile();

    $catalog = json_decode((string) file_get_contents($catalogPath), true, flags: JSON_THROW_ON_ERROR);
    expect($catalog)->toBeArray();

    $identity = array_fill_keys(KeyExtractor::identityKeys(), true);
    $missing = [];

    foreach (KeyExtractor::keys() as $key) {
        if (array_key_exists($key, $catalog)) {
            continue;
        }
        if (isset($identity[$key])) {
            continue;
        }
        if ($key === '' || preg_match('/^[\d\s$%#.,;:\/\\\\+\-—–•·°×÷=<>]+$/u', $key) === 1) {
            continue;
        }
        $missing[] = $key;
    }

    expect($missing)->toBeEmpty('Missing French translations ('.count($missing).'): '.implode(' | ', array_slice($missing, 0, 40)));
});

it('does not keep unused keys in the French catalog', function () {
    $catalog = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);
    $used = array_fill_keys(KeyExtractor::referencedKeys(), true);

    $unused = [];
    foreach (array_keys($catalog) as $key) {
        if (! isset($used[$key])) {
            $unused[] = $key;
        }
    }

    expect($unused)->toBeEmpty('Unused French catalog keys ('.count($unused).'): '.implode(' | ', array_slice($unused, 0, 40)));
});

it('preserves placeholders in French catalog entries', function () {
    $catalog = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

    $broken = [];
    foreach ($catalog as $en => $fr) {
        preg_match_all('/:[A-Za-z][A-Za-z0-9_]*/', $en, $enPlaceholders);
        preg_match_all('/:[A-Za-z][A-Za-z0-9_]*/', (string) $fr, $frPlaceholders);
        $enSet = $enPlaceholders[0];
        sort($enSet);
        $frSet = $frPlaceholders[0];
        sort($frSet);
        if ($enSet !== $frSet) {
            $broken[] = $en;
        }
    }

    expect($broken)->toBeEmpty('Placeholder mismatch in: '.implode(' | ', array_slice($broken, 0, 20)));
});
