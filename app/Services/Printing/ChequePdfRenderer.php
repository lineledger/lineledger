<?php

namespace App\Services\Printing;

use App\Models\Account;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PayrollCheque;
use App\Support\Contacts\AddressLines;
use App\Support\Locales;
use App\Support\Money;

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
     *     date_comb: string,
     *     date_slashed: string,
     *     payee: string,
     *     amount_numeric: string,
     *     amount_words: string,
     *     total_numeric: string,
     *     memo: string,
     *     bank_account_name: string,
     *     address_lines: array<int, string>,
     *     lines: array<int, array{account: string, description: string, amount: string}>,
     * }
     */
    public function dataFor(BillPayment|Cheque|PayrollCheque $source): array
    {
        $source->loadMissing('company');

        return Locales::forContactDocument($this->contactFor($source), $source->company, fn (): array => match (true) {
            $source instanceof Cheque => $this->dataForCheque($source),
            $source instanceof PayrollCheque => $this->dataForPayrollCheque($source),
            default => $this->dataForBillPayment($source),
        });
    }

    private function contactFor(BillPayment|Cheque|PayrollCheque $source): ?Contact
    {
        if ($source instanceof BillPayment) {
            $source->loadMissing('contact');

            return $source->contact;
        }

        $source->loadMissing('payee');

        return $source->payee;
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
     * @return array{date_comb: string, date_slashed: string, payee: string, amount_numeric: string, amount_words: string, total_numeric: string, memo: string, bank_account_name: string, address_lines: array<int, string>, lines: array<int, array{account: string, description: string, amount: string}>}
     */
    private function dataForPayrollCheque(PayrollCheque $cheque): array
    {
        $cheque->loadMissing('bankAccount', 'payee', 'payRunLine.earnings', 'payRunLine.deductions', 'payRun');

        $amount = Money::fromCents((int) $cheque->amount_cents);
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
            'date_comb' => $cheque->cheque_date->format($this->dateCombFormat()),
            'date_slashed' => $cheque->cheque_date->format('n/j/Y'),
            'payee' => (string) ($cheque->payee_name ?: ($cheque->payee?->display_name ?? '')),
            'amount_numeric' => '**'.$totalDecimal,
            'amount_words' => $this->amountInWords($amount),
            'total_numeric' => $totalDecimal,
            'memo' => __('Net pay :run', ['run' => (string) $cheque->payRun->run_no]),
            'bank_account_name' => (string) ($cheque->bankAccount?->name ?? ''),
            // Payroll cheques are usually handed over, not mailed, and an
            // employee's home address on a shared printer is its own decision.
            'address_lines' => [],
            'lines' => $stub,
        ];
    }

    /**
     * The address block for a cheque: its own snapshot, taken when the cheque
     * was written, so a reprint shows where the cheque actually went.
     *
     * Cheques written before the snapshot existed have none, so those fall back
     * to the payee's current address — better than printing nothing on a
     * reprint. A free-text payee with no linked contact has neither, and prints
     * no address at all.
     *
     * @return list<string>
     */
    private function chequeAddressLines(Cheque $cheque): array
    {
        $snapshot = [
            'line1' => $cheque->payee_line1,
            'line2' => $cheque->payee_line2,
            'city' => $cheque->payee_city,
            'region' => $cheque->payee_region,
            'postal_code' => $cheque->payee_postal_code,
            'country' => $cheque->payee_country,
        ];

        if (AddressLines::isEmpty($snapshot)) {
            return AddressLines::forContact($cheque->payee, $cheque->company);
        }

        return AddressLines::format($snapshot, $cheque->company);
    }

    /**
     * @return array{date_comb: string, date_slashed: string, payee: string, amount_numeric: string, amount_words: string, total_numeric: string, memo: string, bank_account_name: string, address_lines: array<int, string>, lines: array<int, array{account: string, description: string, amount: string}>}
     */
    private function dataForBillPayment(BillPayment $payment): array
    {
        $payment->loadMissing('contact', 'paidFromAccount', 'applications.bill');

        $amount = Money::fromCents((int) $payment->amount_cents);
        $totalDecimal = number_format($payment->amount_cents / 100, 2, '.', ',');

        return [
            'date_comb' => $payment->payment_date->format($this->dateCombFormat()),
            'date_slashed' => $payment->payment_date->format('n/j/Y'),
            'payee' => (string) $payment->contact->display_name,
            'amount_numeric' => '**'.$totalDecimal,
            'amount_words' => $this->amountInWords($amount),
            'total_numeric' => $totalDecimal,
            'memo' => (string) ($payment->memo ?? ''),
            'bank_account_name' => (string) ($payment->paidFromAccount?->name ?? ''),
            // Only the direct-cheque path prints an address today.
            'address_lines' => [],
            'lines' => $payment->applications->map(fn ($app) => [
                'account' => (string) (optional($app->bill)->bill_no ?? ''),
                'description' => (string) (optional($app->bill)->memo ?? ''),
                'amount' => number_format((int) $app->amount_cents / 100, 2, '.', ','),
            ])->all(),
        ];
    }

    /**
     * The order of the eight date-comb digits, as a PHP date format.
     */
    private function dateCombFormat(): string
    {
        return (string) config('cheque.date_comb_format', 'Ymd');
    }

    /**
     * The legend under the comb: one letter per digit, spaced as Intuit sets
     * it — "Ymd" reads "Y    Y    Y    Y    M    M    D    D".
     */
    private function dateCombLegend(): string
    {
        $letters = strtr($this->dateCombFormat(), ['Y' => 'YYYY', 'm' => 'MM', 'd' => 'DD']);

        return implode('    ', str_split($letters));
    }

    /**
     * The PAY line: a fixed run of stars, then the amount in words.
     */
    private function amountInWords(Money $amount): string
    {
        return str_repeat('*', (int) config('cheque.amount_words_star_prefix', 5)).$amount->toWords();
    }

    /**
     * How the voucher names an account: "200 · Customer Receivables". The code
     * alone is unreadable to whoever opens the envelope, and the name alone
     * doesn't tie back to the chart of accounts — QuickBooks prints both.
     */
    private function accountLabel(?Account $account): string
    {
        if ($account === null) {
            return '';
        }

        $code = trim((string) $account->code);
        $name = trim((string) $account->name);

        return match (true) {
            $code === '' => $name,
            $name === '' => $code,
            default => $code.' · '.$name,
        };
    }

    /**
     * @return array{date_comb: string, date_slashed: string, payee: string, amount_numeric: string, amount_words: string, total_numeric: string, memo: string, bank_account_name: string, address_lines: array<int, string>, lines: array<int, array{account: string, description: string, amount: string}>}
     */
    private function dataForCheque(Cheque $cheque): array
    {
        $cheque->loadMissing('bankAccount', 'payee', 'company', 'lines.account');

        $amount = Money::fromCents((int) $cheque->amount_cents);
        $totalDecimal = number_format($cheque->amount_cents / 100, 2, '.', ',');

        $payee = (string) ($cheque->payee_name ?: ($cheque->payee?->display_name ?? ''));

        return [
            'date_comb' => $cheque->cheque_date->format($this->dateCombFormat()),
            'date_slashed' => $cheque->cheque_date->format('n/j/Y'),
            'payee' => $payee,
            'amount_numeric' => '**'.$totalDecimal,
            'amount_words' => $this->amountInWords($amount),
            'total_numeric' => $totalDecimal,
            'memo' => (string) ($cheque->memo ?? ''),
            'bank_account_name' => (string) ($cheque->bankAccount?->name ?? ''),
            'address_lines' => $this->chequeAddressLines($cheque),
            'lines' => $cheque->lines->map(fn ($line) => [
                'account' => $this->accountLabel($line->account),
                'description' => (string) ($line->description ?? ''),
                'amount' => number_format(((int) $line->amount_cents + (int) $line->tax_cents) / 100, 2, '.', ','),
            ])->all(),
        ];
    }

    /**
     * @param  array{date_comb: string, date_slashed: string, payee: string, amount_numeric: string, amount_words: string, total_numeric: string, memo: string, bank_account_name: string, address_lines: array<int, string>, lines: array<int, array{account: string, description: string, amount: string}>}  $data
     */
    private function renderPdf(array $data, string $reference, ?Company $company = null): string
    {
        $cfg = config('cheque');
        $fields = $cfg['fields'];
        $columns = $cfg['columns'];
        // Per-company calibration overrides the global config drift when set, so
        // non-technical users can self-align to their printer/tray in Settings.
        $ox = $company?->cheque_offset_x !== null ? (float) $company->cheque_offset_x : (float) $cfg['offset_x'];
        $oy = $company?->cheque_offset_y !== null ? (float) $company->cheque_offset_y : (float) $cfg['offset_y'];
        $bodySize = (float) $cfg['fonts']['size_body'];
        $combSize = (float) $cfg['fonts']['size_date_comb'];
        $payeeSize = (float) $cfg['fonts']['size_payee'];
        $labelSize = (float) $cfg['fonts']['size_label'];
        $subscriptSize = (float) $cfg['fonts']['size_subscript'];
        $family = (string) $cfg['fonts']['family'];
        $drawLabels = (bool) ($cfg['draw_static_labels'] ?? true);

        $pdf = new ChequeDocument('P', 'pt', 'LETTER', true, 'UTF-8', false);
        $pdf->suppressProducerLink();
        $pdf->SetMargins(0, 0, 0);
        // TCPDF pads every cell by 1 mm and Text() draws through Cell(), so the
        // padding would shift each field right of the coordinate it was given.
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCellMargins(0, 0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('LineLedger');
        $pdf->SetAuthor('LineLedger');
        $pdf->SetTitle('Cheque '.$reference);
        $pdf->AddPage();

        /**
         * Place text on an exact baseline. TCPDF's Text() normally centres the
         * text in a notional cell, which drags the result around by whatever
         * the current font's ascent happens to be; the 'L'/'T' alignment pair
         * pins the glyph baseline to $baseline instead, so a coordinate from
         * the spec means the same thing at every font size.
         */
        $place = function (float $x, float $baseline, string $text, string $align = 'L', ?float $size = null) use ($pdf, $family, $bodySize, $ox, $oy) {
            if ($text === '') {
                return;
            }

            $pdf->SetFont($family, '', $size ?? $bodySize);
            $drawX = $x + $ox;

            if ($align === 'R') {
                $drawX -= $pdf->GetStringWidth($text);
            }

            $pdf->Text($drawX, $baseline + $oy, $text, 0, false, true, 0, 0, '', false, '', 0, false, 'L', 'T');
        };

        /**
         * Clip text to a column width. Intuit cuts mid-word with no ellipsis —
         * a stub column is a fixed box, and anything that overflows it would
         * print on top of the column to its right.
         */
        $fit = function (string $text, float $width, ?float $size = null) use ($pdf, $family, $bodySize): string {
            if ($text === '' || $width <= 0) {
                return $text;
            }

            $pdf->SetFont($family, '', $size ?? $bodySize);

            if ($pdf->GetStringWidth($text) <= $width) {
                return $text;
            }

            $length = mb_strlen($text);

            while ($length > 0 && $pdf->GetStringWidth(mb_substr($text, 0, $length)) > $width) {
                $length--;
            }

            return mb_substr($text, 0, $length);
        };

        // ---------- CHEQUE BAND ----------
        [$dx, $dy] = $fields['cheque_date_first_digit'];
        $pitch = (float) $cfg['date_digit_pitch'];

        if ($drawLabels) {
            // "DATE" label to the left of the digit block.
            $place($dx + (float) $cfg['date_label_offset'], $dy + (float) $cfg['date_label_drop'], 'DATE', 'L', $labelSize);
        }

        // The digits themselves.
        foreach (str_split($data['date_comb']) as $i => $digit) {
            $place($dx + $i * $pitch, $dy, $digit, 'L', $combSize);
        }

        if ($drawLabels) {
            // "Y Y Y Y M M D D" legend below the digit block.
            $place(
                $dx + (float) $cfg['date_subscript_x_offset'],
                $dy + (float) $cfg['date_subscript_drop'],
                $this->dateCombLegend(),
                'L',
                $subscriptSize,
            );
        }

        // PAY line: the amount in words, behind its star prefix.
        [$x, $y] = $fields['cheque_amount_words'];
        $place($x, $y, $data['amount_words']);

        // Numeric amount. The leading "**" tamper-fill makes the "$" prefix
        // redundant — QuickBooks omits the $ on its data line for the same reason.
        [$x, $y] = $fields['cheque_amount_numeric'];
        $place($x, $y, $data['amount_numeric']);

        // Payee, set a size down from the amount lines.
        [$x, $y] = $fields['cheque_payee'];
        $place($x, $y, $data['payee'], 'L', $payeeSize);

        // Address block under the payee, so the cheque can be window-enveloped.
        // Capped so a long address cannot run down into the MEMO line.
        if ($data['address_lines'] !== []) {
            [$ax, $ay] = $fields['cheque_payee_address'];
            $addressStep = (float) $cfg['address_line_height'];
            $addressMax = (int) $cfg['address_max_lines'];
            $addressSize = (float) $cfg['address_font_size'];

            foreach (array_slice($data['address_lines'], 0, $addressMax) as $i => $line) {
                $place($ax, $ay + $i * $addressStep, $line, 'L', $addressSize);
            }
        }

        // Memo — label and text both set in the small face.
        if ($drawLabels) {
            [$lx, $ly] = $fields['cheque_memo_label'];
            $place($lx, $ly, 'MEMO', 'L', $labelSize);
        }

        if ($data['memo'] !== '') {
            [$x, $y] = $fields['cheque_memo'];
            $place($x, $y, $data['memo'], 'L', $labelSize);
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
                $place($rowX, $cursorY + $bandOffset, $fit($line['account'], (float) $columns['detail_account_width']));
                $place($descX, $cursorY + $bandOffset, $fit($line['description'], (float) $columns['detail_desc_width']));
                $place($rightEdge, $cursorY + $bandOffset, $line['amount'], 'R');
                $cursorY += $lineHeight;
                $rendered++;
            }

            // Summary row content.
            [$x, $y] = $fields['voucher_summary_account'];
            $place($x, $y + $bandOffset, $fit($data['bank_account_name'], (float) $columns['summary_account_width']));

            [$descx, $descy] = $fields['voucher_summary_desc'];
            $place($descx, $descy + $bandOffset, $fit($data['memo'], (float) $columns['summary_desc_width']));
            $place($rightEdge, $descy + $bandOffset, $data['total_numeric'], 'R');
        }

        return $pdf->Output('cheque.pdf', 'S');
    }
}
