<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Tax\SaveTaxReturn;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\TaxCalculator;
use App\Services\Tax\TaxReturnFiler;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The tax return form's adjustments and reconciliation, and the page a saved
 * draft lands on. The ledger holds 5.00 of GST collected in August on top of
 * 18.90 left owing from July, so the return is 18.90 short until that is
 * carried onto it.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->payable = $this->gst->agency->payableAccount;
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->otherIncome = Account::query()->where('subtype', AccountSubtype::OtherIncome->value)->orderBy('code')->first() ?? $this->income;

    // 18.90 still owing from July.
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-07-31',
        'lines' => [
            ['account_id' => $this->income->id, 'debit_cents' => 1890, 'credit_cents' => 0],
            ['account_id' => $this->payable->id, 'debit_cents' => 0, 'credit_cents' => 1890],
        ],
    ]);
    app(JournalPoster::class)->post($entry);

    // 5.00 of GST collected in August.
    $invoice = Invoice::create([
        'contact_id' => Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true])->id,
        'invoice_no' => 'INV-TRF-1',
        'invoice_date' => '2026-08-10',
        'due_date' => '2026-08-10',
    ]);
    $totals = app(TaxCalculator::class)->line('1', 10000, $this->gst);
    $invoice->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $this->gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function trfForm(): Testable
{
    return Livewire::test('pages::tax-returns.form', ['company' => test()->company])
        ->set('tax_agency_id', test()->gst->agency_id)
        ->set('tax_return_no', 'TR-TRF')
        ->set('period_start', '2026-08-01')
        ->set('period_end', '2026-08-31');
}

it('shows the difference from the ledger and closes it with a no-ledger adjustment', function () {
    $form = trfForm();

    expect($form->instance()->preview->netCents)->toBe(500)
        ->and($form->instance()->preview->differenceCents())->toBe(1890);

    $form->assertSeeHtml('data-test="difference-warning"');

    $form->call('addAdjustment')
        ->assertSet('adjustments.0.account_id', $this->payable->id)
        ->set('adjustments.0.amount', '18.90')
        ->set('adjustments.0.memo', 'July underpayment');

    expect($form->instance()->preview->otherAdjustmentsCents)->toBe(1890)
        ->and($form->instance()->preview->netCents)->toBe(2390)
        ->and($form->instance()->preview->differenceCents())->toBe(0);

    $form->assertDontSeeHtml('data-test="difference-warning"');

    $form->call('removeAdjustment', 0);

    expect($form->instance()->preview->differenceCents())->toBe(1890);
});

it('saves the draft with its adjustments and lands on a page showing its figures', function () {
    trfForm()
        ->call('addAdjustment')
        ->set('adjustments.0.amount', '18.90')
        ->set('adjustments.0.memo', 'July underpayment')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $return = TaxReturn::where('tax_return_no', 'TR-TRF')->firstOrFail();

    expect($return->status)->toBe(TaxReturnStatus::Draft)
        ->and($return->collected_cents)->toBe(500)
        ->and($return->net_cents)->toBe(2390)
        ->and($return->adjustments)->toHaveCount(1)
        ->and($return->adjustments->first()->memo)->toBe('July underpayment');

    // The reported bug: a saved draft's page read 0.00 with "No snapshot lines yet".
    $this->get(route('tax-returns.show', ['company' => $this->company->slug, 'tax_return' => $return->id]))
        ->assertOk()
        ->assertDontSee('No snapshot lines yet')
        ->assertSee('data-test="draft-live-note"', false)
        ->assertSee('INV-TRF-1')
        ->assertSee('July underpayment')
        ->assertSeeInOrder(['data-test="tax-return-net"', '23.90'], false)
        ->assertSeeInOrder(['data-test="reconciliation-difference"', '0.00'], false);
});

it('files only after the difference is accepted, and withdraws acceptance when the figures change', function () {
    $form = trfForm();

    $form->call('fileReturn');
    expect(TaxReturn::where('tax_return_no', 'TR-TRF')->firstOrFail()->status)->toBe(TaxReturnStatus::Draft);

    $form->set('acceptDifference', true)
        ->assertSet('acceptedDifferenceCents', 1890);

    // Any change to the return withdraws the acceptance.
    $form->set('notes', 'Changed my mind')
        ->assertSet('acceptDifference', false)
        ->assertSet('acceptedDifferenceCents', null);

    $form->set('acceptDifference', true)->call('fileReturn');

    $return = TaxReturn::where('tax_return_no', 'TR-TRF')->firstOrFail();

    expect($return->status)->toBe(TaxReturnStatus::Filed)
        ->and($return->reconciliation['accepted_difference_cents'])->toBe(1890);
});

it('rejects an adjustment without an account or with a zero amount', function () {
    trfForm()
        ->call('addAdjustment')
        ->set('adjustments.0.account_id', null)
        ->set('adjustments.0.amount', '0.00')
        ->call('saveDraft')
        ->assertHasErrors(['adjustments.0.account_id', 'adjustments.0.amount']);
});

it('posts a commission coded to an income account when the return is filed from the form', function () {
    trfForm()
        ->call('addAdjustment')
        ->set('adjustments.0.amount', '18.90')
        ->call('addAdjustment')
        ->set('adjustments.1.account_id', $this->otherIncome->id)
        ->set('adjustments.1.amount', '-1.00')
        ->set('adjustments.1.memo', 'Collector’s commission')
        ->call('fileReturn')
        ->assertHasNoErrors();

    $return = TaxReturn::where('tax_return_no', 'TR-TRF')->firstOrFail();

    expect($return->status)->toBe(TaxReturnStatus::Filed)
        ->and($return->net_cents)->toBe(2290)
        ->and($return->reconciliation['difference_cents'])->toBe(0)
        ->and((int) $return->adjustmentJournalEntry->lines->firstWhere('account_id', $this->payable->id)->debit_cents)->toBe(100);

    $this->get(route('tax-returns.show', ['company' => $this->company->slug, 'tax_return' => $return->id]))
        ->assertOk()
        ->assertSee('data-test="adjustment-entry-link"', false)
        ->assertSee('data-test="tax-return-reconciliation"', false)
        ->assertDontSee('data-test="draft-live-note"', false);
});

it('refuses to file from the return page while the return differs from the ledger', function () {
    $return = app(SaveTaxReturn::class)->handle([
        'tax_agency_id' => $this->gst->agency_id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ]);

    Livewire::test('pages::tax-returns.show', ['company' => $this->company, 'tax_return' => $return])
        ->call('file');

    expect($return->fresh()->status)->toBe(TaxReturnStatus::Draft);
});

it('renders a return filed before reconciliations were kept', function () {
    $return = app(SaveTaxReturn::class)->handle([
        'tax_agency_id' => $this->gst->agency_id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'adjustments' => [['kind' => 'other', 'account_id' => $this->payable->id, 'amount_cents' => 1890]],
    ]);
    $filed = app(TaxReturnFiler::class)->file($return);
    $filed->forceFill(['reconciliation' => null])->save();

    $this->get(route('tax-returns.show', ['company' => $this->company->slug, 'tax_return' => $filed->id]))
        ->assertOk()
        ->assertDontSee('data-test="tax-return-reconciliation"', false)
        ->assertSee('INV-TRF-1');
});
