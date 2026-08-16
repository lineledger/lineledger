<?php

namespace App\Concerns;

use App\Services\Reporting\PdfExporter;
use App\Support\Reporting\ChartContext;
use App\Support\Reporting\ComparisonPeriod;
use App\Support\Reporting\StatementLabels;
use Illuminate\Support\Str;

/**
 * Chart export plumbing shared by the report pages and the dashboard. The host
 * builds its own chart series (via App\Support\Reporting\ReportChartBuilder) and
 * the shared <x-reports.chart-panel> renders them; this trait only turns a chart
 * image captured on the client into a clean, branded PDF.
 *
 * The host must expose a public Company $company.
 */
trait HasReportChart
{
    /**
     * Embed a client-rendered chart PNG into the branded report PDF layout and
     * return it as a download. $title/$period are echoed into the PDF header.
     * (Printing is handled entirely client-side — see resources/js/charts.js.)
     *
     * The image is a base64 PNG data URL captured from the chart canvas. It is
     * the user's own data round-tripped to their own PDF, but we still validate
     * it: PNG prefix, strict base64, a 3MB ceiling, and a real-PNG content sniff.
     */
    public function exportChartPdf(string $image, string $title = '', string $period = '')
    {
        $binary = $this->decodeChartImage($image);

        if ($binary === null) {
            return null;
        }

        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 200) : __('Chart');
        $period = mb_substr(trim($period), 0, 200);

        $data = [
            'company' => $this->company,
            'title' => $title,
            'period' => $period !== '' ? $period : null,
            'imageData' => 'data:image/png;base64,'.base64_encode($binary),
        ];

        $filename = (Str::slug($title) ?: 'chart').'.pdf';

        return app(PdfExporter::class)->download('pdf.charts.chart', $data, $filename);
    }

    /**
     * Standard chart context for the report pages — reads the comparison state
     * and home currency the component already exposes. (The dashboard builds its
     * own context, as it has no comparison column.)
     */
    protected function chartContext(): ChartContext
    {
        $comparison = (bool) $this->showComparison;

        return new ChartContext(
            comparison: $comparison,
            periodLabel: __('Current'),
            priorLabel: $comparison ? __(ComparisonPeriod::label($this->comparisonBasis)) : '',
            currency: $this->company->currency_code ?? 'USD',
            labels: StatementLabels::for($this->company),
        );
    }

    /** Validate and decode the data URL into raw PNG bytes, or null if invalid. */
    private function decodeChartImage(string $dataUrl): ?string
    {
        $prefix = 'data:image/png;base64,';

        if (! str_starts_with($dataUrl, $prefix)) {
            return null;
        }

        $binary = base64_decode(substr($dataUrl, strlen($prefix)), true);

        if ($binary === false || $binary === '' || strlen($binary) > 3 * 1024 * 1024) {
            return null;
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
            return null;
        }

        return $binary;
    }
}
