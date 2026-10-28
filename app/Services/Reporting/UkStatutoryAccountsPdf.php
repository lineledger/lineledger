<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Services\Pdf\PdfMerger;
use App\Support\Statutory\StatutoryAccountsProfile;
use App\Support\Statutory\StatutoryFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Renders a set of statutory accounts as one PDF laid out the way small-company
 * accounts are presented: a cover, company information and contents page, then
 * numbered pages — directors' report, the primary statements and the notes —
 * each headed with the company name, page number and statement title.
 *
 * Each section is rendered on its own with dompdf so its page count is known;
 * the contents page and "the notes on pages x to y" references are then filled
 * in, everything is merged with FPDI, and the running header is stamped on.
 */
class UkStatutoryAccountsPdf
{
    /** Renders before the page numbering is taken as settled. */
    private const MAX_PASSES = 4;

    public function __construct(private readonly PdfMerger $merger) {}

    /**
     * @param  array<string, mixed>  $report  from {@see UkStatutoryAccountsBuilder::build()}
     */
    public function render(Company $company, StatutoryAccountsProfile $profile, array $report): string
    {
        $data = $this->viewData($company, $profile, $report);
        $sections = $this->sections($profile, $data);

        // The page references ("the notes on pages x to y", the directors'
        // report pointing at the income statement) change what they sit in, so
        // a nearly full section can gain or lose a page once they are filled
        // in. Start from stand-ins as long as any real reference and render
        // until the page counts stop moving.
        $data['notesRange'] = __('pages :a to :b', ['a' => 888, 'b' => 888]);
        $data['pages'] = array_fill_keys(array_keys($sections), 888);
        $counts = null;

        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $bodies = [];
            foreach ($sections as $key => $section) {
                $bodies[$key] = $this->renderSection($section['view'], $data);
            }

            $rendered = array_map(fn (string $bytes) => $this->merger->pageCount($bytes), $bodies);
            if ($rendered === $counts) {
                break;
            }

            $counts = $rendered;
            $sections = $this->number($sections, $counts);
            $data['notesRange'] = $sections['notes']['first'] === $sections['notes']['last']
                ? __('page :n', ['n' => $sections['notes']['first']])
                : __('pages :a to :b', ['a' => $sections['notes']['first'], 'b' => $sections['notes']['last']]);
            $data['pages'] = array_map(fn ($s) => $s['first'], $sections);
        }

        $data['contents'] = collect($sections)->map(fn ($s) => [
            'title' => $s['title'],
            'pages' => $s['first'] === $s['last'] ? (string) $s['first'] : $s['first'].' – '.$s['last'],
        ])->values()->all();

        $front = $this->renderSection('pdf.statutory.front', $data);

        return $this->assemble($company, $front, $bodies, $sections);
    }

    public function filename(Company $company, array $report): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $company->name), '-');

        return $slug.'-Accounts-'.$report['end']->format('Y').'.pdf';
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function viewData(Company $company, StatutoryAccountsProfile $profile, array $report): array
    {
        return [
            'company' => $company,
            'companyName' => mb_strtoupper($company->legal_name ?: $company->name),
            'profile' => $profile,
            'r' => $report,
            'micro' => $profile->isMicro(),
            'cy' => StatutoryFormat::year($report['end']),
            'py' => StatutoryFormat::year($report['prior_end']),
            'hasPrior' => (bool) $report['has_prior'],
            'yearEnded' => StatutoryFormat::dateHtml($report['end']),
            'notes' => $this->noteNumbers($profile, $report),
            'notesRange' => '',
            'pages' => [],
            'contents' => [],
        ];
    }

    /**
     * The notes that apply to these accounts, numbered in presentation order.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, int>
     */
    public function noteNumbers(StatutoryAccountsProfile $profile, array $report): array
    {
        $sfp = $report['sfp'];
        $either = fn (string $key): bool => ($sfp['current'][$key] ?? 0) !== 0 || ($sfp['prior'][$key] ?? 0) !== 0;

        if ($profile->isMicro()) {
            $keys = array_filter([
                'policies' => true,
                'employees' => true,
                'directors_advances' => true,
                'guarantees' => $profile->guarantees !== '',
            ]);
        } else {
            $keys = array_filter([
                'general' => true,
                'policies' => true,
                'judgements' => $profile->judgementsNote !== '',
                'employees' => $profile->averageEmployees !== null,
                'taxation' => $profile->taxationNote !== '' || ($report['sci']['current']['taxation'] ?? 0) !== 0,
                'tangible' => $report['tangible']['classes'] !== [],
                'debtors' => $either('debtors'),
                'creditors_within' => $either('creditors_within'),
                'creditors_after' => $either('creditors_after'),
                'share_capital' => $profile->authorisedShares !== '' || $profile->issuedShares !== '' || $either('share_capital'),
                'directors_advances' => $profile->directorsAdvances !== '',
                'related_parties' => $profile->relatedParties !== '',
            ]);
        }

        $n = 0;

        return array_map(function () use (&$n) {
            return ++$n;
        }, $keys);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{title: string, subtitle: string, view: string}>
     */
    private function sections(StatutoryAccountsProfile $profile, array $data): array
    {
        $yearEnded = __('For the year ended :date', ['date' => StatutoryFormat::date($data['r']['end'])]);
        $asAt = __('As at :date', ['date' => StatutoryFormat::date($data['r']['end'])]);

        $sections = [];

        if (! $profile->isMicro() || $profile->includeDirectorsReport) {
            $sections['directors_report'] = ['title' => __("Directors' Report"), 'subtitle' => $yearEnded, 'view' => 'pdf.statutory.directors-report'];
        }

        if (! $profile->isMicro() || $profile->includeProfitAndLossForMicro) {
            $sections['income_statement'] = [
                'title' => $profile->isMicro() ? __('Profit and Loss Account') : __('Statement of Comprehensive Income'),
                'subtitle' => $yearEnded,
                'view' => 'pdf.statutory.income-statement',
            ];
        }

        $sections['balance_sheet'] = [
            'title' => $profile->isMicro() ? __('Balance Sheet') : __('Statement of Financial Position'),
            'subtitle' => $asAt,
            'view' => 'pdf.statutory.balance-sheet',
        ];

        if (! $profile->isMicro()) {
            $sections['changes_in_equity'] = ['title' => __('Statement of Changes in Equity'), 'subtitle' => $yearEnded, 'view' => 'pdf.statutory.changes-in-equity'];
        }

        $sections['notes'] = ['title' => __('Notes to the financial statements'), 'subtitle' => $yearEnded, 'view' => 'pdf.statutory.notes'];

        return $sections;
    }

    /**
     * Give each section its first and last page number from its page count.
     *
     * @param  array<string, array<string, mixed>>  $sections
     * @param  array<string, int>  $counts
     * @return array<string, array<string, mixed>>
     */
    private function number(array $sections, array $counts): array
    {
        $page = 1;

        foreach ($sections as $key => $section) {
            $sections[$key]['first'] = $page;
            $sections[$key]['last'] = $page + $counts[$key] - 1;
            $page += $counts[$key];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderSection(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)->setPaper('a4', 'portrait')->output();
    }

    /**
     * @param  array<string, string>  $bodies
     * @param  array<string, array<string, mixed>>  $sections
     */
    private function assemble(Company $company, string $front, array $bodies, array $sections): string
    {
        $pdf = new Fpdi('P', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator('LineLedger');
        $pdf->SetAuthor($company->name);
        $pdf->SetTitle(($company->legal_name ?: $company->name).' — '.__('Financial Statements'));

        $this->import($pdf, $front);

        $companyName = mb_strtoupper($company->legal_name ?: $company->name);

        foreach ($sections as $key => $section) {
            $this->import($pdf, $bodies[$key], function (Fpdi $pdf, int $index) use ($section, $companyName) {
                $this->stampHeader($pdf, $companyName, $section['first'] + $index, $section['title'], $section['subtitle']);
            });
        }

        return $pdf->Output('accounts.pdf', 'S');
    }

    private function import(Fpdi $pdf, string $bytes, ?callable $afterPage = null): void
    {
        $count = $pdf->setSourceFile(StreamReader::createByString($bytes));

        for ($i = 1; $i <= $count; $i++) {
            $template = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            if ($afterPage !== null) {
                $afterPage($pdf, $i - 1);
            }
        }
    }

    /**
     * The running header on every numbered page: company name and page number,
     * then the statement title and period, ruled off.
     */
    private function stampHeader(Fpdi $pdf, string $companyName, int $page, string $title, string $subtitle): void
    {
        $left = 18;
        $right = 192;

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 12.5);
        $pdf->SetXY($left, 12);
        $pdf->Cell(130, 6, $companyName, 0, 0, 'L');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY($right - 40, 12.6);
        $pdf->Cell(40, 6, __('Page :n', ['n' => $page]), 0, 0, 'R');

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($left, 20);
        $pdf->Cell(160, 4.5, $title, 0, 2, 'L');
        $pdf->Cell(160, 4.5, $subtitle, 0, 0, 'L');

        $pdf->SetLineWidth(0.25);
        $pdf->Line($left, 31, $right, 31);
    }
}
