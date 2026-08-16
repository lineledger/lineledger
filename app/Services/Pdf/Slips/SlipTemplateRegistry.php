<?php

namespace App\Services\Pdf\Slips;

/**
 * Resolves the official, year-specific slip template PDF a slip type renders
 * onto. Two locations, operator override first:
 *
 *   storage/app/slip-templates/{year}/{type}.pdf   (installed per deployment)
 *   resources/pdf-templates/slips/{year}/{type}.pdf (shipped with the app)
 *
 * Templates must be FLATTENED copies of the official forms — CRA's fillable
 * PDFs are encrypted XFA documents FPDI's free parser can't import, so run
 * them through Ghostscript first:
 *
 *   gs -q -o t4.pdf -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 t4-fill-25e.pdf
 *
 * Resolution is EXACT-YEAR only: box layouts change between years (2024 added
 * 16A/17A and 45), and silently rendering onto a prior year's official-looking
 * form would be worse than the honest facsimile fallback.
 */
final class SlipTemplateRegistry
{
    public const T4 = 't4';

    public const T4A = 't4a';

    public const RL1 = 'rl1';

    public function path(string $type, int $year): ?string
    {
        foreach ([
            storage_path("app/slip-templates/{$year}/{$type}.pdf"),
            resource_path("pdf-templates/slips/{$year}/{$type}.pdf"),
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function installed(string $type, int $year): bool
    {
        return $this->path($type, $year) !== null;
    }
}
