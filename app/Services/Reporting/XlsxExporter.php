<?php

namespace App\Services\Reporting;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ReportGroup;
use App\Support\Reporting\StatementLabels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Builds nicely formatted XLSX downloads for accounting reports.
 *
 * Numbers stay numeric in the cells (so Excel can sum/filter), with a
 * #,##0.00;[Red]-#,##0.00 format applied. Subtotal/total cells use SUM()
 * formulas so the spreadsheet stays "live" when edited. Each report shows
 * the generation date in the top-right corner.
 */
class XlsxExporter
{
    private const MONEY_FORMAT = '#,##0.00;[Red]-#,##0.00';

    private const HEADER_FILL = 'EFF1F4';

    private const SUBHEADER_FILL = 'F8FAFC';

    private const TOTAL_FILL = 'E5E7EB';

    // ─────────────────────────── General Ledger ────────────────────────────

    /**
     * Lines may be a lazy generator (streamed export); they are iterated exactly once and
     * opening/closing are scalars, so the full range never materialises.
     *
     * @param  array{lines: iterable, opening: int, closing: int}  $report
     */
    public function generalLedgerSingleAccount(string $filename, Company $company, Account $account, array $report, string $startDate, string $endDate): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $account, $report, $startDate, $endDate) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('General Ledger');

            $sheet->setColumnWidth(13, 1);
            $sheet->setColumnWidth(14, 2);
            $sheet->setColumnWidth(48, 3);
            $sheet->setColumnWidth(14, 4, 5, 6);

            $this->writeReportHeader($writer, 'General Ledger', $company->name, [
                $account->code.' — '.$account->name,
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: 6);

            $headerStyle = $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL);
            $writer->addRow(Row::fromValuesWithStyle(
                ['Date', 'Entry #', 'Memo', 'Debit', 'Credit', 'Running'],
                $headerStyle,
            ));

            $sheet->setSheetView((new SheetView)->withFreezeRow(6));

            $italicGrey = $this->makeStyle(italic: true, fontColor: '6B7280');
            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);

            $writer->addRow(new Row([
                Cell::fromValue('Opening balance', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue($report['opening'] / 100, $moneyStyle),
            ]));

            $firstDataRow = 7;
            $rowIndex = $firstDataRow;
            foreach ($report['lines'] as $line) {
                $writer->addRow(new Row([
                    Cell::fromValue($line['date']),
                    $this->text($line['entry_no']),
                    $this->text($line['memo'] ?? ''),
                    Cell::fromValue($line['debit'] ? $line['debit'] / 100 : null, $moneyStyle),
                    Cell::fromValue($line['credit'] ? $line['credit'] / 100 : null, $moneyStyle),
                    Cell::fromValue($line['running'] / 100, $moneyStyle),
                ]));
                $rowIndex++;
            }
            $lastDataRow = $rowIndex - 1;

            $totalLabel = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
            $totalBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);
            $totalMoney = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: self::MONEY_FORMAT);

            if ($lastDataRow >= $firstDataRow) {
                $writer->addRow(new Row([
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('Period totals', $totalLabel),
                    Cell::fromValue(sprintf('=SUM(D%d:D%d)', $firstDataRow, $lastDataRow), $totalMoney),
                    Cell::fromValue(sprintf('=SUM(E%d:E%d)', $firstDataRow, $lastDataRow), $totalMoney),
                    Cell::fromValue('', $totalBlank),
                ]));
            }

            $closingLabel = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
            $closingBlank = $this->makeStyle(backgroundColor: self::SUBHEADER_FILL);
            $closingMoney = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL, format: self::MONEY_FORMAT);

            $writer->addRow(new Row([
                Cell::fromValue('Closing balance', $closingLabel),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue($report['closing'] / 100, $closingMoney),
            ]));
        });
    }

    /**
     * Entries may be a lazy generator (streamed export); it is iterated exactly once and
     * the grand total uses Excel SUM formulas, so nothing is materialised here.
     *
     * @param  array{entries: iterable, total_debit: int, total_credit: int, entry_count: int, line_count: int}  $report
     */
    public function generalLedgerAllAccounts(string $filename, Company $company, array $report, string $startDate, string $endDate): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $report, $startDate, $endDate) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('General Ledger');

            $sheet->setColumnWidth(13, 1);
            $sheet->setColumnWidth(14, 2);
            $sheet->setColumnWidth(10, 3);
            $sheet->setColumnWidth(34, 4);
            $sheet->setColumnWidth(48, 5);
            $sheet->setColumnWidth(14, 6, 7);

            $headerRowsUsed = $this->writeReportHeader($writer, 'General Ledger', $company->name, [
                'All accounts, grouped by entry',
                'Period: '.$startDate.' to '.$endDate,
                sprintf('%d entries, %d lines', $report['entry_count'], $report['line_count']),
            ], totalColumns: 7);

            $headerStyle = $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL);
            $writer->addRow(Row::fromValuesWithStyle(
                ['Date', 'Entry #', 'Code', 'Account', 'Memo', 'Debit', 'Credit'],
                $headerStyle,
            ));

            $sheet->setSheetView((new SheetView)->withFreezeRow($headerRowsUsed + 2));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $entryHeaderStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
            $entryTotalLabel = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL, alignment: CellAlignment::RIGHT);
            $entryTotalMoney = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL, format: self::MONEY_FORMAT);
            $entryTotalBlank = $this->makeStyle(backgroundColor: self::SUBHEADER_FILL);

            $rowIndex = $headerRowsUsed + 2; // currently pointing at the header row position; data starts +1
            $entryTotalRows = []; // row indexes of each per-entry total row (used for grand SUM)

            foreach ($report['entries'] as $entry) {
                $rowIndex++;
                // Entry header row
                $writer->addRow(new Row([
                    Cell::fromValue($entry['date'], $entryHeaderStyle),
                    $this->text($entry['entry_no'], $entryHeaderStyle),
                    Cell::fromValue('', $entryHeaderStyle),
                    Cell::fromValue('', $entryHeaderStyle),
                    $this->text($entry['memo'] ?? '', $entryHeaderStyle),
                    Cell::fromValue('', $entryHeaderStyle),
                    Cell::fromValue('', $entryHeaderStyle),
                ]));

                $firstLineRow = $rowIndex + 1;
                foreach ($entry['lines'] as $line) {
                    $rowIndex++;
                    $writer->addRow(new Row([
                        Cell::fromValue(''),
                        Cell::fromValue(''),
                        $this->text($line['account_code']),
                        $this->text($line['account_name']),
                        $this->text($line['memo'] ?? ''),
                        Cell::fromValue($line['debit'] ? $line['debit'] / 100 : null, $moneyStyle),
                        Cell::fromValue($line['credit'] ? $line['credit'] / 100 : null, $moneyStyle),
                    ]));
                }
                $lastLineRow = $rowIndex;

                $rowIndex++;
                $entryTotalRows[] = $rowIndex;
                $writer->addRow(new Row([
                    Cell::fromValue('', $entryTotalBlank),
                    Cell::fromValue('', $entryTotalBlank),
                    Cell::fromValue('', $entryTotalBlank),
                    Cell::fromValue('', $entryTotalBlank),
                    Cell::fromValue('Entry total', $entryTotalLabel),
                    Cell::fromValue(sprintf('=SUM(F%d:F%d)', $firstLineRow, $lastLineRow), $entryTotalMoney),
                    Cell::fromValue(sprintf('=SUM(G%d:G%d)', $firstLineRow, $lastLineRow), $entryTotalMoney),
                ]));
            }

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));

            $rowIndex++;
            $grandLabel = $this->makeStyle(bold: true, alignment: CellAlignment::RIGHT, backgroundColor: self::TOTAL_FILL);
            $grandBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);
            $grandTotal = $this->makeStyle(bold: true, format: self::MONEY_FORMAT, backgroundColor: self::TOTAL_FILL);

            $sumDebit = $entryTotalRows !== []
                ? '='.implode('+', array_map(fn ($r) => 'F'.$r, $entryTotalRows))
                : 0;
            $sumCredit = $entryTotalRows !== []
                ? '='.implode('+', array_map(fn ($r) => 'G'.$r, $entryTotalRows))
                : 0;

            $writer->addRow(new Row([
                Cell::fromValue('', $grandBlank),
                Cell::fromValue('', $grandBlank),
                Cell::fromValue('', $grandBlank),
                Cell::fromValue('', $grandBlank),
                Cell::fromValue('Grand total', $grandLabel),
                Cell::fromValue($sumDebit, $grandTotal),
                Cell::fromValue($sumCredit, $grandTotal),
            ]));
        });
    }

    // ─────────────────────────── Trial Balance ─────────────────────────────

    /**
     * @param  array{rows: array<int, array{code: string, name: string, type: string, debit: int, credit: int}>, totals: array{debit: int, credit: int}}  $report
     */
    public function trialBalance(string $filename, Company $company, array $report, string $asOf, ?string $moneyFormat = null): BinaryFileResponse
    {
        $money = $moneyFormat ?? self::MONEY_FORMAT;

        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $report, $asOf, $money) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Trial Balance');

            $sheet->setColumnWidth(10, 1);  // Code
            $sheet->setColumnWidth(38, 2);  // Account
            $sheet->setColumnWidth(18, 3);  // Type
            $sheet->setColumnWidth(16, 4, 5); // Debit, Credit

            $headerRowsUsed = $this->writeReportHeader($writer, 'Trial Balance', $company->name, [
                'As of '.$asOf,
            ], totalColumns: 5);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Code', 'Account', 'Type', 'Debit', 'Credit'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: $money);
            $firstDataRow = $columnHeaderRow + 1;

            foreach ($report['rows'] as $row) {
                $writer->addRow(new Row([
                    $this->text($row['code']),
                    $this->text($row['name']),
                    $this->text($row['type']),
                    Cell::fromValue($row['debit'] ? $row['debit'] / 100 : null, $moneyStyle),
                    Cell::fromValue($row['credit'] ? $row['credit'] / 100 : null, $moneyStyle),
                ]));
            }

            $lastDataRow = $columnHeaderRow + count($report['rows']);

            $this->writeTotalsRow($writer, [
                'Total', '', '',
                $this->sumFormula('D', $firstDataRow, $lastDataRow),
                $this->sumFormula('E', $firstDataRow, $lastDataRow),
            ], moneyColumns: [4, 5]);
        });
    }

    /**
     * Flat GIFI statement export. Rows are [schedule, section, code, description, amount].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int}>  $rows
     */
    public function gifi(string $filename, Company $company, array $rows, string $startDate, string $endDate): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $rows, $startDate, $endDate) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('GIFI Statement');

            $sheet->setColumnWidth(16, 1); // Schedule
            $sheet->setColumnWidth(24, 2); // Section
            $sheet->setColumnWidth(10, 3); // GIFI
            $sheet->setColumnWidth(40, 4); // Description
            $sheet->setColumnWidth(16, 5); // Amount

            $headerRowsUsed = $this->writeReportHeader($writer, 'GIFI Statement', $company->name, [
                $startDate.' to '.$endDate,
            ], totalColumns: 5);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Schedule', 'Section', 'GIFI', 'Description', 'Amount'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);

            foreach ($rows as $row) {
                $writer->addRow(new Row([
                    $this->text($row[0]),
                    $this->text($row[1]),
                    $this->text($row[2]),
                    $this->text($row[3]),
                    Cell::fromValue($row[4] / 100, $moneyStyle),
                ]));
            }
        });
    }

    // ─────────────────────────── Balance Sheet ─────────────────────────────

    /**
     * Each bucket (assets/liabilities/equity) is keyed by subtype value carrying a
     * label and an ordered list of section/unassigned blocks; see SectionPartitioner.
     *
     * @param  array<string, mixed>  $report
     */
    public function balanceSheet(string $filename, Company $company, array $report, string $asOf, bool $showComparison = false, ?string $moneyFormat = null): BinaryFileResponse
    {
        $money = $moneyFormat ?? self::MONEY_FORMAT;
        $labels = StatementLabels::for($company);

        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $report, $asOf, $showComparison, $money, $labels) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Balance Sheet');

            $sheet->setColumnWidth(20, 1);  // Section
            $sheet->setColumnWidth(28, 2);  // Subtype
            $sheet->setColumnWidth(40, 3);  // Account
            $sheet->setColumnWidth(16, 4);  // Amount / Current
            if ($showComparison) {
                $sheet->setColumnWidth(16, 5);  // Prior
            }

            $totalColumns = $showComparison ? 5 : 4;
            $headerRowsUsed = $this->writeReportHeader($writer, 'Balance Sheet', $company->name, [
                'As of '.$asOf,
            ], totalColumns: $totalColumns);

            $columnHeaders = $showComparison
                ? ['Section', 'Subtype', 'Account', 'Current', 'Prior']
                : ['Section', 'Subtype', 'Account', 'Amount'];

            $writer->addRow(Row::fromValuesWithStyle(
                $columnHeaders,
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $sheet->setSheetView((new SheetView)->withFreezeRow($headerRowsUsed + 2));

            $moneyStyle = $this->makeStyle(format: $money);
            $sectionStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
            $subtypeStyle = $this->makeStyle(italic: true, fontColor: '6B7280');

            $rowIndex = $headerRowsUsed + 1; // header row written

            $emitSection = function (string $title, array $groups, int $fallbackTotal, int $priorFallbackTotal) use ($writer, $sectionStyle, $subtypeStyle, $moneyStyle, $money, $showComparison, &$rowIndex): int {
                $rowIndex++;
                $sectionHeaderCells = [
                    Cell::fromValue(strtoupper($title), $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                ];
                if ($showComparison) {
                    $sectionHeaderCells[] = Cell::fromValue('', $sectionStyle);
                }
                $writer->addRow(new Row($sectionHeaderCells));

                $accountRows = [];

                foreach ($groups as $group) {
                    $rowIndex++;
                    $subtypeCells = [
                        Cell::fromValue(''),
                        $this->text($group['label'], $subtypeStyle),
                        Cell::fromValue(''),
                        Cell::fromValue(''),
                    ];
                    if ($showComparison) {
                        $subtypeCells[] = Cell::fromValue('');
                    }
                    $writer->addRow(new Row($subtypeCells));

                    foreach ($group['blocks'] as $block) {
                        if ($block['type'] === 'section') {
                            $rowIndex++;
                            $subHeader = [
                                Cell::fromValue(''),
                                Cell::fromValue(''),
                                $this->text($block['name'], $subtypeStyle),
                                Cell::fromValue(''),
                            ];
                            if ($showComparison) {
                                $subHeader[] = Cell::fromValue('');
                            }
                            $writer->addRow(new Row($subHeader));
                        }

                        $blockFirst = $rowIndex + 1;

                        foreach ($block['rows'] as $a) {
                            $rowIndex++;
                            $accountRows[] = $rowIndex;
                            $cells = [
                                Cell::fromValue(''),
                                Cell::fromValue(''),
                                $this->text(($block['type'] === 'section' ? '    ' : '').$a['code'].' — '.$a['name']),
                                Cell::fromValue($a['balance'] / 100, $moneyStyle),
                            ];
                            if ($showComparison) {
                                $cells[] = Cell::fromValue($a['prior'] / 100, $moneyStyle);
                            }
                            $writer->addRow(new Row($cells));
                        }

                        $blockLast = $rowIndex;

                        if ($block['type'] === 'section') {
                            $rowIndex++;
                            $subCells = [
                                Cell::fromValue('', $subtypeStyle),
                                Cell::fromValue('', $subtypeStyle),
                                $this->text('Total '.$block['name'], $subtypeStyle),
                                Cell::fromValue($this->sumFormula('D', $blockFirst, $blockLast), $this->makeStyle(italic: true, fontColor: '6B7280', format: $money)),
                            ];
                            if ($showComparison) {
                                $subCells[] = Cell::fromValue($this->sumFormula('E', $blockFirst, $blockLast), $this->makeStyle(italic: true, fontColor: '6B7280', format: $money));
                            }
                            $writer->addRow(new Row($subCells));
                        }
                    }
                }

                $rowIndex++;
                $totalLabel = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
                $totalMoney = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: $money);
                $totalBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);

                $formula = $accountRows !== []
                    ? '='.implode('+', array_map(fn ($r) => 'D'.$r, $accountRows))
                    : ($fallbackTotal / 100);

                $totalCells = [
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('Total '.$title, $totalLabel),
                    Cell::fromValue($formula, $totalMoney),
                ];
                if ($showComparison) {
                    $priorFormula = $accountRows !== []
                        ? '='.implode('+', array_map(fn ($r) => 'E'.$r, $accountRows))
                        : ($priorFallbackTotal / 100);
                    $totalCells[] = Cell::fromValue($priorFormula, $totalMoney);
                }
                $writer->addRow(new Row($totalCells));

                return $rowIndex;
            };

            $assetsTotalRow = $emitSection('Assets', $report['assets'], $report['total_assets'], $report['prior_total_assets'] ?? 0);

            $rowIndex++;
            $writer->addRow(Row::fromValues([])); // spacer

            $liabilitiesTotalRow = $emitSection('Liabilities', $report['liabilities'], $report['total_liabilities'], $report['prior_total_liabilities'] ?? 0);

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));

            $equityTotalRow = $emitSection($labels->equityShort(), $report['equity'], $report['total_equity'], $report['prior_total_equity'] ?? 0);

            // Net Income YTD row
            $rowIndex++;
            $netIncomeRow = $rowIndex;
            $niCells = [
                Cell::fromValue(''),
                Cell::fromValue(''),
                Cell::fromValue($labels->netIncomeYtd(), $this->makeStyle(italic: true)),
                Cell::fromValue($report['net_income_ytd'] / 100, $moneyStyle),
            ];
            if ($showComparison) {
                $niCells[] = Cell::fromValue(($report['prior_net_income_ytd'] ?? 0) / 100, $moneyStyle);
            }
            $writer->addRow(new Row($niCells));

            // Total liabilities & equity = liab + equity + NI
            $rowIndex++;
            $totalLELabel = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
            $totalLEMoney = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: $money);
            $totalLEBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);

            $totalLECells = [
                Cell::fromValue('', $totalLEBlank),
                Cell::fromValue('', $totalLEBlank),
                Cell::fromValue($labels->totalLiabilitiesAndEquity(), $totalLELabel),
                Cell::fromValue(sprintf('=D%d+D%d+D%d', $liabilitiesTotalRow, $equityTotalRow, $netIncomeRow), $totalLEMoney),
            ];
            if ($showComparison) {
                $totalLECells[] = Cell::fromValue(sprintf('=E%d+E%d+E%d', $liabilitiesTotalRow, $equityTotalRow, $netIncomeRow), $totalLEMoney);
            }
            $writer->addRow(new Row($totalLECells));
        });
    }

    // ────────────────────────── Income Statement ───────────────────────────

    /**
     * @param  array<string, mixed>  $report
     */
    public function incomeStatement(string $filename, Company $company, array $report, string $startDate, string $endDate, bool $showComparison, ?string $moneyFormat = null): BinaryFileResponse
    {
        $money = $moneyFormat ?? self::MONEY_FORMAT;
        $labels = StatementLabels::for($company);

        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $report, $startDate, $endDate, $showComparison, $money, $labels) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Income Statement');

            $sheet->setColumnWidth(20, 1);
            $sheet->setColumnWidth(12, 2);
            $sheet->setColumnWidth(40, 3);
            $sheet->setColumnWidth(16, 4);
            if ($showComparison) {
                $sheet->setColumnWidth(16, 5);
            }

            $totalColumns = $showComparison ? 5 : 4;
            $headerRowsUsed = $this->writeReportHeader($writer, 'Income Statement', $company->name, [
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: $totalColumns);

            $columnHeaders = $showComparison
                ? ['Section', 'Code', 'Account', 'Current', 'Prior']
                : ['Section', 'Code', 'Account', 'Amount'];

            $writer->addRow(Row::fromValuesWithStyle(
                $columnHeaders,
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $sheet->setSheetView((new SheetView)->withFreezeRow($headerRowsUsed + 2));

            $moneyStyle = $this->makeStyle(format: $money);
            $sectionStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
            $subStyle = $this->makeStyle(italic: true, fontColor: '6B7280');
            $subMoney = $this->makeStyle(italic: true, fontColor: '6B7280', format: $money);

            $rowIndex = $headerRowsUsed + 1;
            $sectionTotalRows = []; // bucket_key => row index

            $emitSection = function (string $key, string $label, array $blocks) use ($writer, $sectionStyle, $subStyle, $subMoney, $moneyStyle, $money, $showComparison, &$rowIndex, &$sectionTotalRows): void {
                if (empty($blocks)) {
                    return;
                }

                $rowIndex++;
                $headerCells = [
                    Cell::fromValue(strtoupper($label), $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                ];
                if ($showComparison) {
                    $headerCells[] = Cell::fromValue('', $sectionStyle);
                }
                $writer->addRow(new Row($headerCells));

                $accountRows = [];

                foreach ($blocks as $block) {
                    if ($block['type'] === 'section') {
                        $rowIndex++;
                        $subHeader = [
                            Cell::fromValue('', $subStyle),
                            Cell::fromValue('', $subStyle),
                            $this->text($block['name'], $subStyle),
                            Cell::fromValue('', $subStyle),
                        ];
                        if ($showComparison) {
                            $subHeader[] = Cell::fromValue('', $subStyle);
                        }
                        $writer->addRow(new Row($subHeader));
                    }

                    $blockFirst = $rowIndex + 1;

                    foreach ($block['rows'] as $a) {
                        $rowIndex++;
                        $accountRows[] = $rowIndex;
                        $cells = [
                            Cell::fromValue(''),
                            $this->text($a['code']),
                            $this->text($a['name']),
                            Cell::fromValue($a['current'] / 100, $moneyStyle),
                        ];
                        if ($showComparison) {
                            $cells[] = Cell::fromValue($a['prior'] / 100, $moneyStyle);
                        }
                        $writer->addRow(new Row($cells));
                    }

                    $blockLast = $rowIndex;

                    if ($block['type'] === 'section') {
                        $rowIndex++;
                        $subTotal = [
                            Cell::fromValue('', $subStyle),
                            Cell::fromValue('', $subStyle),
                            $this->text('Total '.$block['name'], $subStyle),
                            Cell::fromValue($this->sumFormula('D', $blockFirst, $blockLast), $subMoney),
                        ];
                        if ($showComparison) {
                            $subTotal[] = Cell::fromValue($this->sumFormula('E', $blockFirst, $blockLast), $subMoney);
                        }
                        $writer->addRow(new Row($subTotal));
                    }
                }

                $rowIndex++;
                $sectionTotalRows[$key] = $rowIndex;

                $totalLabel = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
                $totalMoney = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: $money);
                $totalBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);

                $sumD = $accountRows !== [] ? '='.implode('+', array_map(fn ($r) => 'D'.$r, $accountRows)) : 0.0;

                $cells = [
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('Total '.$label, $totalLabel),
                    Cell::fromValue($sumD, $totalMoney),
                ];
                if ($showComparison) {
                    $sumE = $accountRows !== [] ? '='.implode('+', array_map(fn ($r) => 'E'.$r, $accountRows)) : 0.0;
                    $cells[] = Cell::fromValue($sumE, $totalMoney);
                }
                $writer->addRow(new Row($cells));
            };

            $emitSection('income', 'Income', $report['income']);

            if (! empty($report['cogs'])) {
                $rowIndex++;
                $writer->addRow(Row::fromValues([]));

                $emitSection('cogs', 'Cost of Goods Sold', $report['cogs']);

                // Gross Profit = Income total − COGS total
                $rowIndex++;
                $gpRow = $rowIndex;
                $sectionTotalRows['gross_profit'] = $gpRow;

                $gpLabel = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL, alignment: CellAlignment::RIGHT);
                $gpMoney = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL, format: $money);
                $gpBlank = $this->makeStyle(backgroundColor: self::SUBHEADER_FILL);

                // Income can be empty (no activity) while COGS is not.
                $gpFormula = fn (string $col): string => isset($sectionTotalRows['income'])
                    ? sprintf('=%s%d-%s%d', $col, $sectionTotalRows['income'], $col, $sectionTotalRows['cogs'])
                    : sprintf('=-%s%d', $col, $sectionTotalRows['cogs']);

                $gpCells = [
                    Cell::fromValue('', $gpBlank),
                    Cell::fromValue('', $gpBlank),
                    Cell::fromValue($labels->grossProfit(), $gpLabel),
                    Cell::fromValue($gpFormula('D'), $gpMoney),
                ];
                if ($showComparison) {
                    $gpCells[] = Cell::fromValue($gpFormula('E'), $gpMoney);
                }
                $writer->addRow(new Row($gpCells));
            }

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));

            $emitSection('expense', 'Expenses', $report['expense']);

            // Net Income
            $rowIndex++;
            $niLabel = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
            $niMoney = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: $money);
            $niBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);

            // Net income formula uses whichever section totals exist; with no
            // activity at all there is nothing to reference, so emit a literal 0.
            $netIncomeFormula = function (string $col) use ($sectionTotalRows): string|float {
                if (isset($sectionTotalRows['gross_profit'])) {
                    $base = $col.$sectionTotalRows['gross_profit'];
                } elseif (isset($sectionTotalRows['income']) && isset($sectionTotalRows['cogs'])) {
                    $base = sprintf('%s%d-%s%d', $col, $sectionTotalRows['income'], $col, $sectionTotalRows['cogs']);
                } elseif (isset($sectionTotalRows['income'])) {
                    $base = $col.$sectionTotalRows['income'];
                } else {
                    $base = '';
                }

                if (isset($sectionTotalRows['expense'])) {
                    $base .= '-'.$col.$sectionTotalRows['expense'];
                }

                return $base === '' ? 0.0 : '='.$base;
            };

            $cur = $netIncomeFormula('D');
            $prior = $showComparison ? $netIncomeFormula('E') : null;

            $cells = [
                Cell::fromValue('', $niBlank),
                Cell::fromValue('', $niBlank),
                Cell::fromValue(Str::upper($labels->netIncome()), $niLabel),
                Cell::fromValue($cur, $niMoney),
            ];
            if ($showComparison) {
                $cells[] = Cell::fromValue($prior, $niMoney);
            }
            $writer->addRow(new Row($cells));
        });
    }

    /**
     * Indirect cash flow statement. Operating leads with Net income; activity totals
     * and net change are live SUM formulas, and cash at end = beginning + net change.
     *
     * @param  array<string, mixed>  $report
     */
    public function cashFlow(string $filename, Company $company, array $report, string $startDate, string $endDate, bool $showComparison, ?string $moneyFormat = null): BinaryFileResponse
    {
        $money = $moneyFormat ?? self::MONEY_FORMAT;

        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $report, $startDate, $endDate, $showComparison, $money) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Cash Flow');

            $sheet->setColumnWidth(20, 1);
            $sheet->setColumnWidth(12, 2);
            $sheet->setColumnWidth(40, 3);
            $sheet->setColumnWidth(16, 4);
            if ($showComparison) {
                $sheet->setColumnWidth(16, 5);
            }

            $totalColumns = $showComparison ? 5 : 4;
            $headerRowsUsed = $this->writeReportHeader($writer, 'Cash Flow Statement', $company->name, [
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: $totalColumns);

            $columnHeaders = $showComparison
                ? ['Section', 'Code', 'Line', 'Current', 'Prior']
                : ['Section', 'Code', 'Line', 'Amount'];

            $writer->addRow(Row::fromValuesWithStyle(
                $columnHeaders,
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $sheet->setSheetView((new SheetView)->withFreezeRow($headerRowsUsed + 2));

            $moneyStyle = $this->makeStyle(format: $money);
            $sectionStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
            $subStyle = $this->makeStyle(italic: true, fontColor: '6B7280');
            $subMoney = $this->makeStyle(italic: true, fontColor: '6B7280', format: $money);
            $totalLabel = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
            $totalMoney = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: $money);
            $totalBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);

            $rowIndex = $headerRowsUsed + 1;
            $activityTotalRows = [];

            $emitActivity = function (string $key, string $label, array $blocks, bool $includeNetIncome) use ($writer, $report, $sectionStyle, $subStyle, $subMoney, $moneyStyle, $totalLabel, $totalMoney, $totalBlank, $showComparison, &$rowIndex, &$activityTotalRows): void {
                $rowIndex++;
                $header = [
                    Cell::fromValue(strtoupper($label), $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                    Cell::fromValue('', $sectionStyle),
                ];
                if ($showComparison) {
                    $header[] = Cell::fromValue('', $sectionStyle);
                }
                $writer->addRow(new Row($header));

                $valueRows = [];

                if ($includeNetIncome) {
                    $rowIndex++;
                    $valueRows[] = $rowIndex;
                    $ni = [
                        Cell::fromValue(''),
                        $this->text(''),
                        $this->text('Net income'),
                        Cell::fromValue($report['net_income'] / 100, $moneyStyle),
                    ];
                    if ($showComparison) {
                        $ni[] = Cell::fromValue($report['prior_net_income'] / 100, $moneyStyle);
                    }
                    $writer->addRow(new Row($ni));
                }

                foreach ($blocks as $block) {
                    if ($block['type'] === 'section') {
                        $rowIndex++;
                        $subHeader = [
                            Cell::fromValue('', $subStyle),
                            Cell::fromValue('', $subStyle),
                            $this->text($block['name'], $subStyle),
                            Cell::fromValue('', $subStyle),
                        ];
                        if ($showComparison) {
                            $subHeader[] = Cell::fromValue('', $subStyle);
                        }
                        $writer->addRow(new Row($subHeader));
                    }

                    $blockFirst = $rowIndex + 1;

                    foreach ($block['rows'] as $a) {
                        $rowIndex++;
                        $valueRows[] = $rowIndex;
                        $cells = [
                            Cell::fromValue(''),
                            $this->text($a['code']),
                            $this->text($a['name']),
                            Cell::fromValue($a['current'] / 100, $moneyStyle),
                        ];
                        if ($showComparison) {
                            $cells[] = Cell::fromValue($a['prior'] / 100, $moneyStyle);
                        }
                        $writer->addRow(new Row($cells));
                    }

                    $blockLast = $rowIndex;

                    if ($block['type'] === 'section') {
                        $rowIndex++;
                        $subTotal = [
                            Cell::fromValue('', $subStyle),
                            Cell::fromValue('', $subStyle),
                            $this->text('Total '.$block['name'], $subStyle),
                            Cell::fromValue($this->sumFormula('D', $blockFirst, $blockLast), $subMoney),
                        ];
                        if ($showComparison) {
                            $subTotal[] = Cell::fromValue($this->sumFormula('E', $blockFirst, $blockLast), $subMoney);
                        }
                        $writer->addRow(new Row($subTotal));
                    }
                }

                $rowIndex++;
                $activityTotalRows[$key] = $rowIndex;
                $sumD = $valueRows !== [] ? '='.implode('+', array_map(fn ($r) => 'D'.$r, $valueRows)) : 0.0;
                $cells = [
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('Net cash from '.$label, $totalLabel),
                    Cell::fromValue($sumD, $totalMoney),
                ];
                if ($showComparison) {
                    $sumE = $valueRows !== [] ? '='.implode('+', array_map(fn ($r) => 'E'.$r, $valueRows)) : 0.0;
                    $cells[] = Cell::fromValue($sumE, $totalMoney);
                }
                $writer->addRow(new Row($cells));
            };

            $emitActivity('operating', 'Operating Activities', $report['operating'], true);
            $rowIndex++;
            $writer->addRow(Row::fromValues([]));
            $emitActivity('investing', 'Investing Activities', $report['investing'], false);
            $rowIndex++;
            $writer->addRow(Row::fromValues([]));
            $emitActivity('financing', 'Financing Activities', $report['financing'], false);

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));

            // Net change in cash = sum of the three activity totals.
            $rowIndex++;
            $netChangeRow = $rowIndex;
            $sumExpr = fn (string $letter): string => sprintf('=%s%d+%s%d+%s%d', $letter, $activityTotalRows['operating'], $letter, $activityTotalRows['investing'], $letter, $activityTotalRows['financing']);
            $netCells = [
                Cell::fromValue('', $totalBlank),
                Cell::fromValue('', $totalBlank),
                Cell::fromValue('NET CHANGE IN CASH', $totalLabel),
                Cell::fromValue($sumExpr('D'), $totalMoney),
            ];
            if ($showComparison) {
                $netCells[] = Cell::fromValue($sumExpr('E'), $totalMoney);
            }
            $writer->addRow(new Row($netCells));

            // Cash at beginning (literal) and end (= beginning + net change).
            $rowIndex++;
            $beginRow = $rowIndex;
            $beginCells = [
                Cell::fromValue(''),
                $this->text(''),
                $this->text('Cash at beginning of period'),
                Cell::fromValue($report['cash_beginning'] / 100, $moneyStyle),
            ];
            if ($showComparison) {
                $beginCells[] = Cell::fromValue($report['prior_cash_beginning'] / 100, $moneyStyle);
            }
            $writer->addRow(new Row($beginCells));

            $rowIndex++;
            $endExpr = fn (string $letter): string => sprintf('=%s%d+%s%d', $letter, $beginRow, $letter, $netChangeRow);
            $endCells = [
                Cell::fromValue('', $totalBlank),
                Cell::fromValue('', $totalBlank),
                Cell::fromValue('Cash at end of period', $totalLabel),
                Cell::fromValue($endExpr('D'), $totalMoney),
            ];
            if ($showComparison) {
                $endCells[] = Cell::fromValue($endExpr('E'), $totalMoney);
            }
            $writer->addRow(new Row($endCells));
        });
    }

    // ───────────────────── Combined (multi-company) ────────────────────────

    /**
     * Combined balance sheet. When $byCompany is true, each combined line is
     * broken out into one column per member company before the Combined column.
     *
     * @param  array<string, mixed>  $report
     */
    public function combinedBalanceSheet(string $filename, ReportGroup $group, array $report, string $asOf, bool $byCompany): BinaryFileResponse
    {
        $labels = StatementLabels::forGroup($group->companies);

        return $this->buildAndStream($filename, function (Writer $writer) use ($group, $report, $asOf, $byCompany, $labels) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Balance Sheet');

            $valueColumns = $this->combinedValueColumns($report['companies'], $byCompany);
            $totalColumns = 1 + count($valueColumns);

            $sheet->setColumnWidth(40, 1);
            for ($c = 2; $c <= $totalColumns; $c++) {
                $sheet->setColumnWidth(16, $c);
            }

            $headerRowsUsed = $this->writeReportHeader($writer, 'Combined Balance Sheet', $group->name, [
                'Currency: '.$group->currency_code,
                'As of '.$asOf,
            ], totalColumns: $totalColumns);

            $this->writeCombinedColumnHeader($writer, 'Line', $valueColumns);
            $sheet->setSheetView((new SheetView)->withFreezeRow($headerRowsUsed + 2));

            $rowIndex = $headerRowsUsed + 1; // column header written

            $emitSection = function (string $title, array $groups, bool $withNetIncome) use ($writer, $report, $valueColumns, $labels, &$rowIndex): array {
                $sectionStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
                $subtypeStyle = $this->makeStyle(italic: true, fontColor: '6B7280');
                $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);

                $rowIndex++;
                $writer->addRow(new Row($this->padRow([Cell::fromValue(strtoupper($title), $sectionStyle)], count($valueColumns), $sectionStyle)));

                $lineRows = [];

                foreach ($groups as $group) {
                    $rowIndex++;
                    $writer->addRow(new Row($this->padRow([$this->text($group['label'], $subtypeStyle)], count($valueColumns))));

                    foreach ($group['blocks'] as $block) {
                        if ($block['type'] === 'section') {
                            $rowIndex++;
                            $writer->addRow(new Row($this->padRow([$this->text($block['name'], $subtypeStyle)], count($valueColumns))));
                        }

                        $blockRows = [];
                        foreach ($block['rows'] as $line) {
                            $rowIndex++;
                            $lineRows[] = $rowIndex;
                            $blockRows[] = $rowIndex;

                            $cells = [$this->text($line['name'])];
                            foreach ($valueColumns as $col) {
                                $value = $col['key'] === 'combined'
                                    ? $line['balance']
                                    : ($line['by_company'][$col['key']] ?? 0);
                                $cells[] = Cell::fromValue($value / 100, $moneyStyle);
                            }
                            $writer->addRow(new Row($cells));
                        }

                        if ($block['type'] === 'section') {
                            $rowIndex++;
                            $this->writeCombinedTotalRow($writer, 'Total '.$block['name'], $valueColumns, $blockRows);
                        }
                    }
                }

                $priorRetainedRow = null;
                if ($withNetIncome && ($report['retained_earnings_prior'] ?? 0) !== 0) {
                    $rowIndex++;
                    $priorRetainedRow = $rowIndex;
                    $cells = [$this->text($labels->retainedEarningsPriorRow(), $this->makeStyle(italic: true))];
                    foreach ($valueColumns as $col) {
                        $value = $col['key'] === 'combined'
                            ? $report['retained_earnings_prior']
                            : ($report['retained_earnings_prior_by_company'][$col['key']] ?? 0);
                        $cells[] = Cell::fromValue($value / 100, $moneyStyle);
                    }
                    $writer->addRow(new Row($cells));
                }

                $netIncomeRow = null;
                if ($withNetIncome && $report['net_income_ytd'] !== 0) {
                    $rowIndex++;
                    $netIncomeRow = $rowIndex;
                    $cells = [$this->text($labels->netIncomeYtd(), $this->makeStyle(italic: true))];
                    foreach ($valueColumns as $col) {
                        $value = $col['key'] === 'combined'
                            ? $report['net_income_ytd']
                            : ($report['net_income_ytd_by_company'][$col['key']] ?? 0);
                        $cells[] = Cell::fromValue($value / 100, $moneyStyle);
                    }
                    $writer->addRow(new Row($cells));
                }

                $rowIndex++;
                $totalRow = $rowIndex;
                $this->writeCombinedTotalRow($writer, 'Total '.$title, $valueColumns, $lineRows);

                return ['total_row' => $totalRow, 'net_income_row' => $netIncomeRow, 'prior_retained_row' => $priorRetainedRow];
            };

            $emitSection('Assets', $report['assets'], false);

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));
            $liabilities = $emitSection('Liabilities', $report['liabilities'], false);

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));
            $equity = $emitSection($labels->equityShort(), $report['equity'], true);

            // Total Liabilities & Equity = liabilities + equity + prior retained earnings + net income (per column).
            $rowIndex++;
            $sources = array_filter([$liabilities['total_row'], $equity['total_row'], $equity['prior_retained_row'], $equity['net_income_row']]);
            $this->writeCombinedTotalRow($writer, $labels->totalLiabilitiesAndEquity(), $valueColumns, $sources, sum: false);
        });
    }

    /**
     * Combined income statement with optional per-company columns.
     *
     * @param  array<string, mixed>  $report
     */
    public function combinedIncomeStatement(string $filename, ReportGroup $group, array $report, string $startDate, string $endDate, bool $byCompany): BinaryFileResponse
    {
        $labels = StatementLabels::forGroup($group->companies);

        return $this->buildAndStream($filename, function (Writer $writer) use ($group, $report, $startDate, $endDate, $byCompany, $labels) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Income Statement');

            $valueColumns = $this->combinedValueColumns($report['companies'], $byCompany);
            $totalColumns = 1 + count($valueColumns);

            $sheet->setColumnWidth(40, 1);
            for ($c = 2; $c <= $totalColumns; $c++) {
                $sheet->setColumnWidth(16, $c);
            }

            $headerRowsUsed = $this->writeReportHeader($writer, 'Combined Income Statement', $group->name, [
                'Currency: '.$group->currency_code,
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: $totalColumns);

            $this->writeCombinedColumnHeader($writer, 'Line', $valueColumns);
            $sheet->setSheetView((new SheetView)->withFreezeRow($headerRowsUsed + 2));

            $rowIndex = $headerRowsUsed + 1;
            $sectionTotalRows = [];

            $emitSection = function (string $key, string $label, array $blocks) use ($writer, $valueColumns, &$rowIndex, &$sectionTotalRows): void {
                if (empty($blocks)) {
                    return;
                }

                $sectionStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
                $subStyle = $this->makeStyle(italic: true, fontColor: '6B7280');
                $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);

                $rowIndex++;
                $writer->addRow(new Row($this->padRow([Cell::fromValue(strtoupper($label), $sectionStyle)], count($valueColumns), $sectionStyle)));

                $lineRows = [];
                foreach ($blocks as $block) {
                    if ($block['type'] === 'section') {
                        $rowIndex++;
                        $writer->addRow(new Row($this->padRow([$this->text($block['name'], $subStyle)], count($valueColumns))));
                    }

                    $blockRows = [];
                    foreach ($block['rows'] as $line) {
                        $rowIndex++;
                        $lineRows[] = $rowIndex;
                        $blockRows[] = $rowIndex;

                        $cells = [$this->text($line['name'])];
                        foreach ($valueColumns as $col) {
                            $value = $col['key'] === 'combined'
                                ? $line['current']
                                : ($line['by_company'][$col['key']] ?? 0);
                            $cells[] = Cell::fromValue($value / 100, $moneyStyle);
                        }
                        $writer->addRow(new Row($cells));
                    }

                    if ($block['type'] === 'section') {
                        $rowIndex++;
                        $this->writeCombinedTotalRow($writer, 'Total '.$block['name'], $valueColumns, $blockRows);
                    }
                }

                $rowIndex++;
                $sectionTotalRows[$key] = $rowIndex;
                $this->writeCombinedTotalRow($writer, 'Total '.$label, $valueColumns, $lineRows);
            };

            $emitSection('income', 'Income', $report['income']);

            if (! empty($report['cogs'])) {
                $rowIndex++;
                $writer->addRow(Row::fromValues([]));
                $emitSection('cogs', 'Cost of Goods Sold', $report['cogs']);

                $rowIndex++;
                $sectionTotalRows['gross_profit'] = $rowIndex;
                $this->writeCombinedFormulaRow($writer, $labels->grossProfit(), $valueColumns, fn (string $letter) => sprintf('=%s%d-%s%d', $letter, $sectionTotalRows['income'], $letter, $sectionTotalRows['cogs']), self::SUBHEADER_FILL);
            }

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));
            $emitSection('expense', 'Expenses', $report['expense']);

            // Net income, per column, from whichever section totals exist.
            $rowIndex++;
            $this->writeCombinedFormulaRow($writer, Str::upper($labels->netIncome()), $valueColumns, function (string $letter) use ($sectionTotalRows): string {
                if (isset($sectionTotalRows['gross_profit'])) {
                    $expr = $letter.$sectionTotalRows['gross_profit'];
                } elseif (isset($sectionTotalRows['cogs'])) {
                    $expr = sprintf('%s%d-%s%d', $letter, $sectionTotalRows['income'], $letter, $sectionTotalRows['cogs']);
                } else {
                    $expr = $letter.($sectionTotalRows['income'] ?? 0);
                }
                if (isset($sectionTotalRows['expense'])) {
                    $expr .= '-'.$letter.$sectionTotalRows['expense'];
                }

                return '='.$expr;
            }, self::TOTAL_FILL);
        });
    }

    /**
     * Combined indirect cash flow statement. Operating leads with Net income (per
     * company when broken out); activity totals and net change are live formulas.
     * Cash at beginning/end show in the Combined column only.
     *
     * @param  array<string, mixed>  $report
     */
    public function combinedCashFlow(string $filename, ReportGroup $group, array $report, string $startDate, string $endDate, bool $byCompany): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($group, $report, $startDate, $endDate, $byCompany) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Cash Flow');

            $valueColumns = $this->combinedValueColumns($report['companies'], $byCompany);
            $totalColumns = 1 + count($valueColumns);

            $sheet->setColumnWidth(40, 1);
            for ($c = 2; $c <= $totalColumns; $c++) {
                $sheet->setColumnWidth(16, $c);
            }

            $headerRowsUsed = $this->writeReportHeader($writer, 'Combined Cash Flow Statement', $group->name, [
                'Currency: '.$group->currency_code,
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: $totalColumns);

            $this->writeCombinedColumnHeader($writer, 'Line', $valueColumns);
            $sheet->setSheetView((new SheetView)->withFreezeRow($headerRowsUsed + 2));

            $rowIndex = $headerRowsUsed + 1;
            $activityTotalRows = [];

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $sectionStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
            $subStyle = $this->makeStyle(italic: true, fontColor: '6B7280');

            $emitActivity = function (string $key, string $label, array $blocks, bool $includeNetIncome) use ($writer, $report, $valueColumns, $sectionStyle, $subStyle, $moneyStyle, &$rowIndex, &$activityTotalRows): void {
                $rowIndex++;
                $writer->addRow(new Row($this->padRow([Cell::fromValue(strtoupper($label), $sectionStyle)], count($valueColumns), $sectionStyle)));

                $lineRows = [];

                if ($includeNetIncome) {
                    $rowIndex++;
                    $lineRows[] = $rowIndex;
                    $cells = [$this->text('Net income')];
                    foreach ($valueColumns as $col) {
                        $value = $col['key'] === 'combined'
                            ? $report['net_income']
                            : ($report['net_income_by_company'][$col['key']] ?? 0);
                        $cells[] = Cell::fromValue($value / 100, $moneyStyle);
                    }
                    $writer->addRow(new Row($cells));
                }

                foreach ($blocks as $block) {
                    if ($block['type'] === 'section') {
                        $rowIndex++;
                        $writer->addRow(new Row($this->padRow([$this->text($block['name'], $subStyle)], count($valueColumns))));
                    }

                    $blockRows = [];
                    foreach ($block['rows'] as $line) {
                        $rowIndex++;
                        $lineRows[] = $rowIndex;
                        $blockRows[] = $rowIndex;

                        $cells = [$this->text($line['name'])];
                        foreach ($valueColumns as $col) {
                            $value = $col['key'] === 'combined'
                                ? $line['current']
                                : ($line['by_company'][$col['key']] ?? 0);
                            $cells[] = Cell::fromValue($value / 100, $moneyStyle);
                        }
                        $writer->addRow(new Row($cells));
                    }

                    if ($block['type'] === 'section') {
                        $rowIndex++;
                        $this->writeCombinedTotalRow($writer, 'Total '.$block['name'], $valueColumns, $blockRows);
                    }
                }

                $rowIndex++;
                $activityTotalRows[$key] = $rowIndex;
                $this->writeCombinedTotalRow($writer, 'Net cash from '.$label, $valueColumns, $lineRows);
            };

            $emitActivity('operating', 'Operating Activities', $report['operating'], true);
            $rowIndex++;
            $writer->addRow(Row::fromValues([]));
            $emitActivity('investing', 'Investing Activities', $report['investing'], false);
            $rowIndex++;
            $writer->addRow(Row::fromValues([]));
            $emitActivity('financing', 'Financing Activities', $report['financing'], false);

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));

            // Net change = sum of the three activity totals, per column.
            $rowIndex++;
            $netChangeRow = $rowIndex;
            $this->writeCombinedFormulaRow($writer, 'NET CHANGE IN CASH', $valueColumns, fn (string $letter): string => sprintf('=%s%d+%s%d+%s%d', $letter, $activityTotalRows['operating'], $letter, $activityTotalRows['investing'], $letter, $activityTotalRows['financing']), self::TOTAL_FILL);

            // Beginning/end cash only carry a Combined figure (per-company cash splits
            // are not computed); other columns stay blank.
            $combinedColumn = end($valueColumns)['column'];
            $combinedLetter = $this->columnLetter($combinedColumn);

            $labelStyle = $this->makeStyle(alignment: CellAlignment::RIGHT);

            $rowIndex++;
            $beginRow = $rowIndex;
            $beginCells = [Cell::fromValue('Cash at beginning of period', $labelStyle)];
            foreach ($valueColumns as $col) {
                $beginCells[] = $col['key'] === 'combined'
                    ? Cell::fromValue($report['cash_beginning'] / 100, $moneyStyle)
                    : Cell::fromValue('');
            }
            $writer->addRow(new Row($beginCells));

            $rowIndex++;
            $endLabelStyle = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
            $endMoneyStyle = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: self::MONEY_FORMAT);
            $endBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);
            $endCells = [Cell::fromValue('Cash at end of period', $endLabelStyle)];
            foreach ($valueColumns as $col) {
                $endCells[] = $col['key'] === 'combined'
                    ? Cell::fromValue(sprintf('=%s%d+%s%d', $combinedLetter, $beginRow, $combinedLetter, $netChangeRow), $endMoneyStyle)
                    : Cell::fromValue('', $endBlank);
            }
            $writer->addRow(new Row($endCells));
        });
    }

    /**
     * Combined trial balance: raw accounts grouped by company with subtotals and
     * a grand total. Always balanced; line mappings do not apply here.
     *
     * @param  array<string, mixed>  $report
     */
    public function combinedTrialBalance(string $filename, ReportGroup $group, array $report, string $asOf): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($group, $report, $asOf) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Trial Balance');

            $sheet->setColumnWidth(46, 1);
            $sheet->setColumnWidth(16, 2, 3);

            $headerRowsUsed = $this->writeReportHeader($writer, 'Combined Trial Balance', $group->name, [
                'Currency: '.$group->currency_code,
                'As of '.$asOf,
            ], totalColumns: 3);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Account', 'Debit', 'Credit'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $companyHeaderStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);

            $rowIndex = $columnHeaderRow;
            $subtotalRows = [];

            foreach ($report['companies'] as $section) {
                $rowIndex++;
                $writer->addRow(new Row([
                    $this->text($section['company']['name'], $companyHeaderStyle),
                    Cell::fromValue('', $companyHeaderStyle),
                    Cell::fromValue('', $companyHeaderStyle),
                ]));

                $firstRow = $rowIndex + 1;
                foreach ($section['rows'] as $row) {
                    $rowIndex++;
                    $writer->addRow(new Row([
                        $this->text($row['code'].' — '.$row['name']),
                        Cell::fromValue($row['debit'] ? $row['debit'] / 100 : null, $moneyStyle),
                        Cell::fromValue($row['credit'] ? $row['credit'] / 100 : null, $moneyStyle),
                    ]));
                }
                $lastRow = $rowIndex;

                $rowIndex++;
                $subtotalRows[] = $rowIndex;
                $this->writeTotalsRow($writer, [
                    'Subtotal',
                    $this->sumFormula('B', $firstRow, $lastRow),
                    $this->sumFormula('C', $firstRow, $lastRow),
                ], moneyColumns: [2, 3]);
            }

            $rowIndex++;
            $writer->addRow(Row::fromValues([]));

            $rowIndex++;
            $debitFormula = $subtotalRows !== [] ? '='.implode('+', array_map(fn ($r) => 'B'.$r, $subtotalRows)) : 0.0;
            $creditFormula = $subtotalRows !== [] ? '='.implode('+', array_map(fn ($r) => 'C'.$r, $subtotalRows)) : 0.0;
            $this->writeTotalsRow($writer, ['Total', $debitFormula, $creditFormula], moneyColumns: [2, 3]);
        });
    }

    /**
     * Ordered value columns for a combined report: one per company (when
     * $byCompany) followed by the Combined column. Each carries a sheet column
     * index for formula building.
     *
     * @param  array<int, array{id: int, name: string}>  $companies
     * @return array<int, array{key: int|string, name: string, column: int}>
     */
    private function combinedValueColumns(array $companies, bool $byCompany): array
    {
        $columns = [];
        $index = 2; // column 1 is the label

        if ($byCompany) {
            foreach ($companies as $company) {
                $columns[] = ['key' => $company['id'], 'name' => $company['name'], 'column' => $index++];
            }
        }

        $columns[] = ['key' => 'combined', 'name' => 'Combined', 'column' => $index];

        return $columns;
    }

    /**
     * @param  array<int, array{key: int|string, name: string, column: int}>  $valueColumns
     */
    private function writeCombinedColumnHeader(Writer $writer, string $labelHeading, array $valueColumns): void
    {
        $style = $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL);
        $cells = [Cell::fromValue($labelHeading, $style)];
        foreach ($valueColumns as $col) {
            $cells[] = $this->text($col['name'], $style);
        }
        $writer->addRow(new Row($cells));
    }

    /**
     * Total row whose value cells SUM (or add) the given source rows for each column.
     *
     * @param  array<int, array{key: int|string, name: string, column: int}>  $valueColumns
     * @param  array<int, int>  $sourceRows
     */
    private function writeCombinedTotalRow(Writer $writer, string $label, array $valueColumns, array $sourceRows, bool $sum = true): void
    {
        $labelStyle = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
        $moneyStyle = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: self::MONEY_FORMAT);

        $cells = [Cell::fromValue($label, $labelStyle)];
        foreach ($valueColumns as $col) {
            $letter = $this->columnLetter($col['column']);
            $formula = $sourceRows !== []
                ? '='.implode('+', array_map(fn ($r) => $letter.$r, $sourceRows))
                : 0.0;
            $cells[] = Cell::fromValue($formula, $moneyStyle);
        }
        $writer->addRow(new Row($cells));
    }

    /**
     * Total row whose value cells are built by a per-column formula callback.
     *
     * @param  array<int, array{key: int|string, name: string, column: int}>  $valueColumns
     * @param  callable(string): string  $formula  receives the column letter
     */
    private function writeCombinedFormulaRow(Writer $writer, string $label, array $valueColumns, callable $formula, string $fill): void
    {
        $labelStyle = $this->makeStyle(bold: true, backgroundColor: $fill, alignment: CellAlignment::RIGHT);
        $moneyStyle = $this->makeStyle(bold: true, backgroundColor: $fill, format: self::MONEY_FORMAT);

        $cells = [Cell::fromValue($label, $labelStyle)];
        foreach ($valueColumns as $col) {
            $cells[] = Cell::fromValue($formula($this->columnLetter($col['column'])), $moneyStyle);
        }
        $writer->addRow(new Row($cells));
    }

    /**
     * Pad a partial set of leading cells out to a full row of 1 + $valueCount
     * columns, styling the filler cells.
     *
     * @param  array<int, Cell>  $leading
     * @return array<int, Cell>
     */
    private function padRow(array $leading, int $valueCount, ?Style $fillStyle = null): array
    {
        $total = 1 + $valueCount;
        while (count($leading) < $total) {
            $leading[] = Cell::fromValue('', $fillStyle);
        }

        return $leading;
    }

    // ──────────────────────────── Sales Tax ────────────────────────────────

    /**
     * @param  array<int, array{agency: string, payable_account: string, collected: int, paid: int, net: int}>  $rows
     */
    public function salesTax(string $filename, Company $company, array $rows, string $startDate, string $endDate): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $rows, $startDate, $endDate) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Sales Tax');

            $sheet->setColumnWidth(28, 1);
            $sheet->setColumnWidth(34, 2);
            $sheet->setColumnWidth(18, 3, 4, 5);

            $headerRowsUsed = $this->writeReportHeader($writer, 'Sales Tax', $company->name, [
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: 5);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Agency', 'Payable account', 'Collected on sales', 'Paid (ITC)', 'Net owing'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $firstDataRow = $columnHeaderRow + 1;

            $rowIndex = $columnHeaderRow;
            foreach ($rows as $r) {
                $rowIndex++;
                $writer->addRow(new Row([
                    $this->text($r['agency']),
                    $this->text($r['payable_account']),
                    Cell::fromValue($r['collected'] / 100, $moneyStyle),
                    Cell::fromValue($r['paid'] / 100, $moneyStyle),
                    Cell::fromValue(sprintf('=C%d-D%d', $rowIndex, $rowIndex), $moneyStyle),
                ]));
            }
            $lastDataRow = $rowIndex;

            if ($lastDataRow >= $firstDataRow) {
                $this->writeTotalsRow($writer, [
                    'Total', '',
                    $this->sumFormula('C', $firstDataRow, $lastDataRow),
                    $this->sumFormula('D', $firstDataRow, $lastDataRow),
                    $this->sumFormula('E', $firstDataRow, $lastDataRow),
                ], moneyColumns: [3, 4, 5]);
            }
        });
    }

    /**
     * @param  array<int, array{contact_id: int, name: string, tax_number: ?string, total_cents: int, meets_threshold: bool}>  $rows
     */
    public function form1099(string $filename, Company $company, array $rows, int $year): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $rows, $year) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('1099 Summary');

            $sheet->setColumnWidth(36, 1);
            $sheet->setColumnWidth(20, 2);
            $sheet->setColumnWidth(18, 3);

            $headerRowsUsed = $this->writeReportHeader($writer, '1099 Summary', $company->name, [
                'Calendar year: '.$year,
            ], totalColumns: 3);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Vendor', 'Tax ID', 'Total paid'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $firstDataRow = $columnHeaderRow + 1;

            $rowIndex = $columnHeaderRow;
            foreach ($rows as $r) {
                $rowIndex++;
                $writer->addRow(new Row([
                    $this->text($r['name']),
                    $this->text($r['tax_number'] ?? ''),
                    Cell::fromValue($r['total_cents'] / 100, $moneyStyle),
                ]));
            }
            $lastDataRow = $rowIndex;

            if ($lastDataRow >= $firstDataRow) {
                $this->writeTotalsRow($writer, [
                    'Total', '',
                    $this->sumFormula('C', $firstDataRow, $lastDataRow),
                ], moneyColumns: [3]);
            }
        });
    }

    /**
     * Payroll register: one row per employee × pay run, with a live SUM() total.
     *
     * @param  array<int, array{name: string, run_no: string, pay_date: string, gross_cents: int, cpp_cents: int, ei_cents: int, tax_cents: int, deductions_cents: int, employer_cents: int, net_cents: int}>  $rows
     */
    public function payrollRegister(string $filename, Company $company, array $rows, string $startDate, string $endDate): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $rows, $startDate, $endDate) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Payroll Register');

            $headerRowsUsed = $this->writeReportHeader($writer, 'Payroll Register', $company->name, [
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: 10);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Employee', 'Run #', 'Pay date', 'Gross', 'CPP/QPP', 'EI/QPIP', 'Income tax', 'Other deductions', 'Employer cost', 'Net'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $firstDataRow = $columnHeaderRow + 1;
            $rowIndex = $columnHeaderRow;

            foreach ($rows as $r) {
                $rowIndex++;
                $writer->addRow(new Row([
                    $this->text($r['name']),
                    $this->text($r['run_no']),
                    $this->text($r['pay_date']),
                    Cell::fromValue($r['gross_cents'] / 100, $moneyStyle),
                    Cell::fromValue($r['cpp_cents'] / 100, $moneyStyle),
                    Cell::fromValue($r['ei_cents'] / 100, $moneyStyle),
                    Cell::fromValue($r['tax_cents'] / 100, $moneyStyle),
                    Cell::fromValue($r['deductions_cents'] / 100, $moneyStyle),
                    Cell::fromValue($r['employer_cents'] / 100, $moneyStyle),
                    Cell::fromValue($r['net_cents'] / 100, $moneyStyle),
                ]));
            }
            $lastDataRow = $rowIndex;

            if ($lastDataRow >= $firstDataRow) {
                $this->writeTotalsRow($writer, [
                    'Total', '', '',
                    $this->sumFormula('D', $firstDataRow, $lastDataRow),
                    $this->sumFormula('E', $firstDataRow, $lastDataRow),
                    $this->sumFormula('F', $firstDataRow, $lastDataRow),
                    $this->sumFormula('G', $firstDataRow, $lastDataRow),
                    $this->sumFormula('H', $firstDataRow, $lastDataRow),
                    $this->sumFormula('I', $firstDataRow, $lastDataRow),
                    $this->sumFormula('J', $firstDataRow, $lastDataRow),
                ], moneyColumns: [4, 5, 6, 7, 8, 9, 10]);
            }
        });
    }

    /**
     * Per-document drill-down for a single Sales Tax bucket (collected or paid).
     *
     * @param  Collection<int, array{entry_date: CarbonImmutable, doc_label: string, amount_cents: int, is_reversal: bool}>  $lines
     */
    public function salesTaxDetail(string $filename, Company $company, string $agencyName, string $bucket, Collection $lines, string $startDate, string $endDate): BinaryFileResponse
    {
        $bucketLabel = $bucket === 'paid' ? 'Paid (ITC)' : 'Collected on sales';

        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $agencyName, $bucketLabel, $lines, $startDate, $endDate) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(substr('Sales Tax '.$bucketLabel, 0, 30));

            $sheet->setColumnWidth(14, 1);
            $sheet->setColumnWidth(48, 2);
            $sheet->setColumnWidth(18, 3);

            $headerRowsUsed = $this->writeReportHeader($writer, 'Sales Tax — '.$bucketLabel, $company->name, [
                $agencyName,
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: 3);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Date', 'Document', 'Amount'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $firstDataRow = $columnHeaderRow + 1;

            $rowIndex = $columnHeaderRow;
            foreach ($lines as $line) {
                $rowIndex++;
                $writer->addRow(new Row([
                    Cell::fromValue($line['entry_date']->toDateString()),
                    $this->text($line['doc_label']),
                    Cell::fromValue($line['amount_cents'] / 100, $moneyStyle),
                ]));
            }
            $lastDataRow = $rowIndex;

            if ($lastDataRow >= $firstDataRow) {
                $this->writeTotalsRow($writer, [
                    '', 'Total',
                    $this->sumFormula('C', $firstDataRow, $lastDataRow),
                ], moneyColumns: [3]);
            }
        });
    }

    // ───────────────────────────── Transactions ────────────────────────────

    /**
     * Posted journal lines behind a figure (QuickZoom drill target). `$rows` may
     * be a lazy generator; it is iterated exactly once so the full range never
     * materialises. When $grouped, each row carries a leading 'group' label
     * column (flat rows, no subtotal rows).
     *
     * @param  iterable<int, array{group?: string, date: string, entry_no: ?string, account: string, name: ?string, memo: ?string, debit: int, credit: int}>  $rows
     */
    public function transactions(string $filename, Company $company, iterable $rows, string $startDate, string $endDate, ?string $context = null, bool $grouped = false): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $rows, $startDate, $endDate, $context, $grouped) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Transactions');

            $offset = $grouped ? 1 : 0;

            if ($grouped) {
                $sheet->setColumnWidth(28, 1);
            }
            $sheet->setColumnWidth(14, 1 + $offset);
            $sheet->setColumnWidth(14, 2 + $offset);
            $sheet->setColumnWidth(36, 3 + $offset, 4 + $offset);
            $sheet->setColumnWidth(40, 5 + $offset);
            $sheet->setColumnWidth(16, 6 + $offset, 7 + $offset);

            $headerRowsUsed = $this->writeReportHeader($writer, 'Transactions', $company->name, [
                $context,
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: 7 + $offset);

            $columnHeaders = ['Date', 'Entry #', 'Account', 'Name', 'Memo', 'Debit', 'Credit'];

            if ($grouped) {
                array_unshift($columnHeaders, 'Group');
            }

            $writer->addRow(Row::fromValuesWithStyle(
                $columnHeaders,
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $firstDataRow = $columnHeaderRow + 1;

            $rowIndex = $columnHeaderRow;
            foreach ($rows as $r) {
                $rowIndex++;
                $cells = [
                    Cell::fromValue($r['date']),
                    $this->text($r['entry_no'] ?? ''),
                    $this->text($r['account']),
                    $this->text($r['name'] ?? ''),
                    $this->text($r['memo'] ?? ''),
                    Cell::fromValue($r['debit'] / 100, $moneyStyle),
                    Cell::fromValue($r['credit'] / 100, $moneyStyle),
                ];

                if ($grouped) {
                    array_unshift($cells, $this->text($r['group'] ?? ''));
                }

                $writer->addRow(new Row($cells));
            }
            $lastDataRow = $rowIndex;

            if ($lastDataRow >= $firstDataRow) {
                $totals = array_merge(
                    ['Total'],
                    array_fill(0, 4 + $offset, ''),
                    [
                        $this->sumFormula($grouped ? 'G' : 'F', $firstDataRow, $lastDataRow),
                        $this->sumFormula($grouped ? 'H' : 'G', $firstDataRow, $lastDataRow),
                    ],
                );

                $this->writeTotalsRow($writer, $totals, moneyColumns: [6 + $offset, 7 + $offset]);
            }
        });
    }

    // ──────────────────────────── AR / AP Aging ────────────────────────────

    /**
     * @param  array{rows: array<int, array{name: string, current: int, b1_30: int, b31_60: int, b61_90: int, b90_plus: int, total: int}>, totals: array<string, int>}  $report
     */
    public function aging(string $filename, string $title, string $entityLabel, Company $company, array $report, string $asOf): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($title, $entityLabel, $company, $report, $asOf) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(substr($title, 0, 30));

            $sheet->setColumnWidth(32, 1);
            $sheet->setColumnWidth(14, 2, 3, 4, 5, 6, 7);

            $headerRowsUsed = $this->writeReportHeader($writer, $title, $company->name, [
                'As of '.$asOf,
            ], totalColumns: 7);

            $writer->addRow(Row::fromValuesWithStyle(
                [$entityLabel, 'Current', '1–30', '31–60', '61–90', '90+', 'Total'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $totalRowMoney = $this->makeStyle(bold: true, format: self::MONEY_FORMAT);
            $firstDataRow = $columnHeaderRow + 1;

            $rowIndex = $columnHeaderRow;
            foreach ($report['rows'] as $row) {
                $rowIndex++;
                $writer->addRow(new Row([
                    $this->text($row['name']),
                    Cell::fromValue($row['current'] / 100, $moneyStyle),
                    Cell::fromValue($row['b1_30'] / 100, $moneyStyle),
                    Cell::fromValue($row['b31_60'] / 100, $moneyStyle),
                    Cell::fromValue($row['b61_90'] / 100, $moneyStyle),
                    Cell::fromValue($row['b90_plus'] / 100, $moneyStyle),
                    Cell::fromValue(sprintf('=SUM(B%d:F%d)', $rowIndex, $rowIndex), $totalRowMoney),
                ]));
            }
            $lastDataRow = $rowIndex;

            if ($lastDataRow >= $firstDataRow) {
                $this->writeTotalsRow($writer, [
                    'Total',
                    $this->sumFormula('B', $firstDataRow, $lastDataRow),
                    $this->sumFormula('C', $firstDataRow, $lastDataRow),
                    $this->sumFormula('D', $firstDataRow, $lastDataRow),
                    $this->sumFormula('E', $firstDataRow, $lastDataRow),
                    $this->sumFormula('F', $firstDataRow, $lastDataRow),
                    $this->sumFormula('G', $firstDataRow, $lastDataRow),
                ], moneyColumns: [2, 3, 4, 5, 6, 7]);
            }
        });
    }

    /**
     * Aging detail: every open document individually, grouped by bucket with
     * subtotals, then the GL-reconciling Adjustments section and a grand
     * total that ties to the control account (see OpenDocumentAgingBuilder).
     *
     * @param  array{buckets: array<string, array{label: string, rows: array<int, array{doc_no: string, name: string, doc_date: string, due_date: string, days_overdue: int, balance: int}>, subtotal: int}>, adjustments: array<int, array{contact_id: int, name: string, amount: int}>, adjustments_total: int, grand_total: int}  $report
     */
    public function agingDetail(string $filename, string $title, string $entityLabel, string $docLabel, Company $company, array $report, string $asOf): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($title, $entityLabel, $docLabel, $company, $report, $asOf) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(substr($title, 0, 30));

            $sheet->setColumnWidth(16, 1);  // Doc #
            $sheet->setColumnWidth(32, 2);  // Contact
            $sheet->setColumnWidth(14, 3, 4);  // Date, Due
            $sheet->setColumnWidth(14, 5, 6);  // Days overdue, Balance

            $headerRowsUsed = $this->writeReportHeader($writer, $title, $company->name, [
                'As of '.$asOf,
            ], totalColumns: 6);

            $writer->addRow(Row::fromValuesWithStyle(
                [$docLabel.' #', $entityLabel, 'Date', 'Due', 'Days overdue', 'Balance'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $sectionStyle = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL);
            $subMoney = $this->makeStyle(italic: true, fontColor: '6B7280', format: self::MONEY_FORMAT);
            $subLabel = $this->makeStyle(italic: true, fontColor: '6B7280');

            $rowIndex = $columnHeaderRow;
            $subtotalRows = [];

            foreach ($report['buckets'] as $bucket) {
                if (empty($bucket['rows'])) {
                    continue;
                }

                $rowIndex++;
                $writer->addRow(Row::fromValuesWithStyle(
                    [$bucket['label'], '', '', '', '', ''],
                    $sectionStyle,
                ));

                $firstRow = $rowIndex + 1;

                foreach ($bucket['rows'] as $row) {
                    $rowIndex++;
                    $writer->addRow(new Row([
                        $this->text($row['doc_no']),
                        $this->text($row['name']),
                        $this->text($row['doc_date']),
                        $this->text($row['due_date']),
                        Cell::fromValue($row['days_overdue']),
                        Cell::fromValue($row['balance'] / 100, $moneyStyle),
                    ]));
                }

                $rowIndex++;
                $subtotalRows[] = $rowIndex;
                $writer->addRow(new Row([
                    Cell::fromValue('', $subLabel),
                    Cell::fromValue('', $subLabel),
                    Cell::fromValue('', $subLabel),
                    Cell::fromValue('', $subLabel),
                    $this->text('Total '.$bucket['label'], $subLabel),
                    Cell::fromValue($this->sumFormula('F', $firstRow, $rowIndex - 1), $subMoney),
                ]));
            }

            if (! empty($report['adjustments'])) {
                $rowIndex++;
                $writer->addRow(Row::fromValuesWithStyle(
                    ['Adjustments (credits, unapplied payments & ledger entries)', '', '', '', '', ''],
                    $sectionStyle,
                ));

                $firstRow = $rowIndex + 1;

                foreach ($report['adjustments'] as $adjustment) {
                    $rowIndex++;
                    $writer->addRow(new Row([
                        $this->text('—'),
                        $this->text($adjustment['name']),
                        $this->text(''),
                        $this->text(''),
                        $this->text(''),
                        Cell::fromValue($adjustment['amount'] / 100, $moneyStyle),
                    ]));
                }

                $rowIndex++;
                $subtotalRows[] = $rowIndex;
                $writer->addRow(new Row([
                    Cell::fromValue('', $subLabel),
                    Cell::fromValue('', $subLabel),
                    Cell::fromValue('', $subLabel),
                    Cell::fromValue('', $subLabel),
                    $this->text('Total adjustments', $subLabel),
                    Cell::fromValue($this->sumFormula('F', $firstRow, $rowIndex - 1), $subMoney),
                ]));
            }

            if ($subtotalRows !== []) {
                $grandFormula = '='.implode('+', array_map(fn (int $r) => 'F'.$r, $subtotalRows));

                $this->writeTotalsRow($writer, [
                    'Grand total', '', '', '', '', $grandFormula,
                ], moneyColumns: [6]);
            }
        });
    }

    /**
     * @param  array{rows: array<int, array{invoice_no: string, name: string, invoice_date: string, due_date: ?string, total: int, paid: int, balance: int}>, totals: array{total: int, paid: int, balance: int, count: int}}  $report
     */
    public function openInvoices(string $filename, Company $company, array $report, string $asOf): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $report, $asOf) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Open Invoices');

            $sheet->setColumnWidth(16, 1);  // Invoice
            $sheet->setColumnWidth(36, 2);  // Customer
            $sheet->setColumnWidth(14, 3, 4);  // Date, Due
            $sheet->setColumnWidth(14, 5, 6, 7);  // Total, Paid, Balance

            $headerRowsUsed = $this->writeReportHeader($writer, 'Open Invoices', $company->name, [
                'As of '.$asOf,
            ], totalColumns: 7);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Invoice', 'Customer', 'Date', 'Due', 'Total', 'Paid', 'Balance'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $firstDataRow = $columnHeaderRow + 1;

            $rowIndex = $columnHeaderRow;
            foreach ($report['rows'] as $row) {
                $rowIndex++;
                $writer->addRow(new Row([
                    $this->text($row['invoice_no']),
                    $this->text($row['name']),
                    $this->text($row['invoice_date']),
                    $this->text($row['due_date'] ?? ''),
                    Cell::fromValue($row['total'] / 100, $moneyStyle),
                    Cell::fromValue($row['paid'] / 100, $moneyStyle),
                    Cell::fromValue($row['balance'] / 100, $moneyStyle),
                ]));
            }
            $lastDataRow = $rowIndex;

            if ($lastDataRow >= $firstDataRow) {
                $this->writeTotalsRow($writer, [
                    $report['totals']['count'].' open invoices',
                    '',
                    '',
                    '',
                    $this->sumFormula('E', $firstDataRow, $lastDataRow),
                    $this->sumFormula('F', $firstDataRow, $lastDataRow),
                    $this->sumFormula('G', $firstDataRow, $lastDataRow),
                ], moneyColumns: [5, 6, 7]);
            }
        });
    }

    /**
     * @param  array{rows: array<int, array{bill_no: string, name: string, bill_date: string, due_date: ?string, total: int, paid: int, balance: int}>, totals: array{count: int, total: int, paid: int, balance: int}}  $report
     */
    public function openBills(string $filename, Company $company, array $report, string $asOf): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $report, $asOf) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Open Bills');

            $sheet->setColumnWidth(16, 1);  // Bill
            $sheet->setColumnWidth(36, 2);  // Vendor
            $sheet->setColumnWidth(14, 3, 4);  // Date, Due
            $sheet->setColumnWidth(14, 5, 6, 7);  // Total, Paid, Balance

            $headerRowsUsed = $this->writeReportHeader($writer, 'Open Bills', $company->name, [
                'As of '.$asOf,
            ], totalColumns: 7);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Bill', 'Vendor', 'Date', 'Due', 'Total', 'Paid', 'Balance'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $firstDataRow = $columnHeaderRow + 1;

            $rowIndex = $columnHeaderRow;
            foreach ($report['rows'] as $row) {
                $rowIndex++;
                $writer->addRow(new Row([
                    $this->text($row['bill_no']),
                    $this->text($row['name']),
                    $this->text($row['bill_date']),
                    $this->text($row['due_date'] ?? ''),
                    Cell::fromValue($row['total'] / 100, $moneyStyle),
                    Cell::fromValue($row['paid'] / 100, $moneyStyle),
                    Cell::fromValue($row['balance'] / 100, $moneyStyle),
                ]));
            }
            $lastDataRow = $rowIndex;

            if ($lastDataRow >= $firstDataRow) {
                $this->writeTotalsRow($writer, [
                    $report['totals']['count'].' open bills',
                    '',
                    '',
                    '',
                    $this->sumFormula('E', $firstDataRow, $lastDataRow),
                    $this->sumFormula('F', $firstDataRow, $lastDataRow),
                    $this->sumFormula('G', $firstDataRow, $lastDataRow),
                ], moneyColumns: [5, 6, 7]);
            }
        });
    }

    // ─────────────────────────── Contact Statement ─────────────────────────

    /**
     * @param  array{opening: int, lines: array<int, array{date: string, doc_no: string, type: string, memo: string, debit: int, credit: int, running: int}>, period_debit: int, period_credit: int, closing: int}  $report
     */
    public function contactStatement(string $filename, string $title, Company $company, Contact $contact, array $report, string $startDate, string $endDate): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($title, $company, $contact, $report, $startDate, $endDate) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName(substr($title, 0, 30));

            $sheet->setColumnWidth(14, 1);  // Date
            $sheet->setColumnWidth(14, 2);  // Type
            $sheet->setColumnWidth(16, 3);  // Doc #
            $sheet->setColumnWidth(48, 4);  // Memo
            $sheet->setColumnWidth(14, 5, 6, 7);

            $headerRowsUsed = $this->writeReportHeader($writer, $title, $company->name, [
                $contact->display_name,
                'Period: '.$startDate.' to '.$endDate,
            ], totalColumns: 7);

            $writer->addRow(Row::fromValuesWithStyle(
                ['Date', 'Type', 'Doc #', 'Memo', 'Debit', 'Credit', 'Running'],
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $columnHeaderRow = $headerRowsUsed + 1;
            $sheet->setSheetView((new SheetView)->withFreezeRow($columnHeaderRow + 1));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);
            $italicGrey = $this->makeStyle(italic: true, fontColor: '6B7280');

            $writer->addRow(new Row([
                Cell::fromValue('Opening balance', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue('', $italicGrey),
                Cell::fromValue($report['opening'] / 100, $moneyStyle),
            ]));

            $firstDataRow = $columnHeaderRow + 2;
            $rowIndex = $firstDataRow;

            foreach ($report['lines'] as $line) {
                $writer->addRow(new Row([
                    Cell::fromValue($line['date']),
                    $this->text($line['type']),
                    $this->text($line['doc_no']),
                    $this->text($line['memo'] ?? ''),
                    Cell::fromValue($line['debit'] ? $line['debit'] / 100 : null, $moneyStyle),
                    Cell::fromValue($line['credit'] ? $line['credit'] / 100 : null, $moneyStyle),
                    Cell::fromValue($line['running'] / 100, $moneyStyle),
                ]));
                $rowIndex++;
            }
            $lastDataRow = $rowIndex - 1;

            $totalLabel = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
            $totalBlank = $this->makeStyle(backgroundColor: self::TOTAL_FILL);
            $totalMoney = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: self::MONEY_FORMAT);

            if ($lastDataRow >= $firstDataRow) {
                $writer->addRow(new Row([
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('', $totalBlank),
                    Cell::fromValue('Period totals', $totalLabel),
                    Cell::fromValue($this->sumFormula('E', $firstDataRow, $lastDataRow), $totalMoney),
                    Cell::fromValue($this->sumFormula('F', $firstDataRow, $lastDataRow), $totalMoney),
                    Cell::fromValue('', $totalBlank),
                ]));
            }

            $closingLabel = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL, alignment: CellAlignment::RIGHT);
            $closingBlank = $this->makeStyle(backgroundColor: self::SUBHEADER_FILL);
            $closingMoney = $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL, format: self::MONEY_FORMAT);

            $writer->addRow(new Row([
                Cell::fromValue('Closing balance', $closingLabel),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue('', $closingBlank),
                Cell::fromValue($report['closing'] / 100, $closingMoney),
            ]));
        });
    }

    // ───────────────────────── Bank reconciliation ─────────────────────────

    /**
     * @param  array{payments: Collection, deposits: Collection, payments_total_cents: int, deposits_total_cents: int}  $detail
     */
    public function bankReconciliation(
        string $filename,
        Company $company,
        BankReconciliation $rec,
        array $detail,
        int $clearedBalanceCents,
        int $differenceCents,
    ): BinaryFileResponse {
        return $this->buildAndStream($filename, function (Writer $writer) use ($company, $rec, $detail, $clearedBalanceCents, $differenceCents) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('Reconciliation');

            $sheet->setColumnWidth(14, 1);
            $sheet->setColumnWidth(18, 2);
            $sheet->setColumnWidth(40, 3);
            $sheet->setColumnWidth(14, 4);

            $this->writeReportHeader($writer, 'Bank Reconciliation', $company->name, [
                ($rec->account?->code ?? '').' — '.($rec->account?->name ?? ''),
                'Statement date: '.$rec->statement_date->toDateString(),
                'Status: '.$rec->status->label(),
            ], totalColumns: 4);

            $boldFill = $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL);
            $money = $this->makeStyle(format: self::MONEY_FORMAT);
            $moneyBold = $this->makeStyle(bold: true, format: self::MONEY_FORMAT);

            $writer->addRow(Row::fromValuesWithStyle(['Summary', '', '', ''], $boldFill));

            foreach ([
                ['Beginning balance', $rec->beginning_balance_cents],
                ['Ending balance', $rec->ending_balance_cents],
                ['Service charge', $rec->service_charge_cents],
                ['Interest earned', $rec->interest_earned_cents],
                ['Cleared deposits ('.$detail['deposits']->count().')', $detail['deposits_total_cents']],
                ['Cleared payments ('.$detail['payments']->count().')', $detail['payments_total_cents']],
            ] as [$label, $cents]) {
                $writer->addRow(new Row([
                    Cell::fromValue($label),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue($cents / 100, $money),
                ]));
            }

            $writer->addRow(new Row([
                Cell::fromValue('Cleared balance', $this->makeStyle(bold: true)),
                Cell::fromValue(''),
                Cell::fromValue(''),
                Cell::fromValue($clearedBalanceCents / 100, $moneyBold),
            ]));

            $writer->addRow(new Row([
                Cell::fromValue('Difference', $this->makeStyle(bold: true)),
                Cell::fromValue(''),
                Cell::fromValue(''),
                Cell::fromValue($differenceCents / 100, $moneyBold),
            ]));

            $writer->addRow(Row::fromValues([]));

            $this->writeReconciliationSection($writer, 'Deposits and Other Credits', $detail['deposits'], 'debit_cents', $detail['deposits_total_cents']);

            $writer->addRow(Row::fromValues([]));

            $this->writeReconciliationSection($writer, 'Cheques and Payments', $detail['payments'], 'credit_cents', $detail['payments_total_cents']);
        });
    }

    private function writeReconciliationSection(Writer $writer, string $title, Collection $lines, string $amountCol, int $totalCents): void
    {
        $writer->addRow(Row::fromValuesWithStyle([$title, '', '', ''], $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL)));
        $writer->addRow(Row::fromValuesWithStyle(['Date', 'Entry #', 'Memo', 'Amount'], $this->makeStyle(bold: true, backgroundColor: self::SUBHEADER_FILL)));

        $money = $this->makeStyle(format: self::MONEY_FORMAT);

        foreach ($lines as $line) {
            $writer->addRow(new Row([
                Cell::fromValue($line->journalEntry->entry_date->toDateString()),
                $this->text($line->journalEntry->entry_no),
                $this->text($line->memo ?? $line->journalEntry->memo ?? ''),
                Cell::fromValue(((int) $line->{$amountCol}) / 100, $money),
            ]));
        }

        $this->writeTotalsRow($writer, ['Subtotal', '', '', $totalCents / 100], moneyColumns: [4]);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * Write the report header: title on the left, generation timestamp in the
     * top-right cell (column $totalColumns). Returns the count of rows written.
     */
    private function writeReportHeader(Writer $writer, string $title, ?string $subtitle, array $metaLines, int $totalColumns): int
    {
        $titleStyle = $this->makeStyle(bold: true, fontSize: 14);
        $generatedStyle = $this->makeStyle(italic: true, fontSize: 10, fontColor: '6B7280', alignment: CellAlignment::RIGHT);

        $generatedAt = 'Generated '.CarbonImmutable::now()->format('Y-m-d H:i');

        // First row: title in col 1, generation timestamp in last column
        $firstRowCells = [];
        for ($i = 1; $i <= $totalColumns; $i++) {
            if ($i === 1) {
                $firstRowCells[] = Cell::fromValue($title, $titleStyle);
            } elseif ($i === $totalColumns) {
                $firstRowCells[] = Cell::fromValue($generatedAt, $generatedStyle);
            } else {
                $firstRowCells[] = Cell::fromValue('');
            }
        }
        $writer->addRow(new Row($firstRowCells));
        $written = 1;

        if ($subtitle !== null && $subtitle !== '') {
            // company name — user-controlled, so force a text cell (no formula).
            $writer->addRow(new Row([$this->text($subtitle, $this->makeStyle(bold: true))]));
            $written++;
        }

        foreach ($metaLines as $line) {
            if ($line === null || $line === '') {
                continue;
            }
            // may carry account/contact/agency names — force a text cell.
            $writer->addRow(new Row([$this->text($line, $this->makeStyle(italic: true, fontSize: 10))]));
            $written++;
        }

        // blank spacer row
        $writer->addRow(Row::fromValues([]));
        $written++;

        return $written;
    }

    /**
     * Emit a styled totals row. `moneyColumns` (1-based) get the money number format.
     */
    private function writeTotalsRow(Writer $writer, array $values, array $moneyColumns = []): void
    {
        $labelStyle = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, alignment: CellAlignment::RIGHT);
        $moneyStyle = $this->makeStyle(bold: true, backgroundColor: self::TOTAL_FILL, format: self::MONEY_FORMAT);
        $blankStyle = $this->makeStyle(backgroundColor: self::TOTAL_FILL);

        $cells = [];
        foreach ($values as $i => $v) {
            $col = $i + 1;
            $style = in_array($col, $moneyColumns, true)
                ? $moneyStyle
                : ($v === '' ? $blankStyle : $labelStyle);
            $cells[] = Cell::fromValue($v, $style);
        }

        $writer->addRow(new Row($cells));
    }

    /**
     * Build a forced text cell for user-controlled values (memos, names, codes,
     * doc labels). Cell::fromValue() turns any string starting with "=" into a
     * live FormulaCell, which would let attacker-entered text execute when the
     * spreadsheet is opened (CWE-1236). A StringCell is always inert text, so
     * our intentional =SUM() formula cells (built via Cell::fromValue) stay the
     * only formulas in the workbook.
     */
    private function text(?string $value, ?Style $style = null): Cell
    {
        return new StringCell((string) ($value ?? ''), $style);
    }

    private function sumFormula(string $column, int $firstRow, int $lastRow): string|float
    {
        if ($lastRow < $firstRow) {
            return 0.0;
        }

        return sprintf('=SUM(%s%d:%s%d)', $column, $firstRow, $column, $lastRow);
    }

    /**
     * Convert a 1-based column index to its spreadsheet letter (1→A, 27→AA).
     */
    private function columnLetter(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    /**
     * Generic flat list export (Account List, contact lists): report header,
     * one bold column-header row (frozen), one row per record, and an optional
     * totals row. `moneyColumns` (1-based) carry integer cents and are written
     * as numbers with the money format; everything else is forced text.
     *
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|null>>  $rows
     * @param  list<int>  $moneyColumns  1-based indexes of cents columns
     * @param  array<int, int|float>  $columnWidths  1-based column index => width
     * @param  array<int, string|int|null>|null  $totals  optional totals row (money columns in cents)
     */
    public function listTable(string $filename, string $sheetName, string $title, Company $company, array $metaLines, array $headers, iterable $rows, array $moneyColumns = [], array $columnWidths = [], ?array $totals = null): BinaryFileResponse
    {
        return $this->buildAndStream($filename, function (Writer $writer) use ($sheetName, $title, $company, $metaLines, $headers, $rows, $moneyColumns, $columnWidths, $totals) {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName($sheetName);

            foreach ($columnWidths as $index => $width) {
                $sheet->setColumnWidth($width, $index);
            }

            $headerRowsUsed = $this->writeReportHeader($writer, $title, $company->name, $metaLines, totalColumns: count($headers));

            $writer->addRow(Row::fromValuesWithStyle(
                $headers,
                $this->makeStyle(bold: true, backgroundColor: self::HEADER_FILL),
            ));
            $sheet->setSheetView((new SheetView)->withFreezeRow($headerRowsUsed + 2));

            $moneyStyle = $this->makeStyle(format: self::MONEY_FORMAT);

            foreach ($rows as $row) {
                $cells = [];
                foreach (array_values($row) as $i => $value) {
                    $cells[] = in_array($i + 1, $moneyColumns, true)
                        ? Cell::fromValue(((int) $value) / 100, $moneyStyle)
                        : $this->text($value === null ? '' : (string) $value);
                }
                $writer->addRow(new Row($cells));
            }

            if ($totals !== null) {
                $values = [];
                foreach (array_values($totals) as $i => $value) {
                    $values[] = in_array($i + 1, $moneyColumns, true) ? ((int) $value) / 100 : $value;
                }
                $this->writeTotalsRow($writer, $values, moneyColumns: $moneyColumns);
            }
        });
    }

    private function buildAndStream(string $filename, callable $build): BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-');

        $writer = new Writer;
        $writer->openToFile($tmp);

        $build($writer);

        $writer->close();

        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])
            ->deleteFileAfterSend(true)
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
    }

    private function makeStyle(
        bool $bold = false,
        bool $italic = false,
        ?int $fontSize = null,
        ?string $fontColor = null,
        ?string $backgroundColor = null,
        ?string $format = null,
        ?CellAlignment $alignment = null,
    ): Style {
        $style = new Style;

        if ($bold) {
            $style = $style->withFontBold(true);
        }
        if ($italic) {
            $style = $style->withFontItalic(true);
        }
        if ($fontSize !== null) {
            $style = $style->withFontSize($fontSize);
        }
        if ($fontColor !== null) {
            $style = $style->withFontColor($fontColor);
        }
        if ($backgroundColor !== null) {
            $style = $style->withBackgroundColor($backgroundColor);
        }
        if ($format !== null) {
            $style = $style->withFormat($format);
        }
        if ($alignment !== null) {
            $style = $style->withCellAlignment($alignment);
        }

        return $style;
    }
}
