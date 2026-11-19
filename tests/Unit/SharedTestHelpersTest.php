<?php

/**
 * A helper function defined in one test file and called from another works only
 * while both run in one process with the defining file loaded first. Under
 * `--parallel` (CI) paratest hands files to separate workers, and the caller
 * fails with "Call to undefined function" on whichever files land apart that
 * run. Helpers that more than one file calls belong in tests/Pest.php.
 */
it('keeps helpers that several test files call in tests/Pest.php', function () {
    $root = dirname(__DIR__);

    // Names called as plain functions: tokenized, so strings and comments never
    // count, and methods (->x(), ::x()), definitions and `new X(` are skipped.
    $functionCalls = static function (string $source): array {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $called = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }

            $called[ltrim($token[1], '\\')] = true;
        }

        return $called;
    };

    $sources = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && $file->getPathname() !== $root.'/Pest.php') {
            $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    $calls = array_map($functionCalls, $sources);

    $misplaced = [];
    foreach ($sources as $definedIn => $source) {
        preg_match_all('/^function\s+(\w+)\s*\(/m', $source, $matches);

        foreach ($matches[1] as $helper) {
            foreach ($calls as $calledFrom => $called) {
                if ($calledFrom !== $definedIn && isset($called[$helper])) {
                    $misplaced[] = "{$helper}() is defined in tests/{$definedIn} but called from tests/{$calledFrom}";
                }
            }
        }
    }

    expect(count($sources))->toBeGreaterThan(100)
        ->and($misplaced)->toBe([]);
});
