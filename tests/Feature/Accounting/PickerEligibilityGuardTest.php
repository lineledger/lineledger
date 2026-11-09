<?php

use Illuminate\Support\Facades\File;

/*
 * Guard rail: who is eligible for a picker is decided once, on the model. A form
 * that decides for itself drifts from its siblings, which is how the inbox came to
 * offer Prepaid Expenses as "Paid from" (#198) and sales forms purchase-only tax.
 */

/**
 * @return iterable<SplFileInfo>
 */
function pickerGuardPhpFiles(): iterable
{
    foreach ([app_path(), resource_path('views')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}

it('filters account subtypes through the enum, never a raw string', function () {
    $offenders = [];

    foreach (pickerGuardPhpFiles() as $file) {
        foreach (file($file->getPathname()) as $number => $line) {
            // ->where('subtype', 'bank') or ->whereIn('a.subtype', ['bank', …]), the
            // query builder only. Comparing an imported file's text to a literal is fine.
            if (preg_match('/(?:->|::)(?:or)?[wW]here(?:Not)?(?:In)?\(\s*[\'"](?:\w+\.)?subtype[\'"]\s*,\s*\[?\s*[\'"]/', $line)) {
                $offenders[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Use AccountSubtype, or better a scope on Account: '.implode(', ', $offenders));
});

it('gives every document tax picker a direction', function () {
    // These lists legitimately go both ways, so they may offer every active code.
    $bothWays = [
        'journal/⚡form.blade.php',          // a manual entry can be a sale or a purchase
        'settings/lists/⚡items.blade.php',  // an item's default serves invoices and bills alike
        'settings/lists/⚡tax-codes.blade.php', // the tax code list itself
    ];

    $checked = 0;
    $offenders = [];

    foreach (File::allFiles(resource_path('views/pages')) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($path, $bothWays, true)) {
            continue;
        }

        // The body of taxCodeOptions() / taxCodes(), up to its closing brace.
        if (preg_match('/function (?:taxCodeOptions|taxCodes)\(\)[^{]*\{(.*?)\n    \}/s', $file->getContents(), $method) !== 1) {
            continue;
        }

        $checked++;

        if (preg_match('/offeredFor(?:Sales|Purchases)\(|forSales\(|forPurchases\(/', $method[1]) !== 1) {
            $offenders[] = $path;
        }
    }

    // A rename that stopped the scan from finding pickers mustn't read as a pass.
    expect($checked)->toBeGreaterThan(15)
        ->and($offenders)->toBe([], 'Use TaxCode::offeredForSales() or offeredForPurchases(), or allowlist it with a reason: '.implode(', ', $offenders));
});

it('passes a default tax code through its direction before a document form takes it', function () {
    // These lists legitimately go both ways, so any default fits.
    $bothWays = [
        'journal/⚡form.blade.php',
    ];

    $offenders = [];

    foreach (File::allFiles(resource_path('views/pages')) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($path, $bothWays, true)) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            // ['tax_code_id'] = $item->default_tax_code_id; reads a default raw.
            if (preg_match('/\[\'(?:secondary_)?tax_code_id\'\]\s*=\s*[^;]*default_(?:secondary_)?tax_code_id\s*;/', $line)
                && ! str_contains($line, 'defaultFor')
                && ! str_contains($line, 'usableDefaultTaxCodes')) {
                $offenders[] = $path.':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Use TaxCode::defaultForSales() or defaultForPurchases(): '.implode(', ', $offenders));
});

it('filters a status column through its enum, never a raw string', function () {
    $offenders = [];

    foreach (pickerGuardPhpFiles() as $file) {
        foreach (file($file->getPathname()) as $number => $line) {
            // ->where('status', 'posted'), ->where('status', '<>', 'void'), ->whereIn('x.status', ['draft', …])
            if (preg_match('/(?:->|::)(?:or)?[wW]here(?:Not)?(?:In)?\(\s*[\'"](?:\w+\.)?status[\'"]\s*,\s*(?:[\'"](?:=|!=|<>)[\'"]\s*,\s*)?\[?\s*[\'"]\w/', $line)) {
                $offenders[] = $file->getRelativePathname().':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], 'Use the status enum: '.implode(', ', $offenders));
});
