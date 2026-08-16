<?php

namespace App\Services\Printing;

use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\PayrollCheque;
use App\Support\Money;
use TCPDF;

/**
 * Renders QuickBooks-style voucher cheques (cheque on top, two stubs below).
 *
 * Draws the full cheque by default (labels + data) — suitable for printing
 * onto blank paper. For pre-printed Intuit stock, set
 * `config('cheque.draw_static_labels')` to false so only data lands in the
 * pre-printed fields.
 */
class ChequePdfRenderer
{
    /**
     * Pure-data view of what will be drawn — useful for testing without
     * parsing PDF binary.
     *
     * @return array{
     *     date_mmddyyyy: string,
     *     date_slashed: string,
     *     payee: string,
     *     amount_numeric: string,
     *     amount_words: string,
     *     total_numeric: string,
     *     memo: string,
     *     bank_account_name: string,
     *     lines: array<int, array{account: string, description: string, amount: string}>,
     * }
     */
    public function dataFor(BillPayment|Cheque|PayrollCheque $source): array
    {
        return match (true) {
            $source instanceof Cheque => $this->dataForCheque($source),
            $source instanceof PayrollCheque => $this->dataForPayrollCheque($source),
            default => $this->dataForBillPayment($source),
        };
    }

    /**
     * Returns the binary PDF.
     */
    public function render(BillPayment|Cheque|PayrollCheque $source): string
    {
        $reference = match (true) {
            $source instanceof Cheque, $source instanceof PayrollCheque => (string) $source->cheque_no,
            default => (string) ($source->reference ?? ''),
        };

        return $this->renderPdf($this->dataFor($source), $reference, $source->company);
    }

    /**
     * A payroll cheque prints as a pay stub: net pay on the cheque band, with the
     * earnings and deduction breakdown on the voucher stubs.
     *
     * @return array{date_mmddyyyy: string, date_slashed: string, payee: string, amount_numeric: string, amount_words: string, total_numeric: string, memo: string, bank_account_name: string, lines: array<int, array{account: string, description: string, amount: string}>}
     */
    private function dataForPayrollCheque(PayrollCheque $cheque): array
    {
        $cheque->loadMissing('bankAccount', 'payee', 'payRunLine.earnings', 'payRunLine.deductions', 'payRun');

        $amount = Money::fromCents((int) $cheque->amount_cents);
        $padWidth = (int) config('cheque.amount_words_pad_width', 60);
        $totalDecimal = number_format($cheque->amount_cents / 100, 2, '.', ',');

        $line = $cheque->payRunLine;
        $stub = [];

        foreach ($line->earnings as $earning) {
            $stub[] = [
                'account' => (string) $earning->name,
                'description' => __('Earning'),
                'amount' => number_format((int) $earning->amount_cents / 100, 2, '.', ','),
            ];
        }

        foreach ([
            ['CPP', $line->cppEmployeeCents() + $line->cpp2EmployeeCents()],
            ['EI', $line->eiEmployeeCents()],
            ['Federal tax', $line->federalTaxCents() + $line->additionalTaxCents()],
            ['Provincial tax', $line->provincialTaxCents()],
        ] as [$label, $cents]) {
            if ($cents > 0) {
                $stub[] = ['account' => $label, 'description' => __('Deduction'), 'amount' => '-'.number_format($cents / 100, 2, '.', ',')];
            }
        }

        foreach ($line->deductions as $deduction) {
            $stub[] = ['account' => (string) $deduction->name, 'description' => __('Deduction'), 'amount' => '-'.number_format((int) $deduction->amount_cents / 100, 2, '.', ',')];
        }

        return [
            'date_mmddyyyy' => $cheque->cheque_date->format('mdY'),
            'date_slashed' => $cheque->cheque_date->format('n/j/Y'),
            'payee' => (string) ($cheque->payee_name ?: ($cheque->payee?->display_name ?? '')),
            'amount_numeric' => '**'.$totalDecimal,
            'amount_words' => str_pad($amount->toWords(), $padWidth, '*', STR_PAD_LEFT),
            'total_numeric' => $totalDecimal,
            'memo' => __('Net pay :run', ['run' => (string) $cheque->payRun->run_no]),
            'bank_account_name' => (string) ($cheque->bankAccount?->name ?? ''),
            'lines' => $stub,
        ];
    }

    /**
     * @return array{date_mmddyyyy: string, date_slashed: string, payee: string, amount_numeric: string, amount_words: string, total_numeric: string, memo: string, bank_account_name: string, lines: array<int, array{account: string, description: string, amount: string}>}
     */
    private function dataForBillPayment(BillPayment $payment): array
    {
        $payment->loadMissing('contact', 'paidFromAccount', 'applications.bill');

        $amount = Money::fromCents((int) $payment->amount_cents);
        $padWidth = (int) config('cheque.amount_words_pad_width', 60);
        $totalDecimal = number_format($payment->amount_cents / 100, 2, '.', ',');

        return [
            'date_mmddyyyy' => $payment->payment_date->format('mdY'),
            'date_slashed' => $payment->payment_date->format('n/j/Y'),
            'payee' => (string) $payment->contact->display_name,
            'amount_numeric' => '**'.$totalDecimal,
            'amount_words' => str_pad($amount->toWords(), $padWidth, '*', STR_PAD_LEFT),
            'total_numeric' => $totalDecimal,
            'memo' => (string) ($payment->memo ?? ''),
            'bank_account_name' => (string) ($payment->paidFromAccount?->name ?? ''),
            'lines' => $payment->applications->map(fn ($app) => [
                'account' => (string) (optional($app->bill)->bill_no ?? ''),
                'description' => (string) (optional($app->bill)->memo ?? ''),
                'amount' => number_format((int) $app->amount_cents / 100, 2, '.', ','),
            ])->all(),
        ];
    }

    /**
     * @return array{date_mmddyyyy: string, date_slashed: string, payee: string, amount_numeric: string, amount_words: string, total_numeric: string, memo: string, bank_account_name: string, lines: array<int, array{account: string, description: string, amount: string}>}
     */
    private function dataForCheque(Cheque $cheque): array
    {
        $cheque->loadMissing('bankAccount', 'payee', 'lines.account');

        $amount = Money::fromCents((int) $cheque->amount_cents);
        $padWidth = (int) config('cheque.amount_words_pad_width', 60);
        $totalDecimal = number_format($cheque->amount_cents / 100, 2, '.', ',');

        $payee = (string) ($cheque->payee_name ?: ($cheque->payee?->display_name ?? ''));

        return [
            'date_mmddyyyy' => $cheque->cheque_date->format('mdY'),
            'date_slashed' => $cheque->cheque_date->format('n/j/Y'),
            'payee' => $payee,
            'amount_numeric' => '**'.$totalDecimal,
            'amount_words' => str_pad($amount->toWords(), $padWidth, '*', STR_PAD_LEFT),
            'total_numeric' => $totalDecimal,
            'memo' => (string) ($cheque->memo ?? ''),
            'bank_account_name' => (string) ($cheque->bankAccount?->name ?? ''),
            'lines' => $cheque->lines->map(fn ($line) => [
                'account' => (string) (optional($line->account)->code ?? ''),
                'description' => (string) ($line->description ?? ''),
                'amount' => number_format(((int) $line->amount_cents + (int) $line->tax_cents) / 100, 2, '.', ','),
            ])->all(),
        ];
    }

    /**
     * @param  array{date_mmddyyyy: string, date_slashed: string, payee: string, amount_numeric: string, amount_words: string, total_numeric: string, memo: string, bank_account_name: string, lines: array<int, array{account: string, description: string, amount: string}>}  $data
     */
    private function renderPdf(array $data, string $reference, ?Company $company = null): string
    {
        $cfg = config('cheque');
        $fields = $cfg['fields'];
        // Per-company calibration overrides the global config drift when set, so
        // non-technical users can self-align to their printer/tray in Settings.
        $ox = $company?->cheque_offset_x !== null ? (float) $company->cheque_offset_x : (float) $cfg['offset_x'];
        $oy = $company?->cheque_offset_y !== null ? (float) $company->cheque_offset_y : (float) $cfg['offset_y'];
        $bodySize = (int) $cfg['fonts']['size_body'];
        $combSize = (int) $cfg['fonts']['size_date_comb'];
        $labelSize = (int) $cfg['fonts']['size_label'];
        $subscriptSize = (int) $cfg['fonts']['size_subscript'];
        $family = (string) $cfg['fonts']['family'];
        $drawLabels = (bool) ($cfg['draw_static_labels'] ?? true);

        $pdf = new TCPDF('P', 'pt', 'LETTER', true, 'UTF-8', false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('LineLedger');
        $pdf->SetAuthor('LineLedger');
        $pdf->SetTitle('Cheque '.$reference);
        $pdf->AddPage();

        /**
         * Place text using top-of-glyph coordinates from the spec.
         * TCPDF::Text() draws at the baseline, so we add the font size
         * (close enough to ascender height for monospaced calibration).
         */
        $place = function (float $x, float $top, string $text, string $align = 'L', ?int $size = null) use ($pdf, $family, $bodySize, $ox, $oy) {
            $size = $size ?? $bodySize;
            $pdf->SetFont($family, '', $size);
            $baselineY = $top + $size + $oy;
            $drawX = $x + $ox;
            if ($align === 'R') {
                $drawX -= $pdf->GetStringWidth($text);
            }
            $pdf->Text($drawX, $baselineY, $text);
        };

        // ---------- CHEQUE BAND ----------
        [$dx, $dy] = $fields['cheque_date_first_digit'];
        $pitch = (float) $cfg['date_digit_pitch'];

        if ($drawLabels) {
            // "DATE" label to the left of the digit block.
            $place($dx + (float) $cfg['date_label_offset'], $dy + 3.0, 'DATE', 'L', $labelSize);
        }

        // The digits themselves.
        foreach (str_split($data['date_mmddyyyy']) as $i => $digit) {
            $place($dx + $i * $pitch, $dy, $digit, 'L', $combSize);
        }

        if ($drawLabels) {
            // "M M D D Y Y Y Y" subscript below each digit.
            $subscriptTop = $dy + (float) $cfg['date_subscript_drop'];
            foreach (['M', 'M', 'D', 'D', 'Y', 'Y', 'Y', 'Y'] as $i => $letter) {
                // Centre each letter under its digit by half-pitch.
                $place($dx + $i * $pitch + 1.5, $subscriptTop, $letter, 'L', $subscriptSize);
            }
        }

        // Amount in words (star-padded). Optional "DOLLARS" suffix at end of line.
        [$x, $y] = $fields['cheque_amount_words'];
        $place($x, $y, $data['amount_words']);

        // Numeric amount. The leading "**" tamper-fill makes the "$" prefix
        // redundant — QuickBooks omits the $ on its data line for the same reason.
        [$x, $y] = $fields['cheque_amount_numeric'];
        $place($x, $y, $data['amount_numeric']);

        // Payee — left-justified to the same x as the amount-in-words line.
        [$x, $y] = $fields['cheque_payee'];
        $place($x, $y, $data['payee']);

        // Memo.
        if ($data['memo'] !== '' || $drawLabels) {
            if ($drawLabels) {
                [$lx, $ly] = $fields['cheque_memo_label'];
                $place($lx, $ly, 'MEMO', 'L', $labelSize);
            }
            if ($data['memo'] !== '') {
                [$x, $y] = $fields['cheque_memo'];
                $place($x, $y, $data['memo']);
            }
        }

        // ---------- VOUCHERS (band + band + 252) ----------
        $bandPitch = (float) $cfg['voucher_band_pitch'];
        $lineHeight = (float) $cfg['voucher_line_height'];
        $maxLines = (int) $cfg['voucher_max_lines'];
        $rightEdge = (float) $cfg['amount_right_edge'];
        $descX = (float) $fields['voucher_detail_desc_x'];

        foreach ([0.0, $bandPitch] as $bandOffset) {
            [$x, $y] = $fields['voucher_payee'];
            $place($x, $y + $bandOffset, $data['payee']);

            [$x, $y] = $fields['voucher_date'];
            $place($x, $y + $bandOffset, $data['date_slashed']);

            [$rowX, $rowY] = $fields['voucher_detail_first_row'];

            // QuickBooks-style vouchers omit column headers — payee/date band
            // at the top and the summary band at the bottom imply the columns.

            $cursorY = $rowY;
            $rendered = 0;
            foreach ($data['lines'] as $line) {
                if ($rendered >= $maxLines) {
                    break;
                }
                $place($rowX, $cursorY + $bandOffset, $line['account']);
                $place($descX, $cursorY + $bandOffset, $line['description']);
                $place($rightEdge, $cursorY + $bandOffset, $line['amount'], 'R');
                $cursorY += $lineHeight;
                $rendered++;
            }

            // Summary row content.
            [$x, $y] = $fields['voucher_summary_account'];
            $place($x, $y + $bandOffset, $data['bank_account_name']);

            [$descx, $descy] = $fields['voucher_summary_desc'];
            $place($descx, $descy + $bandOffset, $data['memo']);
            $place($rightEdge, $descy + $bandOffset, $data['total_numeric'], 'R');
        }

        return $pdf->Output('cheque.pdf', 'S');
    }
}
