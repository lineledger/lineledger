<?php

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\DTO\ParseOptions;
use App\Services\Banking\Import\Parsers\PdfStatementParser;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

// Boot the full app (config + the dompdf facade) but skip the database — these
// tests only exercise PDF generation, extraction, and structuring.
uses(TestCase::class);

function statementPdf(string $body): string
{
    $path = sys_get_temp_dir().'/'.uniqid('stmt_', true).'.pdf';
    file_put_contents($path, Pdf::loadHTML('<html><body><pre>'.$body.'</pre></body></html>')->output());

    return $path;
}

it('parses a text-based statement PDF into transactions', function () {
    $body = "2026-01-03  COFFEE SHOP     -4.50      995.50\n"
        ."2026-01-05  PAYROLL         2,000.00   2,995.50\n"
        ."2026-01-06  HYDRO          -120.00     2,875.50\n";

    $path = statementPdf($body);

    $stmt = app(PdfStatementParser::class)->parse($path, BankStatementFormat::Pdf, new ParseOptions);

    expect($stmt->count())->toBe(3)
        ->and($stmt->transactions[0]->amountCents)->toBe(-450)
        ->and($stmt->transactions[1]->amountCents)->toBe(200000)
        ->and($stmt->transactions[2]->amountCents)->toBe(-12000);

    @unlink($path);
});

it('rejects a PDF with no readable text layer (scanned image)', function () {
    $path = sys_get_temp_dir().'/'.uniqid('blank_', true).'.pdf';
    file_put_contents($path, Pdf::loadHTML('<html><body>&nbsp;</body></html>')->output());

    expect(fn () => app(PdfStatementParser::class)->parse($path, BankStatementFormat::Pdf, new ParseOptions))
        ->toThrow(RuntimeException::class);

    @unlink($path);
});
