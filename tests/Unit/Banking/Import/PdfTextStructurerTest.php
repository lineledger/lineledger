<?php

use App\Services\Banking\Import\Support\PdfTextStructurer;

function structure(string $text): array
{
    return app(PdfTextStructurer::class)->structure($text);
}

it('structures a typical layout-extracted statement into signed transactions', function () {
    $text = <<<'TXT'
        ACME BANK — Chequing Statement
        Period: 2026-01-01 to 2026-01-31

        Date        Description              Amount      Balance
        2026-01-03  COFFEE SHOP              -4.50       995.50
        2026-01-05  PAYROLL DEPOSIT          2,000.00    2,995.50
        2026-01-06  HYDRO BILL               -120.00     2,875.50

        Closing Balance                                  2,875.50
        TXT;

    $result = structure($text);

    expect($result['transactions'])->toHaveCount(3)
        ->and($result['transactions'][0]->description)->toBe('COFFEE SHOP')
        ->and($result['transactions'][0]->amountCents)->toBe(-450)
        ->and($result['transactions'][0]->balanceCents)->toBe(99550)
        ->and($result['transactions'][1]->amountCents)->toBe(200000)
        ->and($result['transactions'][2]->amountCents)->toBe(-12000)
        ->and($result['beginDate']->toDateString())->toBe('2026-01-03')
        ->and($result['endDate']->toDateString())->toBe('2026-01-06')
        ->and($result['endBalanceCents'])->toBe(287550);
});

it('handles day-first dates and parenthesised negatives', function () {
    $text = <<<'TXT'
        13/01/2026   RENT PAYMENT        (1,500.00)
        15/01/2026   CLIENT INVOICE      3,200.00
        TXT;

    $result = structure($text);

    expect($result['transactions'])->toHaveCount(2)
        ->and($result['transactions'][0]->date->toDateString())->toBe('2026-01-13')
        ->and($result['transactions'][0]->amountCents)->toBe(-150000)
        ->and($result['transactions'][1]->amountCents)->toBe(320000);
});

it('signs unsigned columnar amounts from the running-balance delta (BMO-style)', function () {
    // No year on the rows, separate debit/credit columns with UNSIGNED amounts, an
    // embedded reference decimal ("140610 21.25"), and a closing-totals summary row.
    $text = <<<'TXT'
        For the period ending March 31, 2026
                                                       Amounts debited     Amounts credited
        Date     Description                       from your account ($)  to your account ($)   Balance ($)
        Feb 28   Opening balance                                                                1,000.00
        Mar 09   INTERAC e-Transfer Sent, 140610 21.25      446.25                              553.75
        Mar 20   Payroll Deposit                                          2,000.00              2,553.75
        Mar 31   Plan Fee                                       6.00                            2,547.75
        Mar 31   Closing totals                             452.25        2,000.00
        TXT;

    $result = structure($text);

    expect($result['transactions'])->toHaveCount(3)
        ->and($result['transactions'][0]->date->toDateString())->toBe('2026-03-09')
        ->and($result['transactions'][0]->amountCents)->toBe(-44625)          // the withdrawal, not the embedded 21.25
        ->and($result['transactions'][0]->description)->toBe('INTERAC e-Transfer Sent, 140610')
        ->and($result['transactions'][1]->amountCents)->toBe(200000)          // a deposit, signed positive
        ->and($result['transactions'][2]->amountCents)->toBe(-600)
        ->and($result['endBalanceCents'])->toBe(254775);                      // last running balance, not the totals row
});

it('ignores lines without a leading date', function () {
    $text = <<<'TXT'
        Opening Balance              1,000.00
        2026-02-01  RENT  -1,500.00
        Page 1 of 2
        TXT;

    $result = structure($text);

    expect($result['transactions'])->toHaveCount(1)
        ->and($result['transactions'][0]->amountCents)->toBe(-150000);
});
