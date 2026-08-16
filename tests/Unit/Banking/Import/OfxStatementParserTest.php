<?php

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\DTO\ParseOptions;
use App\Services\Banking\Import\Parsers\OfxStatementParser;

function writeOfx(string $content): string
{
    $path = sys_get_temp_dir().'/'.uniqid('stmt_', true).'.ofx';
    file_put_contents($path, $content);

    return $path;
}

/** A realistic OFX 1.02 SGML body, with unclosed value tags. */
function sampleOfx(): string
{
    return <<<'OFX'
        OFXHEADER:100
        DATA:OFXSGML
        VERSION:102
        SECURITY:NONE
        ENCODING:USASCII

        <OFX>
        <BANKMSGSRSV1><STMTTRNRS><TRNUID>1<STATUS><CODE>0<SEVERITY>INFO</STATUS>
        <STMTRS><CURDEF>CAD
        <BANKACCTFROM><BANKID>900<ACCTID>123456789<ACCTTYPE>CHECKING</BANKACCTFROM>
        <BANKTRANLIST><DTSTART>20260101<DTEND>20260131
        <STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260105120000<TRNAMT>2000.00<FITID>FIT-1<NAME>PAYROLL DEPOSIT</STMTTRN>
        <STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260106<TRNAMT>-120.00<FITID>FIT-2<NAME>HYDRO<MEMO>BILL PYMT</STMTTRN>
        <STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260107<TRNAMT>-4.50<FITID>FIT-3<NAME>COFFEE</STMTTRN>
        </BANKTRANLIST>
        <LEDGERBAL><BALAMT>1875.50<DTASOF>20260131</LEDGERBAL>
        </STMTRS></STMTTRNRS></BANKMSGSRSV1>
        </OFX>
        OFX;
}

it('parses OFX transactions with signed amounts, FITIDs and descriptions', function () {
    $parser = app(OfxStatementParser::class);
    $stmt = $parser->parse(writeOfx(sampleOfx()), BankStatementFormat::Ofx, new ParseOptions);

    expect($stmt->count())->toBe(3)
        ->and($stmt->transactions[0]->amountCents)->toBe(200000)
        ->and($stmt->transactions[0]->externalId)->toBe('FIT-1')
        ->and($stmt->transactions[0]->date->toDateString())->toBe('2026-01-05')
        ->and($stmt->transactions[0]->description)->toBe('PAYROLL DEPOSIT')
        ->and($stmt->transactions[1]->amountCents)->toBe(-12000)
        ->and($stmt->transactions[1]->description)->toBe('HYDRO BILL PYMT')
        ->and($stmt->transactions[2]->amountCents)->toBe(-450);
});

it('reads the statement period and closing ledger balance', function () {
    $parser = app(OfxStatementParser::class);
    $stmt = $parser->parse(writeOfx(sampleOfx()), BankStatementFormat::Ofx, new ParseOptions);

    expect($stmt->beginDate->toDateString())->toBe('2026-01-01')
        ->and($stmt->endDate->toDateString())->toBe('2026-01-31')
        ->and($stmt->endBalanceCents)->toBe(187550)
        ->and($stmt->currency)->toBe('CAD');
});
