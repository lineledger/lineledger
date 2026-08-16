<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MemorizedReport;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use App\Services\Reporting\OpenDocumentAgingBuilder;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->asOf = CarbonImmutable::now()->startOfDay();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function agingDetailInvoice(Contact $customer, string $no, CarbonImmutable $date, CarbonImmutable $due, int $cents): Invoice
{
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $no,
        'invoice_date' => $date,
        'due_date' => $due,
    ]);

    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

it('buckets every open invoice individually with subtotals', function () {
    $customer = Contact::create(['display_name' => 'Bucket Co', 'is_customer' => true]);

    // Due in +5 days → Current; overdue 10 → 1–30; 45 → 31–60; 75 → 61–90; 120 → 90+.
    agingDetailInvoice($customer, 'INV-CUR', $this->asOf->subDays(10), $this->asOf->addDays(5), 10000);
    agingDetailInvoice($customer, 'INV-10', $this->asOf->subDays(40), $this->asOf->subDays(10), 20000);
    agingDetailInvoice($customer, 'INV-45', $this->asOf->subDays(80), $this->asOf->subDays(45), 30000);
    agingDetailInvoice($customer, 'INV-75', $this->asOf->subDays(120), $this->asOf->subDays(75), 40000);
    agingDetailInvoice($customer, 'INV-120', $this->asOf->subDays(160), $this->asOf->subDays(120), 50000);

    $component = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('asOf', $this->asOf->toDateString())
        ->set('view', 'detail');

    $report = $component->instance()->detailReport;

    expect(array_column($report['buckets']['current']['rows'], 'doc_no'))->toBe(['INV-CUR'])
        ->and(array_column($report['buckets']['b1_30']['rows'], 'doc_no'))->toBe(['INV-10'])
        ->and(array_column($report['buckets']['b31_60']['rows'], 'doc_no'))->toBe(['INV-45'])
        ->and(array_column($report['buckets']['b61_90']['rows'], 'doc_no'))->toBe(['INV-75'])
        ->and(array_column($report['buckets']['b90_plus']['rows'], 'doc_no'))->toBe(['INV-120']);

    expect($report['buckets']['current']['subtotal'])->toBe(10000)
        ->and($report['buckets']['b90_plus']['subtotal'])->toBe(50000)
        ->and($report['grand_total'])->toBe(150000);

    $row = $report['buckets']['b1_30']['rows'][0];
    expect($row['days_overdue'])->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(11);

    $component->assertSee('INV-CUR')->assertSee('INV-120');
});

it('ties the detail grand total to the summary total and the AR control balance', function () {
    $customer = Contact::create(['display_name' => 'Tieout Inc', 'is_customer' => true]);
    agingDetailInvoice($customer, 'INV-TIE', $this->asOf->subDays(20), $this->asOf->subDays(5), 25000);

    // An unapplied receipt: reduces the customer's GL AR without touching the invoice.
    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'RCPT-ON-ACCT',
        'receipt_date' => $this->asOf->subDays(3),
        'amount_cents' => 4000,
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
    ]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    // An orphan journal entry on the AR control with no contact → unattributed.
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $entry = JournalEntry::create([
        'entry_no' => 'JE-ORPHAN',
        'entry_date' => $this->asOf->subDays(2),
        'memo' => 'orphan AR',
    ]);
    $entry->lines()->create(['account_id' => $ar->id, 'debit_cents' => 7777, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 7777, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    $component = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('asOf', $this->asOf->toDateString())
        ->set('excludeUnappliedCredits', false);

    $instance = $component->instance();
    $summaryTotal = $instance->report['totals']['total'];
    $detail = $instance->detailReport;

    $controlBalance = app(OpenDocumentAgingBuilder::class)
        ->controlBalance($this->company, 'ar', $this->asOf);

    // 25000 invoice − 4000 unapplied receipt + 7777 orphan = control balance.
    expect($controlBalance)->toBe(25000 - 4000 + 7777)
        ->and($summaryTotal)->toBe($controlBalance)
        ->and($detail['grand_total'])->toBe($controlBalance);

    // The unapplied receipt shows as the customer's adjustment; the orphan as unattributed.
    $amounts = collect($detail['adjustments'])->pluck('amount', 'contact_id');
    expect($amounts[$customer->id])->toBe(-4000)
        ->and($amounts[0])->toBe(7777);
});

it('keeps summary and detail grand totals equal under the owing-only toggle', function () {
    $owing = Contact::create(['display_name' => 'Owing Co', 'is_customer' => true]);
    agingDetailInvoice($owing, 'INV-OWE', $this->asOf->subDays(10), $this->asOf->addDays(10), 12000);

    // A net-credit customer: only an unapplied receipt, no open invoice.
    $creditor = Contact::create(['display_name' => 'Credit Co', 'is_customer' => true]);
    $receipt = CustomerReceipt::create([
        'contact_id' => $creditor->id,
        'receipt_no' => 'RCPT-CREDIT',
        'receipt_date' => $this->asOf->subDays(1),
        'amount_cents' => 5000,
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
    ]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    foreach ([true, false] as $owingOnly) {
        $component = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
            ->set('asOf', $this->asOf->toDateString())
            ->set('excludeUnappliedCredits', $owingOnly);

        $instance = $component->instance();

        expect($instance->detailReport['grand_total'])
            ->toBe($instance->report['totals']['total'], 'owingOnly='.var_export($owingOnly, true));
    }

    // With owing-only on, the credit customer's adjustment row is hidden in detail.
    $component = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('asOf', $this->asOf->toDateString())
        ->set('excludeUnappliedCredits', true)
        ->set('view', 'detail');

    expect(collect($component->instance()->detailReport['adjustments'])->pluck('contact_id'))
        ->not->toContain($creditor->id);
});

it('falls back to summary for an invalid view and resets sort on toggle', function () {
    $component = Livewire::withQueryParams(['view' => 'bogus'])
        ->test('pages::reports.ar-aging', ['company' => $this->company]);

    $component->assertSet('view', 'summary');

    $component->set('view', 'detail')
        ->assertSet('sortField', 'due_date')
        ->assertSet('sortDir', 'asc');

    $component->set('view', 'summary')
        ->assertSet('sortField', 'name');
});

it('exports the detail view as xlsx and pdf', function () {
    $customer = Contact::create(['display_name' => 'Export Co', 'is_customer' => true]);
    agingDetailInvoice($customer, 'INV-EXP', $this->asOf->subDays(10), $this->asOf->subDays(5), 9000);

    foreach (['ar-aging' => 'pages::reports.ar-aging', 'ap-aging' => 'pages::reports.ap-aging'] as $slug => $page) {
        foreach (['exportXlsx' => '.xlsx', 'exportPdf' => '.pdf'] as $method => $ext) {
            $component = Livewire::test($page, ['company' => $this->company])
                ->set('asOf', $this->asOf->toDateString())
                ->set('view', 'detail')
                ->call($method);

            expect(data_get($component->effects, 'download.name'))
                ->toBe("{$slug}-detail-{$this->asOf->toDateString()}{$ext}");
        }
    }
});

it('memorizes and restores the detail view', function () {
    Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('view', 'detail')
        ->set('memorizeName', 'AR Detail')
        ->call('memorizeReport')
        ->assertHasNoErrors();

    $memorized = MemorizedReport::query()->where('user_id', $this->user->id)->first();

    expect($memorized->settings['view'])->toBe('detail');

    Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->call('applyMemorized', $memorized->id)
        ->assertSet('view', 'detail');
});
