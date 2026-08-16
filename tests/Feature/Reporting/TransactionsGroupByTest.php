<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/**
 * One invoice (posted via InvoicePoster, dated April) and one manual journal
 * entry (posted via JournalPoster, dated May), so the report has two source
 * types and two months to group by.
 *
 * @return array{invoice: Invoice, entry: JournalEntry}
 */
function transactionsGroupScenario(): array
{
    $customer = Contact::create(['display_name' => 'Group Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-GRP-1',
        'invoice_date' => '2026-04-10',
        'due_date' => '2026-05-10',
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $entry = JournalEntry::create([
        'entry_no' => 'JE-GRP-1',
        'entry_date' => '2026-05-05',
        'memo' => 'Manual entry',
    ]);
    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 2500, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 2500, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    return ['invoice' => $invoice, 'entry' => $entry];
}

function transactionsComponent(Company $company): Testable
{
    return Livewire::test('pages::reports.transactions', ['company' => $company])
        ->set('startDate', '2026-01-01')
        ->set('endDate', '2026-12-31');
}

it('groups by account in account-code order with full subtotal rows', function () {
    transactionsGroupScenario();

    $component = transactionsComponent($this->company)->set('groupBy', 'account');

    $component->assertSeeHtml('data-test="txn-group-header"')
        ->assertSeeHtml('data-test="txn-group-subtotal"');

    $codes = collect($component->instance()->lines->items())->map(fn (JournalLine $line) => $line->account->code);
    expect($codes->values()->all())->toBe($codes->sort()->values()->all());

    // Subtotals reflect the full per-account sums from the aggregate query.
    $totals = $component->instance()->groupTotals;
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    expect($totals[(string) $income->id])->toBe(['debit' => 0, 'credit' => 12500, 'count' => 2]);
    $component->assertSee('(2 lines)')->assertSee('125.00');
});

it('groups by month using the Y-m entry-date prefix', function () {
    transactionsGroupScenario();

    $component = transactionsComponent($this->company)->set('groupBy', 'month');

    $totals = $component->instance()->groupTotals;

    expect(array_keys($totals))->toContain('2026-04', '2026-05')
        ->and($totals['2026-05']['debit'])->toBe(2500)
        ->and($totals['2026-05']['credit'])->toBe(2500)
        ->and($totals['2026-05']['count'])->toBe(2);

    $component->assertSeeHtml('data-test="txn-group-header"')->assertSee('2026-05');
});

it('filters to invoice-sourced lines with ?source=Invoice', function () {
    transactionsGroupScenario();

    $component = transactionsComponent($this->company)->set('sourceType', 'Invoice');

    $lines = collect($component->instance()->lines->items());

    expect($lines)->not->toBeEmpty()
        ->and($lines->every(fn (JournalLine $line) => $line->journalEntry->source_type === Invoice::class))->toBeTrue();

    $component->assertDontSee('JE-GRP-1');
});

it('filters to manual journal entries with ?source=journal', function () {
    transactionsGroupScenario();

    $component = transactionsComponent($this->company)->set('sourceType', 'journal');

    $lines = collect($component->instance()->lines->items());

    expect($lines)->toHaveCount(2)
        ->and($lines->every(fn (JournalLine $line) => $line->journalEntry->source_type === null))->toBeTrue();

    $component->assertSee('JE-GRP-1');
});

it('shows the full group total on every page when a group spans pages', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $entry = JournalEntry::create(['entry_no' => 'JE-BIG', 'entry_date' => '2026-03-15', 'is_posted' => true]);

    foreach (range(1, 60) as $i) {
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $income->id,
            'debit_cents' => 100,
            'credit_cents' => 0,
            'entry_date' => '2026-03-15',
            'is_posted' => true,
            'line_order' => $i,
        ]);
    }

    $component = transactionsComponent($this->company)->set('groupBy', 'account');

    // Page 1 holds 50 of the 60 lines, yet the subtotal shows the true total.
    expect($component->instance()->lines->total())->toBe(60);
    $component->assertSee('(60 lines)')->assertSee('60.00');

    $component->call('gotoPage', 2)->assertSee('(60 lines)')->assertSee('60.00');
});

it('adds a leading Group column to the CSV export when grouped', function () {
    transactionsGroupScenario();

    $component = transactionsComponent($this->company)->set('groupBy', 'source');

    $response = $component->instance()->exportCsv();
    expect($response)->toBeInstanceOf(StreamedResponse::class);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Group,Date,"Entry #",Account,Name,Memo,Debit,Credit')
        ->and($csv)->toContain('Invoice')
        ->and($csv)->toContain('Journal entry');
});

it('exports grouped XLSX without error', function () {
    transactionsGroupScenario();

    $response = transactionsComponent($this->company)
        ->set('groupBy', 'account')
        ->instance()
        ->exportXlsx();

    expect($response)->toBeInstanceOf(BinaryFileResponse::class);
});

it('keeps the CSV export ungrouped when no grouping is active', function () {
    transactionsGroupScenario();

    $response = transactionsComponent($this->company)->instance()->exportCsv();

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('Date,"Entry #",Account,Name,Memo,Debit,Credit')
        ->and($csv)->not->toContain('Group,Date');
});

it('falls back to no grouping for an unknown ?group value', function () {
    Livewire::withQueryParams(['group' => 'bogus'])
        ->test('pages::reports.transactions', ['company' => $this->company])
        ->assertSet('groupBy', 'none');
});

it('falls back to all types for an unknown ?source value', function () {
    Livewire::withQueryParams(['source' => 'NotAModel'])
        ->test('pages::reports.transactions', ['company' => $this->company])
        ->assertSet('sourceType', '');
});
