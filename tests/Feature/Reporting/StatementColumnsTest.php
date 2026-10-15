<?php

use App\Enums\CustomerStatementType;
use App\Support\Reporting\StatementColumns;

it('defaults to the original statement layout for both types', function () {
    expect(StatementColumns::visible(CustomerStatementType::OpenInvoices, null, null))
        ->toBe(['invoice_date', 'invoice_no', 'memo', 'due_date', 'original', 'balance'])
        ->and(StatementColumns::visible(CustomerStatementType::Activity, null, null))
        ->toBe(['date', 'type', 'doc_no', 'memo', 'charges', 'payments', 'running']);

    // The original fixed widths, memo absorbing the rest.
    expect(StatementColumns::widths(CustomerStatementType::OpenInvoices, StatementColumns::visible(CustomerStatementType::OpenInvoices, null, null)))
        ->toBe(['invoice_date' => '11%', 'invoice_no' => '15%', 'memo' => null, 'due_date' => '11%', 'original' => '17%', 'balance' => '14%']);
});

it('offers the P.O. on both types and never a sales rep', function () {
    $open = StatementColumns::optional(CustomerStatementType::OpenInvoices);
    $activity = StatementColumns::optional(CustomerStatementType::Activity);

    expect(array_keys($open))->toBe(['po', 'memo', 'terms', 'due_date', 'days_past_due', 'original', 'paid'])
        ->and(array_keys($activity))->toBe(['type', 'po', 'memo'])
        ->and($open['po'])->toBe('P.O. #')
        ->and(array_merge(array_keys($open), array_keys($activity)))->not->toContain('sales_rep')->not->toContain('rep');
});

it('prefers the requested columns, then the saved default, then the built-in default', function () {
    $type = CustomerStatementType::OpenInvoices;

    expect(StatementColumns::chosen($type, ['paid', 'po', 'balance', 'bogus'], ['terms']))->toBe(['po', 'paid'])
        ->and(StatementColumns::chosen($type, [], ['terms']))->toBe([])
        ->and(StatementColumns::chosen($type, null, ['terms', 'memo']))->toBe(['memo', 'terms'])
        ->and(StatementColumns::chosen($type, null, null))->toBe(['memo', 'due_date', 'original'])
        // Fixed columns always render, in display order, whatever is chosen.
        ->and(StatementColumns::visible($type, ['po'], null))->toBe(['invoice_date', 'invoice_no', 'po', 'balance'])
        // A key from the other type is dropped.
        ->and(StatementColumns::sanitize(CustomerStatementType::Activity, ['type', 'terms', 'po']))->toBe(['type', 'po']);
});

it('round-trips the cols query value, with none for an empty selection', function () {
    expect(StatementColumns::parse(null))->toBeNull()
        ->and(StatementColumns::parse(''))->toBeNull()
        ->and(StatementColumns::parse(['po']))->toBeNull()
        ->and(StatementColumns::parse('none'))->toBe([])
        ->and(StatementColumns::parse(' po, memo ,,'))->toBe(['po', 'memo'])
        ->and(StatementColumns::encode([]))->toBe('none')
        ->and(StatementColumns::encode(['po', 'memo']))->toBe('po,memo');
});

it('formats every cell the same way for the preview and the PDF', function () {
    $row = [
        'invoice_date' => '2026-03-01',
        'due_date' => null,
        'invoice_no' => 'INV-1',
        'customer_po' => 'PO-9',
        'total' => 123456,
        'paid' => 0,
        'days_past_due' => 0,
        'balance' => -500,
    ];

    expect(StatementColumns::cell('invoice_date', $row))->toBe('3/1/2026')
        ->and(StatementColumns::cell('due_date', $row))->toBe('—')
        ->and(StatementColumns::cell('po', $row))->toBe('PO-9')
        ->and(StatementColumns::cell('original', $row))->toBe('1,234.56')
        ->and(StatementColumns::cell('paid', $row))->toBe('0.00')
        ->and(StatementColumns::cell('balance', $row))->toBe('-5.00')
        ->and(StatementColumns::cell('days_past_due', $row))->toBe('')
        ->and(StatementColumns::cell('days_past_due', ['days_past_due' => 12]))->toBe('12')
        ->and(StatementColumns::cell('charges', ['debit' => 0]))->toBe('')
        ->and(StatementColumns::cell('payments', ['credit' => 2500]))->toBe('25.00')
        ->and(StatementColumns::cell('memo', []))->toBe('')
        ->and(StatementColumns::isNumeric('days_past_due'))->toBeTrue()
        ->and(StatementColumns::isNumeric('po'))->toBeFalse();
});
