<?php

namespace App\Support\Translation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Collects static __('…') / __("…") source keys under app/ and resources/,
 * plus Livewire #[Title] strings which partials/head.blade.php passes through __().
 */
final class KeyExtractor
{
    /**
     * Sources that only ever render in English, so their strings are not
     * translation keys: UK statutory accounts are delivered to Companies House
     * in English (StatutoryAccountsPdfController renders them under 'en').
     */
    private const ENGLISH_ONLY = [
        'app/Services/Reporting/UkStatutoryAccountsDocx.php',
        'app/Services/Reporting/UkStatutoryAccountsPdf.php',
        'resources/views/pdf/statutory/',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return self::collect(static fn (string $contents): array => self::keysIn($contents));
    }

    /**
     * Keys that count as used in lang/*.json: static __() plus #[Title('…')].
     *
     * @return list<string>
     */
    public static function referencedKeys(): array
    {
        return self::collect(static fn (string $contents): array => [
            ...self::keysIn($contents),
            ...self::titleKeysIn($contents),
        ]);
    }

    /**
     * @return list<string>
     */
    public static function keysIn(string $contents): array
    {
        preg_match_all('/(?:__|trans_choice)\(\s*([\'"])((?:\\\\.|(?!\1).)*?)\1/s', $contents, $matches);

        $keys = [];
        foreach ($matches[2] as $raw) {
            $keys[] = stripcslashes($raw);
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function titleKeysIn(string $contents): array
    {
        preg_match_all('/\bTitle\(\s*([\'"])((?:\\\\.|(?!\1).)*?)\1/s', $contents, $matches);

        $keys = [];
        foreach ($matches[2] as $raw) {
            $keys[] = stripcslashes($raw);
        }

        return $keys;
    }

    /**
     * @param  callable(string): list<string>  $extract
     * @return list<string>
     */
    private static function collect(callable $extract): array
    {
        $found = [];

        foreach (['app', 'resources'] as $dir) {
            $root = base_path($dir);
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $name = $file->getFilename();
                if (! str_ends_with($name, '.php') && ! str_ends_with($name, '.blade.php')) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if (array_any(self::ENGLISH_ONLY, fn (string $prefix): bool => str_starts_with($relative, $prefix))) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }

                foreach ($extract($contents) as $key) {
                    $found[$key] = true;
                }
            }
        }

        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }

    /**
     * Acronyms, symbols, and other keys that stay identical in French.
     *
     * @return list<string>
     */
    public static function identityKeys(): array
    {
        return [
            '—', '–', '-', '•', '·', '%', '$', '#', '/', ':', 'h', 'hr', 'mo', 'no',
            'AI', 'API', 'CSV', 'PDF', 'XML', 'JSON', 'HTML', 'HTTP', 'HTTPS', 'URL',
            'SKU', 'ID', 'IP', 'CC', 'PO', 'GL', 'AR', 'AP', 'FX', 'OCR', 'MCP', 'CPA',
            'GST', 'HST', 'PST', 'RST', 'TPS', 'TVH', 'TVQ',
            'CPP', 'CPP2', 'EI', 'QPP', 'QPP2', 'QPIP', 'T4', 'T4A', 'RL-1', 'PD7A',
            'ROE', 'T4127', 'T3010', 'GIFI', 'SIN', 'BN', 'CRA', 'RQ', 'WCB', 'CNESST',
            'QHSF', 'WSDRF', 'CCA', 'UCC', 'FIFO', 'ASNPO', '1099',
            'Stripe', 'QuickBooks', 'OAuth', 'WebAuthn', 'LineLedger', 'Mailpit',
            'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat',
            'T4', 'T4A',
        ];
    }
}
