<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Support\Payroll\PayrollAccountNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a GIFI statement as a standard GIFI file (.gfi, "fichier IGRF" in
 * French), the file Canadian tax software imports Schedule 100 and Schedule
 * 125 from: DT Max, Corporate Taxprep, ProFile, TaxCycle and Pro Tax.
 *
 * The CRA no longer publishes the layout, so it follows the files the
 * accounting programs those packages name produce (CaseWare, Sage 50 /
 * Simply Accounting, QuickBooks Online):
 *
 *   "GIFI01","0001","ASCII","-","L",<vendor>,…,<BN>,<name>,…,<year-end>,<created>,""
 *   "1000",12345.67           one "code",amount line per GIFI line
 *   "1741",-500.00
 *
 * The header is 24 quoted fields. The ones a tax program reads are the
 * Business Number (14), the corporation's name and address (15, 17–20), the
 * fiscal year-end (22, yyyyMMdd0900) and the date the file was made (23,
 * yyyyMMddHHmm). Fields 2–5 describe the numbers: record format 0001, ASCII
 * text, a "-" sign written on the left ("L"), and field 13 their two decimal
 * places. Each data line carries the line's natural balance as the statement
 * shows it, so assets and expenses are positive debits and liabilities,
 * equity and revenue positive credits; contra lines (accumulated
 * amortization) and a deficit go negative. Amounts keep their cents, which
 * the tax software rounds to dollars on import. CRA total items are never
 * written: the tax software computes them. Lines end in CRLF.
 *
 * Retained earnings go out as 3600, the Schedule 100 line, at their balance at
 * year-end: the statement shows the current year's net income on its own row,
 * which is folded in here so the file's balance sheet balances.
 */
final class GifiFileExporter
{
    public function __construct(private readonly ReportCalculator $calc) {}

    /**
     * Why the statement cannot be written as a .gfi file, or null when it can.
     * A file with an account left out, an unbalanced balance sheet, or a period
     * that is not a fiscal year would import into the return as wrong figures,
     * so it is refused rather than written.
     *
     * @param  array<string, mixed>  $report  {@see GifiStatementBuilder::build()}
     */
    public function refusal(Company $company, array $report, CarbonInterface $start, CarbonInterface $end): ?string
    {
        $unassigned = count($report['unassigned']['lines']);

        if ($unassigned > 0) {
            return trans_choice(
                '{1} Give the account under Unassigned accounts a GIFI line first, so the file includes it.|[2,*] Give the :count accounts under Unassigned accounts a GIFI line first, so the file includes them.',
                $unassigned,
                ['count' => $unassigned],
            );
        }

        if (! $report['bs']['balanced']) {
            return __('The balance sheet is out of balance, so it cannot be written as a GIFI file.');
        }

        if (! $this->calc->fiscalYearStart($company, $end)->isSameDay($start)) {
            return __('A GIFI file covers one fiscal year. Set the period to start on the first day of the fiscal year.');
        }

        return null;
    }

    /**
     * The .gfi file's content.
     *
     * @param  array<string, mixed>  $report  {@see GifiStatementBuilder::build()}
     */
    public function content(Company $company, array $report, CarbonInterface $end, CarbonInterface $createdAt): string
    {
        $lines = [$this->header($company, $end, $createdAt)];

        foreach ($this->amounts($report) as $code => $cents) {
            $lines[] = '"'.$code.'",'.self::amount($cents);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param  array<string, mixed>  $report  {@see GifiStatementBuilder::build()}
     */
    public function download(string $filename, Company $company, array $report, CarbonInterface $end): StreamedResponse
    {
        $content = $this->content($company, $report, $end, CarbonImmutable::now($company->timezone ?: config('app.timezone')));

        return new StreamedResponse(function () use ($content) {
            echo $content;
        }, 200, [
            'Content-Type' => 'text/plain; charset=us-ascii',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GIFI code => amount in cents, in code order, zero lines left out. GIFI
     * codes are four digits from 1000 up, so they are keyed as ints, as PHP
     * would store them anyway.
     *
     * @param  array<string, mixed>  $report
     * @return array<int, int>
     */
    public function amounts(array $report): array
    {
        $amounts = [];

        foreach (['bs', 'is'] as $statement) {
            foreach ($report[$statement]['halves'] as $half) {
                foreach ($half['sections'] as $section) {
                    foreach ($section['lines'] as $line) {
                        $code = (int) $line['code'];
                        $amounts[$code] = ($amounts[$code] ?? 0) + (int) $line['amount'];
                    }
                }
            }
        }

        $netIncome = (int) $report['bs']['halves']['equity']['net_income'];

        if ($netIncome !== 0) {
            $amounts[3600] = ($amounts[3600] ?? 0) + $netIncome;
        }

        $amounts = array_filter($amounts, fn (int $cents): bool => $cents !== 0);
        ksort($amounts);

        return $amounts;
    }

    private function header(Company $company, CarbonInterface $end, CarbonInterface $createdAt): string
    {
        $address = trim(implode(' ', array_filter([(string) $company->address_line1, (string) $company->address_line2])));

        $fields = [
            'GIFI01',
            '0001',
            'ASCII',
            '-',
            'L',
            'LineLedger',
            '',
            '',
            '',
            '',
            'LineLedger',
            (string) config('version.app'),
            '02',
            $this->corporationAccount($company),
            (string) ($company->legal_name ?: $company->name),
            '',
            $address,
            (string) $company->address_city,
            strtoupper((string) $company->address_region),
            strtoupper((string) $company->address_postal_code),
            '',
            $end->format('Ymd').'0900',
            $createdAt->format('YmdHi'),
            '',
        ];

        return implode(',', array_map(fn (string $field): string => '"'.self::text($field).'"', $fields));
    }

    /**
     * The corporation income tax program account (123456789RC0001), from the
     * nine-digit Business Number at the front of the GST/HST number or in the
     * payroll settings; empty when neither has one. Tax software keeps the BN
     * already on the return, so an empty one does no harm.
     */
    private function corporationAccount(Company $company): string
    {
        foreach ([$company->payroll_business_number, $company->tax_number] as $candidate) {
            $normalized = PayrollAccountNumber::normalize((string) $candidate);

            if (preg_match('/^(\d{9})(?:[A-Z]{2}\d{4})?$/', $normalized, $match) === 1) {
                return $match[1].'RC0001';
            }
        }

        return '';
    }

    /** Printable ASCII with no quote, so a field never breaks the line. */
    private static function text(string $value): string
    {
        $ascii = Str::ascii($value);

        return trim((string) preg_replace(['/[^\x20-\x7E]/', '/"/', '/ {2,}/'], ['', ' ', ' '], (string) preg_replace('/[\r\n\t]+/', ' ', $ascii)));
    }

    /** Cents as a plain decimal: 1234567 → 12345.67, -5 → -0.05. */
    private static function amount(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
