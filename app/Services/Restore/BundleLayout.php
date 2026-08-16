<?php

namespace App\Services\Restore;

/**
 * Locates the real root of a backup bundle's entries.
 *
 * A bundle exported by LineLedger is flat — `manifest.json` sits at the archive
 * root. But users frequently extract the download and re-compress the resulting
 * folder with macOS Finder ("Compress"), which nests every entry under a single
 * wrapping directory and sprinkles in `__MACOSX/` + AppleDouble (`._*`) cruft.
 * This helper collapses that back to a usable prefix so the inspector and
 * importer can read a re-zipped bundle as if it were flat.
 */
final class BundleLayout
{
    /**
     * The archive-relative prefix the bundle root sits under, or '' when the
     * manifest is already at the root.
     *
     * Tolerates exactly one wrapping directory and ignores macOS cruft. When
     * zero — or more than one — wrapping directories contain a manifest, it
     * returns '' (root) and lets the caller surface the missing-manifest error
     * rather than guessing.
     *
     * @param  iterable<string>  $names  Archive-relative entry names.
     */
    public static function rootPrefix(iterable $names): string
    {
        $wrappers = [];

        foreach ($names as $name) {
            $name = str_replace('\\', '/', (string) $name);

            if (self::isCruft($name)) {
                continue;
            }

            // Manifest at the root — the common, exported-flat case.
            if ($name === 'manifest.json') {
                return '';
            }

            // "<wrapper>/manifest.json", exactly one directory deep.
            if (preg_match('#^([^/]+)/manifest\.json$#', $name, $m) === 1) {
                $wrappers[$m[1]] = true;
            }
        }

        return count($wrappers) === 1 ? array_key_first($wrappers).'/' : '';
    }

    /**
     * macOS archiving artefacts that must never be treated as bundle content:
     * the `__MACOSX/` sidecar tree, `.DS_Store`, and AppleDouble `._*` files.
     */
    public static function isCruft(string $name): bool
    {
        $name = str_replace('\\', '/', $name);

        if ($name === '' || str_starts_with($name, '__MACOSX/')) {
            return true;
        }

        $base = basename($name);

        return $base === '.DS_Store' || str_starts_with($base, '._');
    }
}
