<?php

namespace App\Services\Reporting\Render;

use App\Models\Company;
use App\Models\ReportPackage;
use App\Services\Pdf\PdfMerger;
use App\Services\Reporting\PdfExporter;
use App\Support\Reporting\RenderableReports;
use App\Support\Reporting\ReportDatePresets;
use App\Support\Reporting\ReportSettings;
use App\Support\Storage\StorageDisks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds a management report package (QBO "Management Reports") into a single
 * professional PDF: cover page, optional preliminary text, table of contents
 * with page numbers, each report rendered for the package's period, and
 * optional end notes — concatenated via {@see PdfMerger}.
 */
class ManagementReportBuilder
{
    /**
     * The TOC is rendered as a single page; the page UI caps a package at this
     * many items so the entry list can't overflow onto a second page (which
     * would shift every computed page number by one).
     */
    public const MAX_ITEMS = 20;

    public function __construct(
        private readonly ReportRenderer $renderer,
        private readonly PdfExporter $exporter,
        private readonly PdfMerger $merger,
    ) {}

    /**
     * @throws RuntimeException when no item in the package is renderable
     */
    public function build(ReportPackage $package): RenderedArtifact
    {
        $company = $package->company;
        [$start, $end] = $this->resolvePeriod($package, $company);

        // Render every renderable report first — the TOC needs their page counts.
        /** @var list<array{label: string, bytes: string, pages: int}> $reports */
        $reports = [];

        foreach ($package->items as $item) {
            $entry = RenderableReports::get($item->report_key);

            if ($entry === null || ! in_array('pdf', $entry['formats'], true)) {
                Log::info('Management report package item skipped: not PDF-renderable.', [
                    'report_package_id' => $package->id,
                    'report_key' => $item->report_key,
                ]);

                continue;
            }

            $artifact = $this->renderer->render(
                $company,
                $item->report_key,
                $this->overlayPeriod($item->settings ?? [], $start, $end),
                'pdf',
                resolvePresets: false,
            );

            $reports[] = [
                'label' => $item->label ?: $entry['label'],
                'bytes' => $artifact->bytes,
                'pages' => $this->merger->pageCount($artifact->bytes),
            ];
        }

        if ($reports === []) {
            throw new RuntimeException('None of the reports in this package can be rendered as PDF.');
        }

        $period = $start->format('M j, Y').' – '.$end->format('M j, Y');
        $title = $package->title ?: $package->name;

        // Front matter before the TOC: cover, then optional preliminary text.
        /** @var list<string> $parts */
        $parts = [];

        if ($package->show_cover) {
            $parts[] = $this->exporter->raw('pdf.reports.package-cover', [
                'company' => $company,
                'title' => $title,
                'subtitle' => $package->subtitle,
                'period' => $period,
                'logoData' => $package->show_logo ? $this->logoData($company) : null,
            ]);
        }

        if (filled($package->preliminary_text)) {
            $parts[] = $this->exporter->raw('pdf.reports.package-notes', [
                'company' => $company,
                'heading' => __('Preliminary Notes'),
                'text' => (string) $package->preliminary_text,
            ]);
        }

        // Page numbers: front matter (cover + preliminary + the one-page TOC),
        // then each report starts after the previous one ends.
        $frontPages = array_sum(array_map($this->merger->pageCount(...), $parts))
            + ($package->show_toc ? 1 : 0);

        $startPage = $frontPages + 1;
        $entries = [];

        foreach ($reports as $report) {
            $entries[] = ['label' => $report['label'], 'page' => $startPage];
            $startPage += $report['pages'];
        }

        if ($package->show_toc) {
            $parts[] = $this->exporter->raw('pdf.reports.package-toc', [
                'company' => $company,
                'entries' => $entries,
            ]);
        }

        foreach ($reports as $report) {
            $parts[] = $report['bytes'];
        }

        if (filled($package->end_notes)) {
            $parts[] = $this->exporter->raw('pdf.reports.package-notes', [
                'company' => $company,
                'heading' => __('End Notes'),
                'text' => (string) $package->end_notes,
            ]);
        }

        $slug = Str::slug($package->name) ?: 'management-reports';

        return new RenderedArtifact(
            bytes: $this->merger->merge(...$parts),
            filename: $slug.'-'.$start->toDateString().'-'.$end->toDateString().'.pdf',
            mime: 'application/pdf',
        );
    }

    /**
     * The package's period overrides whatever dates an item's settings snapshot
     * carries: range reports take startDate/endDate, as-of reports take the
     * period end. All four keys are set — {@see ReportSettings::apply}
     * only assigns properties the target component actually declares.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function overlayPeriod(array $settings, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $settings['startDate'] = $start->toDateString();
        $settings['endDate'] = $end->toDateString();
        $settings['preset'] = 'custom';
        $settings['asOf'] = $end->toDateString();
        $settings['asOfPreset'] = 'custom';

        return $settings;
    }

    /**
     * Resolve the package's period preset against the company's calendar.
     * An unresolvable preset (e.g. 'custom' from old data) falls back to
     * last month — a package always has a concrete period.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriod(ReportPackage $package, Company $company): array
    {
        $today = $company->currentDateTime();
        $lastMonth = $today->subMonthNoOverflow();

        return ReportDatePresets::resolve($package->period_preset, (int) $company->fiscal_year_start_month, $today)
            ?? [$lastMonth->startOfMonth(), $lastMonth->endOfMonth()];
    }

    /**
     * The company logo as a base64 data URI for reliable embedding in dompdf,
     * or null when no logo is set.
     */
    private function logoData(Company $company): ?string
    {
        $disk = Storage::disk(StorageDisks::logos());

        if (! $company->logo_path || ! $disk->exists($company->logo_path)) {
            return null;
        }

        $contents = $disk->get($company->logo_path);
        $mime = $disk->mimeType($company->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
