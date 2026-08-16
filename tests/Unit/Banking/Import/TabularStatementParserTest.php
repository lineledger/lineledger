<?php

use App\Enums\BankStatementFormat;
use App\Services\Banking\Import\DTO\ParsedStatement;
use App\Services\Banking\Import\DTO\ParseOptions;
use App\Services\Banking\Import\Parsers\TabularStatementParser;

function writeStatement(string $content): string
{
    $path = sys_get_temp_dir().'/'.uniqid('stmt_', true).'.csv';
    file_put_contents($path, $content);

    return $path;
}

function parseStatement(string $content): ParsedStatement
{
    $parser = app(TabularStatementParser::class);
    $path = writeStatement($content);
    $probe = $parser->sniff($path, BankStatementFormat::Csv);

    return $parser->parse($path, BankStatementFormat::Csv, new ParseOptions(mapping: $probe->detectedMapping));
}

it('parses a single signed-amount statement into signed book deltas', function () {
    $stmt = parseStatement(<<<'CSV'
    Date,Description,Amount,Balance
    2026-01-03,COFFEE SHOP,-4.50,995.50
    2026-01-05,PAYROLL DEPOSIT,"2,000.00",2995.50
    2026-01-06,HYDRO BILL,-120.00,2875.50
    CSV);

    expect($stmt->count())->toBe(3)
        ->and($stmt->transactions[0]->amountCents)->toBe(-450)
        ->and($stmt->transactions[1]->amountCents)->toBe(200000)
        ->and($stmt->transactions[2]->amountCents)->toBe(-12000)
        ->and($stmt->beginDate->toDateString())->toBe('2026-01-03')
        ->and($stmt->endDate->toDateString())->toBe('2026-01-06')
        ->and($stmt->endBalanceCents)->toBe(287550);
});

it('parses separate withdrawal/deposit columns (deposits positive)', function () {
    $stmt = parseStatement(<<<'CSV'
    Transaction Date,Details,Withdrawals,Deposits
    01/03/2026,Coffee Shop,4.50,
    01/05/2026,Payroll Deposit,,2000.00
    01/06/2026,Hydro Bill,120.00,
    CSV);

    expect($stmt->count())->toBe(3)
        ->and($stmt->transactions[0]->amountCents)->toBe(-450)
        ->and($stmt->transactions[1]->amountCents)->toBe(200000)
        ->and($stmt->transactions[2]->amountCents)->toBe(-12000)
        ->and($stmt->meta['amount_mode'])->toBe('debit_credit');
});

it('skips header preamble and footer/summary junk rows', function () {
    $stmt = parseStatement(<<<'CSV'
    Date,Description,Amount
    not a date,opening balance,
    2026-02-01,Rent,-1500.00
    Total,,−1500.00
    CSV);

    expect($stmt->count())->toBe(1)
        ->and($stmt->transactions[0]->description)->toBe('Rent')
        ->and($stmt->transactions[0]->amountCents)->toBe(-150000)
        ->and($stmt->meta['rows_skipped'])->toBe(2);
});

it('flips the sign when a running balance proves the amount column is positive-for-withdrawals', function () {
    // Amount is positive for money OUT here; the falling balance proves it.
    $stmt = parseStatement(<<<'CSV'
    Date,Description,Amount,Balance
    2026-03-01,Groceries,50.00,950.00
    2026-03-02,Fuel,40.00,910.00
    2026-03-03,Refund,-25.00,935.00
    CSV);

    expect($stmt->meta['flip_sign'])->toBeTrue()
        ->and($stmt->transactions[0]->amountCents)->toBe(-5000)
        ->and($stmt->transactions[2]->amountCents)->toBe(2500);
});
