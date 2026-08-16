<?php

namespace App\Services\Reporting\Render;

use App\Models\Company;
use App\Models\MemorizedReport;
use App\Support\Reporting\RenderableReports;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Bundles a memorized report group into a single ZIP of per-report artifacts
 * (QBO "Export group as PDF"). Each memorized report is rendered with its own
 * saved settings through {@see ReportRenderer}; reports whose key has left the
 * caller's catalog or that can't render in the requested format are skipped.
 */
class ReportBundleBuilder
{
    public function __construct(
        private readonly ReportRenderer $renderer,
    ) {}

    /**
     * @param  Collection<int, MemorizedReport>  $memorizedReports
     * @param  list<string>  $availableKeys  report keys visible to the user (catalog gate)
     *
     * @throws RuntimeException when no report in the group is renderable
     */
    public function bundle(
        Company $company,
        Collection $memorizedReports,
        array $availableKeys,
        string $bundleName,
        string $format = 'pdf',
        bool $resolvePresets = false,
    ): RenderedArtifact {
        /** @var array<string, string> $files  filename within zip => bytes */
        $files = [];

        foreach ($memorizedReports as $report) {
            if (! in_array($report->report_key, $availableKeys, true)
                || ! RenderableReports::supports($report->report_key, $format)) {
                continue;
            }

            $artifact = $this->renderer->render(
                $company,
                $report->report_key,
                $report->settings ?? [],
                $format,
                $resolvePresets,
            );

            $files[$this->uniqueName($files, $artifact->filename, $report->name)] = $artifact->bytes;
        }

        if ($files === []) {
            throw new RuntimeException(
                'None of the reports in this group can be exported as '.strtoupper($format).'.',
            );
        }

        $slug = Str::slug($bundleName) ?: 'memorized';

        return new RenderedArtifact(
            bytes: $this->zip($files),
            filename: $slug.'-reports-'.$company->currentDateTime()->format('Y-m-d').'.zip',
            mime: 'application/zip',
        );
    }

    /**
     * Two memorized views of the same report with the same date range render to
     * the same filename; disambiguate with the memorized report's name, then a
     * numeric suffix.
     *
     * @param  array<string, string>  $files
     */
    private function uniqueName(array $files, string $filename, string $reportName): string
    {
        if (! array_key_exists($filename, $files)) {
            return $filename;
        }

        $slug = Str::slug($reportName);
        $candidate = $slug !== '' ? "{$slug}-{$filename}" : $filename;

        if (! array_key_exists($candidate, $files)) {
            return $candidate;
        }

        $dot = strrpos($candidate, '.');
        $base = $dot === false ? $candidate : substr($candidate, 0, $dot);
        $extension = $dot === false ? '' : substr($candidate, $dot);

        for ($i = 2; ; $i++) {
            $next = "{$base}-{$i}{$extension}";

            if (! array_key_exists($next, $files)) {
                return $next;
            }
        }
    }

    /**
     * @param  array<string, string>  $files  filename => bytes
     */
    private function zip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'report-bundle-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
