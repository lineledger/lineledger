<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Support\Statutory\StatutoryAccountsProfile as Profile;
use App\Support\Statutory\StatutoryFormat as F;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use PhpOffice\PhpWord\ComplexType\TblWidth;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * The statutory accounts as an editable Word document, laid out like the PDF
 * ({@see UkStatutoryAccountsPdf}) from the same figures: cover, company
 * information and contents, then the numbered sections with a running header
 * (company name, page number, statement title and period).
 *
 * Page numbers are live Word fields — PAGE in the headers, PAGEREF to bookmarks
 * on the contents page and in "the notes on pages x to y" — so they stay right
 * however the text is edited. Word refreshes them when the file is opened.
 */
class UkStatutoryAccountsDocx
{
    private const FONT = 'Arial';

    private const SIZE = 9;

    /** Text width on A4 with 18 mm side margins, in twips. */
    private const WIDTH = 9865;

    private const NUM = 1250;

    private const NOTE = 700;

    private PhpWord $word;

    /** @var array<string, mixed> */
    private array $d;

    public function __construct(private readonly UkStatutoryAccountsPdf $pdf) {}

    /**
     * @param  array<string, mixed>  $report  from {@see UkStatutoryAccountsBuilder::build()}
     */
    public function render(Company $company, Profile $profile, array $report): string
    {
        $this->d = $this->pdf->viewData($company, $profile, $report);

        // Company names, captions and note text are free text ("Directors'
        // report & Financial Statements"): have PHPWord escape them as XML.
        Settings::setOutputEscapingEnabled(true);

        $this->word = new PhpWord;
        $this->word->setDefaultFontName(self::FONT);
        $this->word->setDefaultFontSize(self::SIZE);
        $this->word->setDefaultParagraphStyle(['spaceAfter' => 0, 'spaceBefore' => 0, 'spacing' => 0]);
        $this->word->getSettings()->setUpdateFields(true);
        $this->word->getDocInfo()->setCreator('LineLedger')->setTitle($this->d['companyName'].' — '.__('Financial Statements'));

        $sections = $this->sectionList();

        $this->cover();
        $this->information();
        $this->contents($sections);

        $first = true;
        foreach ($sections as $key => $s) {
            $section = $this->numberedSection($s['title'], $s['subtitle'], $first, in_array($key, ['income_statement', 'balance_sheet', 'changes_in_equity'], true), $key === 'income_statement' && ! $this->d['micro']);
            $first = false;
            // The bookmark sits inside the section's first paragraph: one placed
            // between paragraphs lands before the page break and PAGEREF then
            // reports the previous page.
            $section->addTextRun(['spaceAfter' => 0])->addBookmark('ll_'.$key);
            $this->{$s['method']}($section);

            if ($key === 'notes') {
                $section->addTextRun(['spaceAfter' => 0])->addBookmark('ll_notes_end');
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'llsa');
        IOFactory::createWriter($this->word, 'Word2007')->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Centimetres as whole twips, which is what Word stores.
     */
    private static function cm(float $cm): int
    {
        return (int) round(Converter::cmToTwip($cm));
    }

    public function filename(Company $company, array $report): string
    {
        return preg_replace('/\.pdf$/', '.docx', $this->pdf->filename($company, $report));
    }

    /**
     * @return array<string, array{title: string, subtitle: string, method: string}>
     */
    private function sectionList(): array
    {
        $micro = $this->d['micro'];
        $profile = $this->d['profile'];
        $end = F::date($this->d['r']['end']);
        $yearEnded = __('For the year ended :date', ['date' => $end]);

        $out = [];
        if (! $micro || $profile->includeDirectorsReport) {
            $out['directors_report'] = ['title' => __("Directors' Report"), 'subtitle' => $yearEnded, 'method' => 'directorsReport'];
        }
        if (! $micro || $profile->includeProfitAndLossForMicro) {
            $out['income_statement'] = ['title' => $micro ? __('Profit and Loss Account') : __('Statement of Comprehensive Income'), 'subtitle' => $yearEnded, 'method' => 'incomeStatement'];
        }
        $out['balance_sheet'] = ['title' => $micro ? __('Balance Sheet') : __('Statement of Financial Position'), 'subtitle' => __('As at :date', ['date' => $end]), 'method' => 'balanceSheet'];
        if (! $micro) {
            $out['changes_in_equity'] = ['title' => __('Statement of Changes in Equity'), 'subtitle' => $yearEnded, 'method' => 'changesInEquity'];
        }
        $out['notes'] = ['title' => __('Notes to the financial statements'), 'subtitle' => $yearEnded, 'method' => 'notes'];

        return $out;
    }

    // ───────────────────────── Front matter ─────────────────────────

    private function frontSection(): Section
    {
        return $this->word->addSection([
            'paperSize' => 'A4',
            'marginTop' => self::cm(1.4), 'marginBottom' => self::cm(1.4),
            'marginLeft' => self::cm(1.4), 'marginRight' => self::cm(1.4),
            'breakType' => 'nextPage',
        ]);
    }

    private function cover(): void
    {
        $section = $this->frontSection();
        $profile = $this->d['profile'];
        $what = (! $this->d['micro'] || $profile->includeDirectorsReport) ? __("Directors' report & Financial Statements") : __('Financial Statements');

        $table = $section->addTable(['borderSize' => 18, 'borderColor' => '000000', 'borderStyle' => 'double', 'width' => 100 * 50, 'unit' => 'pct']);
        $table->addRow(self::cm(26.2), ['exactHeight' => true]);
        $cell = $table->addCell(self::cm(18.2), ['valign' => 'center', 'borderSize' => 18, 'borderStyle' => 'double']);
        $center = ['alignment' => Jc::CENTER];

        foreach (explode("\n", wordwrap($this->d['companyName'], 22)) as $line) {
            $cell->addText($line, ['size' => 16], $center);
        }
        $cell->addTextBreak();
        $cell->addText($what, ['size' => 12], $center);
        $cell->addText(__('For the year ended'), ['size' => 12], $center);
        $cell->addTextBreak();
        $this->dateRun($cell->addTextRun($center), $this->d['r']['end'], ['size' => 12]);
    }

    private function information(): void
    {
        $section = $this->frontSection();
        $profile = $this->d['profile'];

        $this->frontTitle($section, __('Company Information'));

        $rows = [];
        if ($profile->directors !== []) {
            $rows[] = [count($profile->directors) === 1 ? __('Director') : __('Directors'), array_column($profile->directors, 'name')];
        }
        if ($profile->secretary !== '') {
            $rows[] = [__('Secretary'), [$profile->secretary]];
        }
        if ($profile->companyNumber !== '') {
            $rows[] = [__('Company Number'), [$profile->companyNumber.($profile->isIsleOfMan() ? ' ('.__('Isle of Man').')' : '')]];
        }
        if ($profile->registeredOffice !== '') {
            $rows[] = [__('Registered Office'), preg_split('/\r?\n/', $profile->registeredOffice)];
        }

        $table = $section->addTable(['cellMargin' => 0]);
        foreach ($rows as [$label, $lines]) {
            $table->addRow();
            $table->addCell(self::cm(1.2));
            $table->addCell(self::cm(6.6))->addText($label, ['size' => 9.6]);
            $cell = $table->addCell(self::cm(9));
            foreach ($lines as $line) {
                $cell->addText(trim($line), ['size' => 9.6]);
            }
            $cell->addText('', [], ['spaceAfter' => 200]);
        }
    }

    /**
     * @param  array<string, array{title: string, subtitle: string, method: string}>  $sections
     */
    private function contents(array $sections): void
    {
        $section = $this->frontSection();
        $this->frontTitle($section, __('Contents'));

        $table = $section->addTable(['cellMargin' => 0]);
        $table->addRow();
        $table->addCell(self::cm(13.4));
        $table->addCell(self::cm(3))->addText(__('Page'), ['bold' => true, 'size' => 9.6], ['alignment' => Jc::CENTER, 'spaceAfter' => 220]);

        foreach ($sections as $key => $s) {
            $table->addRow();
            $table->addCell(self::cm(13.4))->addText('          '.$s['title'], ['size' => 9.6], ['spaceAfter' => 220]);
            $field = $key === 'notes' ? '{PAGEREF ll_notes \h} – {PAGEREF ll_notes_end \h}' : '{PAGEREF ll_'.$key.' \h}';
            $table->addCell(self::cm(3))->addPreserveText($field, ['size' => 9.6], ['alignment' => Jc::CENTER, 'spaceAfter' => 220]);
        }
    }

    private function frontTitle(Section $section, string $subtitle): void
    {
        $section->addText('', [], ['spaceAfter' => 300]);
        $section->addText($this->d['companyName'], ['size' => 13], ['alignment' => Jc::CENTER, 'spaceAfter' => 480]);
        $section->addText($subtitle, ['size' => 11], ['alignment' => Jc::CENTER, 'spaceAfter' => 760]);
    }

    // ───────────────────────── Numbered sections ─────────────────────────

    private function numberedSection(string $title, string $subtitle, bool $restartNumbering, bool $notesFooter, bool $gainsFooter): Section
    {
        $style = [
            'paperSize' => 'A4',
            'marginTop' => self::cm(3.6), 'marginBottom' => self::cm(2.0),
            'marginLeft' => self::cm(1.8), 'marginRight' => self::cm(1.8),
            'headerHeight' => self::cm(1.1), 'footerHeight' => self::cm(0.9),
            'breakType' => 'nextPage',
        ];
        if ($restartNumbering) {
            $style['pageNumberingStart'] = 1;
        }

        $section = $this->word->addSection($style);

        $header = $section->addHeader();
        $table = $header->addTable(['cellMargin' => 0]);
        $table->addRow();
        $table->addCell(self::WIDTH - 2200)->addText($this->d['companyName'], ['bold' => true, 'size' => 12.5]);
        $table->addCell(2200, ['valign' => 'bottom'])->addPreserveText(__('Page').' {PAGE}', ['size' => 9], ['alignment' => Jc::END]);
        $header->addText('', [], ['spaceAfter' => 80]);
        $header->addText($title, ['bold' => true]);
        $header->addText($subtitle, ['bold' => true], ['borderBottomSize' => 6, 'borderBottomColor' => '000000', 'spaceAfter' => 0]);

        // Every section gets its own footer, even an empty one: Word otherwise
        // carries the previous section's footer forward onto this one.
        $footer = $section->addFooter();
        if ($notesFooter || $gainsFooter) {
            if ($gainsFooter) {
                $footer->addText($this->gainsSentence(), ['size' => 8], ['spaceAfter' => 120]);
            }
            if ($notesFooter) {
                $footer->addPreserveText(__('The notes on pages').' {PAGEREF ll_notes \h} '.__('to').' {PAGEREF ll_notes_end \h} '.__('form part of these financial statements.'), ['size' => 8]);
            }
        }

        return $section;
    }

    private function gainsSentence(): string
    {
        return $this->d['hasPrior']
            ? __('There were no recognised gains or losses for :cy or :py other than those included in the statement of comprehensive income.', ['cy' => $this->d['cy'], 'py' => $this->d['py']])
            : __('There were no recognised gains or losses for :cy other than those included in the statement of comprehensive income.', ['cy' => $this->d['cy']]);
    }

    private function directorsReport(Section $s): void
    {
        $profile = $this->d['profile'];
        $r = $this->d['r'];
        $micro = $this->d['micro'];
        $p = ['alignment' => Jc::BOTH, 'spaceAfter' => 140];
        $h = fn (string $text) => $s->addText($text, ['bold' => true], ['spaceBefore' => 160, 'spaceAfter' => 40]);

        $run = $s->addTextRun($p);
        $run->addText(__('The directors present their report and the unaudited financial statements for the year ended').' ');
        $this->dateRun($run, $r['end']);
        $run->addText('.');

        $h(__("Statement of directors' responsibilities"));
        $s->addText(__('Company law requires the directors to prepare financial statements for each financial year, which give a true and fair view of the state of affairs of the company and of the profit or loss of the company for that period. In preparing those financial statements, the directors are required to:'), [], $p);
        foreach ([
            __('select suitable accounting policies and then apply them consistently;'),
            __('make judgements and estimates that are reasonable and prudent;'),
            __('state whether applicable UK Accounting Standards have been followed, subject to any material departures disclosed and explained in the financial statements; and'),
            __('prepare the financial statements on the going concern basis unless it is inappropriate to presume that the company will continue in business.'),
        ] as $bullet) {
            $s->addText('-   '.$bullet, [], ['indentation' => ['left' => 560, 'hanging' => 280], 'spaceAfter' => 20, 'alignment' => Jc::BOTH]);
        }
        $s->addText('', [], ['spaceAfter' => 100]);
        $s->addText(__("The directors are responsible for keeping adequate accounting records that are sufficient to show and explain the company's transactions and disclose with reasonable accuracy at any time the financial position of the company and to enable them to ensure that the financial statements comply with the :act. They are also responsible for safeguarding the assets of the company, and hence for taking reasonable steps for the prevention and detection of fraud and other irregularities.", ['act' => $profile->legislation()]), [], $p);

        if ($profile->principalActivity !== '') {
            $h(__('Principal activity'));
            $s->addText($profile->principalActivity, [], $p);
        }

        $div = $r['sci']['current']['dividends'];
        $divPrior = $r['sci']['prior']['dividends'] ?? 0;
        $statement = $micro ? __('profit and loss account') : __('statement of comprehensive income');
        if (! $micro || $profile->includeProfitAndLossForMicro) {
            $h(__('Results and dividends'));
            $s->addPreserveText(__('The results of the company for the year are set out in the :statement on page', ['statement' => $statement]).' {PAGEREF ll_income_statement \h}.', [], $p);
        } else {
            $h(__('Dividends'));
        }
        $prior = $this->d['hasPrior'] && ($div !== 0 || $divPrior !== 0) ? ' ('.$this->d['py'].' - £'.number_format($divPrior).')' : '';
        $s->addText(($div !== 0
            ? __('The company paid a dividend of £:amount during the year', ['amount' => number_format($div)])
            : __('The company did not pay a dividend during the year')).$prior.'.', [], $p);

        if ($profile->directors !== []) {
            $h(__('Directors'));
            $hasShares = collect($profile->directors)->contains(fn ($d) => $d['shares_current'] !== '' || $d['shares_prior'] !== '');
            $s->addText($hasShares
                ? __('The directors who served during the year and their beneficial interests in the ordinary share capital were:')
                : __('The directors who served during the year were:'), [], $p);

            if ($hasShares) {
                $t = $s->addTable(['cellMargin' => 0]);
                $t->addRow();
                $t->addCell(4200);
                $t->addCell(2400, ['gridSpan' => 2])->addText(__('Number of shares'), ['bold' => true], ['alignment' => Jc::CENTER]);
                $t->addRow();
                $t->addCell(4200);
                $t->addCell(1200)->addText($this->d['cy'], ['bold' => true], ['alignment' => Jc::CENTER]);
                $t->addCell(1200)->addText($this->d['py'], [], ['alignment' => Jc::CENTER]);
                foreach ($profile->directors as $d) {
                    $t->addRow();
                    $t->addCell(4200)->addText($d['name']);
                    $t->addCell(1200)->addText(in_array($d['shares_current'], ['', '0'], true) ? '-' : $d['shares_current'], ['bold' => true], ['alignment' => Jc::CENTER]);
                    $t->addCell(1200)->addText(in_array($d['shares_prior'], ['', '0'], true) ? '-' : $d['shares_prior'], [], ['alignment' => Jc::CENTER]);
                }
            } else {
                foreach ($profile->directors as $d) {
                    $s->addText($d['name']);
                }
            }
        }

        if ($profile->regime === Profile::REGIME_IOM_1931) {
            $h(__('Auditors and Annual General Meeting (AGM)'));
            $s->addText(__('The company has elected, by resolution, to dispense with the requirements to have its accounts audited and to hold AGMs. Members are hereby advised that they may, by notice in writing to the Company, rescind the aforesaid elections.'), [], $p);
        } elseif ($profile->regime === Profile::REGIME_IOM_2006) {
            $h(__('Audit'));
            $s->addText(__('The company is exempt from the requirement to have its accounts audited and the members have not required an audit.'), [], $p);
        } else {
            $h(__('Small company provisions'));
            $s->addText(__('This report has been prepared in accordance with the provisions applicable to companies entitled to the small companies exemption.'), [], $p);
        }

        $run = $s->addTextRun(['spaceBefore' => 160]);
        $run->addText(__('This report was approved by the board on').' ');
        $profile->approvalDate ? $this->dateRun($run, CarbonImmutable::parse($profile->approvalDate)) : $run->addText('..................................................');

        $signatory = $profile->directorsReportSignatory ?: ($profile->directors[0]['name'] ?? '');
        $s->addText('', [], ['spaceBefore' => 600]);
        $s->addText('..............................................');
        $s->addText($signatory, ['bold' => true]);
        $s->addText($profile->directorsReportSignatoryRole, ['bold' => true]);
    }

    private function incomeStatement(Section $s): void
    {
        $r = $this->d['r'];
        $c = $r['sci']['current'];
        $p = $r['sci']['prior'];
        $pv = fn (string $k) => $p[$k] ?? 0;
        $cap = $r['captions'];
        $notes = $this->d['notes'];
        $policy = isset($notes['policies']) ? $notes['policies'].'b)' : '';

        $t = $this->statementTable($s);

        if ($this->d['micro']) {
            $other = fn ($x) => ($x['other_operating_income'] ?? 0) + ($x['interest_receivable'] ?? 0);
            foreach ([
                [__('Turnover'), $c['turnover'], $pv('turnover'), false],
                [__('Other income'), $other($c), $p ? $other($p) : 0, false],
                [__('Cost of raw materials and consumables'), $c['cost_of_sales'], $pv('cost_of_sales'), true],
                [__('Staff costs'), $c['staff_costs'], $pv('staff_costs'), true],
                [__('Depreciation and other amounts written off assets'), $c['depreciation'], $pv('depreciation'), true],
                [__('Other charges'), $c['other_charges'], $pv('other_charges'), true],
                [__('Tax'), $c['taxation'], $pv('taxation'), true],
            ] as [$label, $cv, $pvv, $deduct]) {
                $this->row($t, $label, '', null, $deduct ? F::deduction($cv) : F::amount($cv), $deduct ? F::deduction($pvv) : F::amount($pvv), gap: true);
            }
            $this->row($t, __('Profit for the financial year'), '', null, F::amount($c['profit_after_tax']), F::amount($pv('profit_after_tax')), bold: true, top: true, double: true, gap: true);

            return;
        }

        $this->row($t, $cap['turnover'], $policy, null, F::amount($c['turnover']), F::amount($pv('turnover')), bold: true, gap: true);

        if ($c['cost_of_sales'] !== 0 || $pv('cost_of_sales') !== 0) {
            $this->row($t, $cap['cost_of_sales'], '', null, F::deduction($c['cost_of_sales']), F::deduction($pv('cost_of_sales')));
            $this->row($t, __('Gross profit'), '', null, F::amount($c['gross_profit']), F::amount($pv('gross_profit')), bold: true, top: true);
        }
        if ($c['other_operating_income'] !== 0 || $pv('other_operating_income') !== 0) {
            $this->row($t, $cap['other_operating_income'], '', null, F::amount($c['other_operating_income']), F::amount($pv('other_operating_income')));
        }

        $this->row($t, __('Expenses'), $policy, null, null, null, bold: true, gap: true);
        foreach ($r['sci']['expense_rows'] as $row) {
            $this->row($t, $row['label'], '', F::amount($row['current']), null, F::amount($row['prior']));
        }
        $this->row($t, '', '', '', F::deduction($c['expense_total']), F::deduction($pv('expense_total')), detailTop: true, priorTop: true);

        $this->row($t, __('Operating profit'), '', null, F::amount($c['operating_profit']), F::amount($pv('operating_profit')), bold: true, top: true, gap: true);
        if ($c['interest_receivable'] !== 0 || $pv('interest_receivable') !== 0) {
            $this->row($t, $cap['interest_receivable'], '', null, F::amount($c['interest_receivable']), F::amount($pv('interest_receivable')));
        }
        if ($c['interest_payable'] !== 0 || $pv('interest_payable') !== 0) {
            $this->row($t, $cap['interest_payable'], '', null, F::deduction($c['interest_payable']), F::deduction($pv('interest_payable')));
        }
        $this->row($t, __('Profit on ordinary activities before taxation'), '', null, F::amount($c['profit_before_tax']), F::amount($pv('profit_before_tax')), bold: true, top: true, gap: true);
        $this->row($t, __('Taxation'), (string) ($notes['taxation'] ?? ''), null, F::deduction($c['taxation']), F::deduction($pv('taxation')));
        $this->row($t, __('Profit on ordinary activities after taxation and total comprehensive income for the year'), '', null, F::amount($c['profit_after_tax']), F::amount($pv('profit_after_tax')), bold: true, top: true, double: true, gap: true);
    }

    private function balanceSheet(Section $s): void
    {
        $r = $this->d['r'];
        $profile = $this->d['profile'];
        $c = $r['sfp']['current'];
        $p = $r['sfp']['prior'];
        $pv = fn (string $k) => $p[$k] ?? 0;
        $either = fn (string $k) => ($c[$k] ?? 0) !== 0 || $pv($k) !== 0;
        $cap = $r['captions'];
        $notes = $this->d['notes'];

        $t = $this->statementTable($s);

        if ($this->d['micro']) {
            foreach ([
                [__('Fixed assets'), 'fixed_total', false, false, true],
                [__('Current assets'), 'current_total', false, false, true],
                [__('Creditors: amounts falling due within one year'), 'creditors_within', true, false, true],
                [__('Net current assets'), 'net_current_assets', false, true, true],
                [__('Total assets less current liabilities'), 'total_assets_less_current_liabilities', false, true, true],
                [__('Creditors: amounts falling due after more than one year'), 'creditors_after', true, false, false],
                [__('Provisions for liabilities'), 'provisions', true, false, false],
            ] as [$label, $key, $deduct, $total, $always]) {
                if ($always || $either($key)) {
                    $this->row($t, $label, '', null, $deduct ? F::deduction($c[$key]) : F::amount($c[$key]), $deduct ? F::deduction($pv($key)) : F::amount($pv($key)), bold: $total, top: $total, gap: true);
                }
            }
            $this->row($t, __('Net assets'), '', null, F::amount($c['net_assets']), F::amount($pv('net_assets')), bold: true, top: true, double: true, gap: true);
            $this->row($t, __('Capital and reserves'), '', null, F::amount($c['equity_total']), F::amount($pv('equity_total')), bold: true, double: true, gap: true);
        } else {
            $fixed = array_values(array_filter(['intangible_assets', 'tangible_assets', 'investments'], $either));
            if ($fixed !== []) {
                $this->row($t, __('Fixed assets'), '', null, null, null, bold: true, gap: true);
                foreach ($fixed as $line) {
                    $label = match ($line) {
                        'intangible_assets' => __('Intangible assets'), 'tangible_assets' => __('Tangible fixed assets'), default => $cap['investments']
                    };
                    $this->row($t, $label, $line === 'tangible_assets' ? (string) ($notes['tangible'] ?? '') : '', null, F::amount($c[$line]), F::amount($pv($line)));
                }
                if (count($fixed) > 1) {
                    $this->row($t, '', '', null, F::amount($c['fixed_total']), F::amount($pv('fixed_total')), bold: true, top: true);
                }
            }

            $this->row($t, __('Current assets'), '', null, null, null, bold: true, gap: true);
            foreach (['stocks' => __('Stocks'), 'debtors' => __('Debtors'), 'cash' => $cap['cash']] as $line => $label) {
                if ($either($line)) {
                    $this->row($t, $label, $line === 'debtors' ? (string) ($notes['debtors'] ?? '') : '', F::amount($c[$line]), null, F::amount($pv($line)));
                }
            }
            $this->row($t, '', '', F::amount($c['current_total']), null, F::amount($pv('current_total')), detailTop: true, priorTop: true, detailBold: true);

            $this->row($t, [__('Creditors:'), ' '.__('amounts falling due within one year')], (string) ($notes['creditors_within'] ?? ''), F::deduction($c['creditors_within']), null, F::deduction($pv('creditors_within')), gap: true);
            $this->row($t, __('Net current assets'), '', '', F::amount($c['net_current_assets']), F::amount($pv('net_current_assets')), bold: true, detailTop: true, priorTop: true, gap: true);
            $this->row($t, __('Total assets less current liabilities'), '', null, F::amount($c['total_assets_less_current_liabilities']), F::amount($pv('total_assets_less_current_liabilities')), bold: true, top: true, gap: true);
            if ($either('creditors_after')) {
                $this->row($t, [__('Creditors:'), ' '.__('amounts falling due after more than one year')], (string) ($notes['creditors_after'] ?? ''), null, F::deduction($c['creditors_after']), F::deduction($pv('creditors_after')), gap: true);
            }
            if ($either('provisions')) {
                $this->row($t, __('Provisions for liabilities'), '', null, F::deduction($c['provisions']), F::deduction($pv('provisions')), gap: true);
            }
            $this->row($t, __('Net assets'), '', null, F::amount($c['net_assets']), F::amount($pv('net_assets')), bold: true, top: true, double: true, gap: true);

            $this->row($t, __('Capital and Reserves'), '', null, null, null, bold: true, gap: true);
            $this->row($t, __('Called up share capital'), (string) ($notes['share_capital'] ?? ''), null, F::amount($c['share_capital']), F::amount($pv('share_capital')));
            foreach (['share_premium', 'revaluation_reserve', 'other_reserves'] as $line) {
                if ($either($line)) {
                    $this->row($t, $cap[$line], '', null, F::amount($c[$line]), F::amount($pv($line)));
                }
            }
            $this->row($t, __('Retained earnings'), '', null, F::amount($c['retained_earnings'], false), F::amount($pv('retained_earnings'), false));
            $this->row($t, __("Equity Shareholders' funds"), '', null, F::amount($c['equity_total']), F::amount($pv('equity_total')), bold: true, top: true, double: true, gap: true);
        }

        $s->addText('', [], ['spaceAfter' => 300]);
        $p = ['alignment' => Jc::BOTH, 'spaceAfter' => 140];
        $end = F::date($r['end']);
        if ($profile->regime === Profile::REGIME_UK_2006) {
            $s->addText(__('For the year ending :date the company was entitled to exemption from audit under section 477 of the Companies Act 2006 relating to small companies.', ['date' => $end]), [], $p);
            $s->addText(__('The members have not required the company to obtain an audit of its accounts for the year in question in accordance with section 476.'), [], $p);
            $s->addText(__('The directors acknowledge their responsibilities for complying with the requirements of the Act with respect to accounting records and the preparation of accounts.'), [], $p);
            $s->addText($this->d['micro']
                ? __('These accounts have been prepared in accordance with the micro-entity provisions.')
                : __('These accounts have been prepared in accordance with the provisions applicable to companies subject to the small companies regime.'), [], $p);
        } elseif ($this->d['micro']) {
            $s->addText(__('These financial statements have been prepared in accordance with FRS 105 The Financial Reporting Standard applicable to the Micro-entities Regime.'), [], $p);
        }

        $run = $s->addTextRun(['spaceAfter' => 140]);
        $run->addText(__('The financial statements were approved by the board of directors on').' ', ['bold' => true]);
        if ($profile->approvalDate) {
            $this->dateRun($run, CarbonImmutable::parse($profile->approvalDate), ['bold' => true]);
            $run->addText(' ', ['bold' => true]);
        }
        $run->addText(__('and signed on their behalf by:'), ['bold' => true]);

        $signatories = $profile->signatories !== [] ? $profile->signatories : array_slice(array_column($profile->directors, 'name'), 0, 2);
        $sig = $s->addTable(['cellMargin' => 0]);
        $sig->addRow(self::cm(1.6));
        foreach (array_slice($signatories, 0, 2) as $name) {
            $cell = $sig->addCell(4600, ['valign' => 'bottom']);
            $cell->addText('.....................................................');
            $cell->addText($name, ['bold' => true]);
            $cell->addText(__('Director'), ['bold' => true]);
        }
    }

    private function changesInEquity(Section $s): void
    {
        $r = $this->d['r'];
        $blocks = array_values(array_filter([$r['soce']['prior'], $r['soce']['current']]));
        $withOther = collect($blocks)->contains(fn ($b) => $b['has_other_reserves']);
        $cols = $withOther ? 4 : 3;
        $labelWidth = self::WIDTH - $cols * self::NUM;

        foreach ($blocks as $i => $b) {
            if ($i > 0) {
                $s->addText('', [], ['spaceAfter' => 360]);
            }
            $t = $s->addTable(['cellMargin' => 0]);
            $t->addRow();
            $t->addCell($labelWidth);
            $heads = [[__('Share'), __('Capital')]];
            if ($withOther) {
                $heads[] = [__('Other'), __('Reserves')];
            }
            $heads[] = [__('Retained'), __('Earnings')];
            $heads[] = [__('Total'), __('Equity')];
            foreach ($heads as [$a, $bb]) {
                $cell = $t->addCell(self::NUM);
                foreach ([$a, $bb, '£'] as $line) {
                    $cell->addText($line, ['bold' => true], ['alignment' => Jc::END]);
                }
            }

            $total = fn (array $x) => $x['share_capital'] + $x['other_reserves'] + $x['retained_earnings'];
            $line = function (string|array $label, array $values, bool $topDouble = false, bool $bold = false, ?CarbonInterface $date = null) use ($t, $labelWidth, $withOther) {
                $t->addRow();
                $cell = $t->addCell($labelWidth);
                $run = $cell->addTextRun(['spaceBefore' => $date ? 100 : 0]);
                $run->addText(is_array($label) ? $label[0] : $label, ['bold' => $bold]);
                if ($date) {
                    $run->addText(' ', ['bold' => $bold]);
                    $this->dateRun($run, $date, ['bold' => $bold]);
                }
                if (! $withOther) {
                    unset($values[1]);
                }
                foreach (array_values($values) as $v) {
                    $style = $topDouble ? ['borderTopSize' => 6, 'borderTopColor' => '000000', 'borderBottomSize' => 6, 'borderBottomColor' => '000000', 'borderBottomStyle' => 'double'] : [];
                    $t->addCell(self::NUM, $style + ['valign' => 'bottom'])->addText(F::amount($v), ['bold' => $bold], ['alignment' => Jc::END]);
                }
            };

            $o = $b['opening'];
            $line(__('At'), [$o['share_capital'], $o['other_reserves'], $o['retained_earnings'], $total($o)], date: $b['opening_date']);
            $line(__('Profit after taxation'), [0, 0, $b['profit'], $b['profit']]);
            if ($b['dividends'] !== 0) {
                $line(__('Dividends'), [0, 0, -$b['dividends'], -$b['dividends']]);
            }
            if ($b['shares_issued'] !== 0) {
                $line(__('Shares issued'), [$b['shares_issued'], 0, 0, $b['shares_issued']]);
            }
            if ($b['other_reserve_movement'] !== 0) {
                $line(__('Movement in other reserves'), [0, $b['other_reserve_movement'], 0, $b['other_reserve_movement']]);
            }
            if ($b['other_movements'] !== 0) {
                $line(__('Other movements'), [0, 0, $b['other_movements'], $b['other_movements']]);
            }
            $cl = $b['closing'];
            $line(__('At'), [$cl['share_capital'], $cl['other_reserves'], $cl['retained_earnings'], $total($cl)], topDouble: true, bold: $i === count($blocks) - 1, date: $b['closing_date']);
        }
    }

    private function notes(Section $s): void
    {
        $r = $this->d['r'];
        $profile = $this->d['profile'];
        $notes = $this->d['notes'];
        $company = $this->d['company'];
        $p = ['alignment' => Jc::BOTH, 'spaceAfter' => 120, 'indentation' => ['left' => 450]];
        $head = function (string $key, string|array $title) use ($s, $notes) {
            $run = $s->addTextRun(['spaceBefore' => 220, 'spaceAfter' => 80, 'keepNext' => true]);
            $run->addText($notes[$key].'.', ['bold' => true]);
            $run->addText("\t");
            if (is_array($title)) {
                $run->addText($title[0], ['bold' => true]);
                $run->addText($title[1]);
            } else {
                $run->addText($title, ['bold' => true]);
            }
        };

        if ($this->d['micro']) {
            $head('policies', __('Accounting policies'));
            $s->addText(__('These financial statements have been prepared in accordance with FRS 105 The Financial Reporting Standard applicable to the Micro-entities Regime and the :act.', ['act' => $profile->legislation()]), [], $p);
            $s->addText(__('The financial statements have been prepared on a historical cost basis. Income and expenditure are accounted for on an accruals basis.'), [], $p);
            $head('employees', __('Employees'));
            $s->addText(__('The average number of persons employed by the company (including directors) during the year was :n.', ['n' => $profile->averageEmployees ?? count($profile->directors)]), [], $p);
            $head('directors_advances', __('Advances, credits and guarantees granted to directors'));
            $s->addText($profile->directorsAdvances !== '' ? $profile->directorsAdvances : __('There were no advances, credits or guarantees granted to the directors during the year.'), [], $p);
            if (isset($notes['guarantees'])) {
                $head('guarantees', __('Guarantees and other financial commitments'));
                $s->addText($profile->guarantees, [], $p);
            }

            return;
        }

        $office = collect(preg_split('/\r?\n/', $profile->registeredOffice))->map(fn ($l) => trim($l))->filter()->implode(', ');
        $head('general', __('General Information'));
        $s->addText(__(':name is a company limited by shares, incorporated in :place.', ['name' => $company->legal_name ?: $company->name, 'place' => $profile->incorporatedIn ?: __('the United Kingdom')])
            .($office !== '' ? ' '.__('Its registered office is :office.', ['office' => $office]) : ''), [], $p);

        $head('policies', __('Summary of significant accounting policies'));
        $sub = fn (string $letter, string $title) => $s->addText($letter.')  '.$title, ['bold' => true], ['indentation' => ['left' => 450], 'spaceBefore' => 60]);
        $pp = ['alignment' => Jc::BOTH, 'spaceAfter' => 120, 'indentation' => ['left' => 760]];
        $sub('a', __('Basis of preparation of Financial Statements'));
        $s->addText(__('The financial statements have been prepared in accordance with FRS 102 Section 1A Small Entities.'), [], $pp);
        $s->addText(__('The financial statements have been prepared on a historical cost basis.'), [], $pp);
        $sub('b', __('Income and expenditure'));
        $s->addText(__('Income and expenditure are accounted for on an accruals basis.'), [], $pp);
        if (isset($notes['tangible']) || $profile->depreciationRates !== []) {
            $sub('c', __('Depreciation of fixed assets and impairment'));
            $s->addText(__('Depreciation is calculated so as to write off the cost of an asset, less its estimated residual value, over the useful economic life of that asset as follows:'), [], $pp + ['spaceAfter' => 40]);
            if ($profile->depreciationRates !== []) {
                $rt = $s->addTable(['cellMargin' => 0, 'indent' => new TblWidth(1300)]);
                foreach ($profile->depreciationRates as $rate) {
                    $rt->addRow();
                    $rt->addCell(2600)->addText($rate['class']);
                    $rt->addCell(1000)->addText($rate['rate']);
                    $rt->addCell(2600)->addText($rate['method']);
                }
            }
            $s->addText(__('At each reporting date, goodwill and other fixed assets, including tangible fixed assets and investments, but excluding investment properties, are assessed to determine whether there is an indication that the carrying amount of an asset may be more than its recoverable amount and that the asset should be impaired. If there is an indication of possible impairment, the recoverable amount of an asset, which is the higher of its value in use and its net realisable value, is estimated and compared with its carrying amount. If the recoverable amount is lower, the carrying amount of the asset is written down to its estimated recoverable amount and an impairment loss is recognised.'), [], $pp + ['spaceBefore' => 60]);
        }

        if (isset($notes['judgements'])) {
            $head('judgements', __('Critical accounting estimates and judgements'));
            $s->addText($profile->judgementsNote, [], $p);
        }
        if (isset($notes['employees'])) {
            $head('employees', __('Employees'));
            $s->addText(__('The average monthly number of employees, including directors, during the year was :n.', ['n' => $profile->averageEmployees]), [], $p);
        }
        if (isset($notes['taxation'])) {
            $head('taxation', __('Taxation'));
            $s->addText($profile->taxationNote !== '' ? $profile->taxationNote : __('The tax charge on the profit for the year was £:amount.', ['amount' => number_format($r['sci']['current']['taxation'])]), [], $p);
        }

        if (isset($notes['tangible'])) {
            $head('tangible', __('Tangible fixed assets'));
            $this->tangibleTable($s);
        }
        foreach (['debtors' => __('Debtors'), 'creditors_within' => [__('Creditors:'), ' '.__('amounts falling due within one year')], 'creditors_after' => [__('Creditors:'), ' '.__('amounts falling due after more than one year')]] as $key => $title) {
            if (isset($notes[$key])) {
                $head($key, $title);
                $rows = $r['analysis'][$key];
                $this->analysisTable($s, array_map(fn ($row) => [$row['label'], $row['current'], $row['prior']], $rows), count($rows) > 1);
                if ($key === 'creditors_after' && $profile->creditorsAfterNarrative !== '') {
                    $s->addText($profile->creditorsAfterNarrative, [], $p + ['spaceBefore' => 120]);
                }
            }
        }

        if (isset($notes['share_capital'])) {
            $head('share_capital', __('Share capital'));
            $t = $this->notesTable($s);
            $this->notesRow($t, '', '£', '£', bold: true);
            if ($profile->authorisedShares !== '') {
                $auth = preg_match('/([\d,]+)\s+[^£]*£\s?([\d.]+)/u', $profile->authorisedShares, $m) === 1 ? (int) round(((int) str_replace(',', '', $m[1])) * (float) $m[2]) : null;
                $this->notesRow($t, __('Authorised share capital'), '', '', labelBold: true, gap: true);
                $this->notesRow($t, $profile->authorisedShares, $auth !== null ? F::amount($auth) : '', $auth !== null ? F::amount($auth) : '', double: true);
            }
            $this->notesRow($t, __('Issued share capital'), '', '', labelBold: true, gap: true);
            $this->notesRow($t, $profile->issuedShares !== '' ? $profile->issuedShares : __('Called up, allotted and fully paid'), F::amount($r['sfp']['current']['share_capital']), F::amount($r['sfp']['prior']['share_capital'] ?? 0), double: true, keepNext: false);
        }
        if (isset($notes['directors_advances'])) {
            $head('directors_advances', __('Advances, credits and guarantees granted to directors'));
            $s->addText($profile->directorsAdvances, [], $p);
        }
        if (isset($notes['related_parties'])) {
            $head('related_parties', __('Related party transactions'));
            $s->addText($profile->relatedParties, [], $p);
        }
    }

    private function tangibleTable(Section $s): void
    {
        $tn = $this->d['r']['tangible'];
        $classes = $tn['classes'];
        $showTotal = count($classes) > 1;
        $colW = 1500;
        $labelW = 3600;

        $t = $s->addTable(['cellMargin' => 0, 'indent' => new TblWidth(450)]);
        $t->addRow();
        $t->addCell($labelW);
        foreach (array_merge(array_keys($classes), $showTotal ? [__('Total')] : []) as $name) {
            $cell = $t->addCell($colW, ['valign' => 'bottom']);
            foreach (explode("\n", wordwrap((string) $name, 14)) as $line) {
                $cell->addText($line, ['bold' => true], ['alignment' => Jc::END]);
            }
            $cell->addText('£', ['bold' => true], ['alignment' => Jc::END]);
        }

        $r = $this->d['r'];
        $row = function (string $label, ?string $key, ?CarbonInterface $date = null, bool $top = false, bool $double = false, bool $bold = false, bool $head = false) use ($t, $classes, $showTotal, $tn, $colW, $labelW) {
            $t->addRow(null, ['cantSplit' => true]);
            $run = $t->addCell($labelW)->addTextRun(['spaceBefore' => $head ? 120 : 0, 'keepNext' => ! ($key === 'nbv_open' || ($key === 'nbv_close' && ! $this->d['hasPrior']))]);
            $run->addText($label, ['bold' => $head]);
            if ($date) {
                $run->addText(' ');
                $this->dateRun($run, $date);
            }
            if ($key === null) {
                return;
            }
            $values = array_map(fn ($c) => $c[$key], $classes);
            if ($showTotal) {
                $values[] = $tn['total'][$key];
            }
            foreach ($values as $v) {
                $style = ['valign' => 'bottom'];
                if ($top) {
                    $style += ['borderTopSize' => 6, 'borderTopColor' => '000000'];
                }
                if ($double) {
                    $style += ['borderBottomSize' => 6, 'borderBottomColor' => '000000', 'borderBottomStyle' => 'double'];
                }
                $t->addCell($colW, $style)->addText(F::amount($v), ['bold' => $bold], ['alignment' => Jc::END]);
            }
        };

        $row(__('Cost'), null, head: true);
        $row(__('At'), 'cost_open', $r['start']);
        $row(__('Additions'), 'additions');
        $row(__('Disposals'), 'disposals');
        $row(__('At'), 'cost_close', $r['end'], top: true);
        $row(__('Depreciation'), null, head: true);
        $row(__('At'), 'dep_open', $r['start']);
        $row(__('Charge for the year'), 'charge');
        if ($tn['total']['eliminated'] !== 0) {
            $row(__('Eliminated on disposals'), 'eliminated');
        }
        $row(__('At'), 'dep_close', $r['end'], top: true);
        $row(__('Net book value'), null, head: true);
        $row(__('At'), 'nbv_close', $r['end'], top: true, double: true, bold: true);
        if ($this->d['hasPrior']) {
            $row(__('Net book value'), null, head: true);
            $row(__('At'), 'nbv_open', $r['prior_end'], double: true);
        }
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $rows
     */
    private function analysisTable(Section $s, array $rows, bool $total): void
    {
        $t = $this->notesTable($s);
        $this->notesRow($t, '', '£', '£', bold: true);
        foreach ($rows as $i => [$label, $cv, $pv]) {
            $last = $i === count($rows) - 1;
            $this->notesRow($t, $label, F::amount($cv), F::amount($pv), double: ! $total && $last, gap: $i === 0, keepNext: $total || ! $last);
        }
        if ($total) {
            $this->notesRow($t, '', F::amount(array_sum(array_column($rows, 1))), F::amount(array_sum(array_column($rows, 2))), top: true, double: true, keepNext: false);
        }
    }

    private function notesTable(Section $s): Table
    {
        $t = $s->addTable(['cellMargin' => 0, 'indent' => new TblWidth(450)]);
        $t->addRow(null, ['cantSplit' => true]);
        $t->addCell(self::WIDTH - 450 - 2 * self::NUM)->addText('', [], ['keepNext' => true]);
        $t->addCell(self::NUM)->addText($this->d['cy'], ['bold' => true], ['alignment' => Jc::END, 'keepNext' => true]);
        $t->addCell(self::NUM)->addText($this->d['hasPrior'] ? $this->d['py'] : '', [], ['alignment' => Jc::END, 'keepNext' => true]);

        return $t;
    }

    private function notesRow(Table $t, string $label, string $cy, string $py, bool $bold = false, bool $labelBold = false, bool $top = false, bool $double = false, bool $gap = false, bool $keepNext = true): void
    {
        $t->addRow(null, ['cantSplit' => true]);
        $t->addCell(self::WIDTH - 450 - 2 * self::NUM)->addText($label, ['bold' => $bold || $labelBold], ['spaceBefore' => $gap ? 120 : 0, 'keepNext' => $keepNext]);
        foreach ([[$cy, true], [$this->d['hasPrior'] ? $py : '', false]] as [$v, $isCy]) {
            $style = ['valign' => 'bottom'];
            if ($top) {
                $style += ['borderTopSize' => 6, 'borderTopColor' => '000000'];
            }
            if ($double && $v !== '') {
                $style += ['borderBottomSize' => 6, 'borderBottomColor' => '000000', 'borderBottomStyle' => 'double'];
            }
            $t->addCell(self::NUM, $style)->addText($v, ['bold' => $bold || $isCy], ['alignment' => Jc::END, 'keepNext' => $keepNext]);
        }
    }

    // ───────────────────────── Building blocks ─────────────────────────

    /**
     * The four-column statement grid — caption, note, a detail £ column, the
     * current year (bold) and the prior year — with its year/£ headings.
     */
    private function statementTable(Section $s): Table
    {
        $t = $s->addTable(['cellMargin' => 0]);
        $t->addRow();
        $t->addCell($this->labelWidth());
        $t->addCell(self::NOTE);
        $t->addCell(self::NUM);
        $t->addCell(self::NUM)->addText($this->d['cy'], ['bold' => true], ['alignment' => Jc::END]);
        $t->addCell(self::NUM)->addText($this->d['hasPrior'] ? $this->d['py'] : '', [], ['alignment' => Jc::END]);
        $t->addRow();
        $t->addCell($this->labelWidth());
        $t->addCell(self::NOTE)->addText(__('Note'), [], ['alignment' => Jc::CENTER]);
        $t->addCell(self::NUM)->addText('£', [], ['alignment' => Jc::END]);
        $t->addCell(self::NUM)->addText('£', ['bold' => true], ['alignment' => Jc::END]);
        $t->addCell(self::NUM)->addText($this->d['hasPrior'] ? '£' : '', [], ['alignment' => Jc::END]);

        return $t;
    }

    private function labelWidth(): int
    {
        return self::WIDTH - self::NOTE - 3 * self::NUM;
    }

    /**
     * One statement row. A null amount leaves the cell empty; an empty string
     * keeps it empty but still draws any rule asked for.
     *
     * @param  string|array{0: string, 1: string}  $label  [bold part, plain part] for "Creditors: amounts…"
     */
    private function row(
        Table $t, string|array $label, string $note, ?string $detail, ?string $current, ?string $prior,
        bool $bold = false, bool $top = false, bool $double = false, bool $gap = false,
        bool $detailTop = false, bool $priorTop = false, bool $detailBold = false,
    ): void {
        $t->addRow();
        $run = $t->addCell($this->labelWidth())->addTextRun(['spaceBefore' => $gap ? 130 : 0]);
        if (is_array($label)) {
            $run->addText($label[0], ['bold' => true]);
            $run->addText($label[1]);
        } else {
            $run->addText($label, ['bold' => $bold]);
        }
        $t->addCell(self::NOTE, ['valign' => 'bottom'])->addText($note, [], ['alignment' => Jc::CENTER]);

        $cells = [
            [$detail, true, $detailTop, false, $detailBold || $detail !== null],
            [$current, true, $top, $double, true],
            [$this->d['hasPrior'] ? $prior : null, false, $top || $priorTop, $double, false],
        ];
        foreach ($cells as [$value, $isCy, $lineTop, $lineDouble, $boldCol]) {
            $style = ['valign' => 'bottom'];
            if ($lineTop && $value !== null) {
                $style += ['borderTopSize' => 6, 'borderTopColor' => '000000'];
            }
            if ($lineDouble && $value !== null) {
                $style += ['borderBottomSize' => 6, 'borderBottomColor' => '000000', 'borderBottomStyle' => 'double'];
            }
            $t->addCell(self::NUM, $style)->addText((string) $value, ['bold' => $isCy && $boldCol], ['alignment' => Jc::END]);
        }
    }

    /**
     * 5<sup>th</sup> April 2022, with the ordinal raised.
     *
     * @param  array<string, mixed>  $font
     */
    private function dateRun(TextRun|AbstractContainer $run, CarbonInterface $date, array $font = []): void
    {
        $run->addText($date->format('j'), $font);
        $run->addText($date->format('S'), $font + ['superScript' => true]);
        $run->addText(' '.$date->format('F Y'), $font);
    }
}
