<?php

namespace App\Services\Reporting\Render;

use App\Models\Company;
use App\Models\ReportPackage;
use App\Services\Pdf\PdfMerger;
use App\Services\Reporting\PdfExporter;
use App\Support\Reporting\ComparisonPeriod;
use App\Support\Reporting\RenderableReports;
use App\Support\Reporting\ReportDatePresets;
use App\Support\Reporting\ReportSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
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
        [$preset, $start, $end] = $this->resolvePeriod($package, $company);
        $comparisonBasis = $this->comparisonBasis($package);

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
                $this->overlayPeriod($item->settings ?? [], $preset, $start, $end, $comparisonBasis),
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
                'comparison' => ComparisonPeriod::label($comparisonBasis),
                'logoData' => $package->show_logo ? $company->documentLogoDataUri() : null,
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
     * The package's period and comparison override whatever an item's settings
     * snapshot carries: range reports take startDate/endDate, as-of reports take
     * the period end, and reports that compare take the package's basis.
     *
     * The real preset key is passed through (not 'custom') so ComparisonPeriod
     * can resolve the true preceding month, quarter, or year for a "prior
     * period" comparison. Nothing re-resolves it against today: ReportRenderer
     * is called with resolvePresets: false, and the components' preset hooks
     * only fire on Livewire updates. A package preset never ends after today
     * ({@see ReportDatePresets::packageOptions()}), so an as-of report is as at
     * the period end or as of today — never a future date, which would age
     * every receivable into 90+.
     *
     * All keys are set — {@see ReportSettings::apply} only assigns properties
     * the target component actually declares, so reports without a comparison
     * (aging, trial balance, …) simply ignore the basis.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function overlayPeriod(array $settings, string $preset, CarbonImmutable $start, CarbonImmutable $end, string $comparisonBasis): array
    {
        $settings['startDate'] = $start->toDateString();
        $settings['endDate'] = $end->toDateString();
        $settings['preset'] = $preset;
        $settings['asOf'] = $end->toDateString();
        $settings['asOfPreset'] = $preset;
        $settings['comparisonBasis'] = $comparisonBasis;

        return $settings;
    }

    /**
     * Resolve the package's period preset against the company's calendar.
     * Legacy full-period presets are normalized to their to-date twin first
     * ({@see ReportDatePresets::packagePreset()}); an unresolvable preset (e.g.
     * 'custom' from old data) falls back to last month — a package always has
     * a concrete period.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable} [preset, start, end]
     */
    private function resolvePeriod(ReportPackage $package, Company $company): array
    {
        $today = $company->currentDateTime();
        $preset = ReportDatePresets::packagePreset($package->period_preset);
        $range = ReportDatePresets::resolve($preset, (int) $company->fiscal_year_start_month, $today);

        if ($range === null) {
            $lastMonth = $today->subMonthNoOverflow();

            return ['last_month', $lastMonth->startOfMonth(), $lastMonth->endOfMonth()];
        }

        return [$preset, $range[0], $range[1]];
    }

    /**
     * The package's comparison basis, sanitized to a known ComparisonPeriod
     * value so an unexpected stored string can't leak into report settings.
     */
    private function comparisonBasis(ReportPackage $package): string
    {
        $basis = (string) $package->comparison_basis;

        return ComparisonPeriod::isOn($basis) ? $basis : ComparisonPeriod::Off;
    }
}
