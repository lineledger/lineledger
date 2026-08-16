<?php

namespace App\Services\Reporting\Render;

use App\Models\Company;
use App\Support\Reporting\RenderableReports;
use App\Support\Reporting\ReportSettings;
use Closure;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Renders a report to PDF/XLSX bytes outside a Livewire request by driving
 * the report's real page component (the ProofArtifactWriter pattern), so the
 * artifact is byte-identical to the page's own Download button. Used by
 * report email, scheduled sends, print, and memorized-group bundles.
 */
class ReportRenderer
{
    /**
     * @param  array<string, mixed>  $settings  a ReportSettings/Memorizable snapshot
     * @param  bool  $resolvePresets  re-resolve a saved date preset against today
     *                                (QBO semantics: a scheduled "Last Month" report
     *                                always covers the most recent month). Leave off
     *                                to reproduce exactly what the user saw.
     */
    public function render(
        Company $company,
        string $reportKey,
        array $settings,
        string $format,
        bool $resolvePresets = false,
    ): RenderedArtifact {
        $entry = RenderableReports::get($reportKey);

        if ($entry === null || ! in_array($format, $entry['formats'], true)) {
            throw new InvalidArgumentException("Report [{$reportKey}] cannot be rendered as [{$format}].");
        }

        return $this->withCompany($company, function () use ($company, $entry, $settings, $format, $resolvePresets): RenderedArtifact {
            $component = Livewire::new($entry['component']);
            $component->mount($company);

            ReportSettings::apply($component, $settings);

            if ($resolvePresets) {
                $this->reResolvePresets($component);
            }

            $response = match ($format) {
                'pdf' => $component->exportPdf(),
                'xlsx' => $component->exportXlsx(),
                default => throw new InvalidArgumentException("Unsupported report format [{$format}]."),
            };

            return $this->artifactFromResponse($response, $format);
        });
    }

    /**
     * A saved snapshot stores both the preset and the dates it resolved to at
     * save time; the components' own updated-hooks re-resolve the preset
     * against the company's current date, fiscal-year aware.
     */
    private function reResolvePresets(object $component): void
    {
        if (property_exists($component, 'preset')
            && $component->preset !== 'custom'
            && method_exists($component, 'updatedPreset')) {
            $component->updatedPreset();
        }

        if (property_exists($component, 'asOfPreset')
            && $component->asOfPreset !== 'custom'
            && method_exists($component, 'updatedAsOfPreset')) {
            $component->updatedAsOfPreset();
        }
    }

    private function artifactFromResponse(BinaryFileResponse $response, string $format): RenderedArtifact
    {
        $path = $response->getFile()->getPathname();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return new RenderedArtifact(
            bytes: $bytes,
            filename: $this->dispositionFilename($response) ?? 'report.'.$format,
            mime: $format === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
    }

    private function dispositionFilename(BinaryFileResponse $response): ?string
    {
        $disposition = (string) $response->headers->get('content-disposition', '');

        return preg_match('/filename="?([^";]+)"?/', $disposition, $matches) ? $matches[1] : null;
    }

    /**
     * Reports query through the CompanyScope, so the company MUST be bound
     * while the component runs — and restored after, whether the caller is a
     * queue worker (nothing bound) or a request (another company bound).
     */
    private function withCompany(Company $company, Closure $callback): mixed
    {
        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            return $callback();
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }
}
