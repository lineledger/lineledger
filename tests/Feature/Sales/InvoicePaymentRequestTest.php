<?php

use App\Actions\Sales\SaveInvoicePaymentRequests;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentRequestStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Sales\PaymentRequestScheduleStatus;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function invoiceWithTotal(int $cents): Invoice
{
    $invoice = Invoice::create([
        'company_id' => test()->company->id,
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.str()->random(5),
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'status' => InvoiceStatus::Draft->value,
        'total_cents' => $cents,
    ]);
    $invoice->lines()->create(['account_id' => test()->income->id, 'description' => 'x', 'quantity' => '1', 'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents, 'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0]);

    return $invoice;
}

it('resolves percentage and fixed milestones to cents', function () {
    $invoice = invoiceWithTotal(10000);

    app(SaveInvoicePaymentRequests::class)->handle($invoice, [
        ['label' => 'Deposit', 'type' => 'percent', 'percent' => 30, 'due_date' => null],
        ['label' => 'On completion', 'type' => 'fixed', 'amount_cents' => 7000, 'due_date' => '2026-07-15'],
    ]);

    $rows = $invoice->paymentRequests()->orderBy('sort_order')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->amount_cents)->toBe(3000)
        ->and($rows[1]->amount_cents)->toBe(7000)
        ->and($rows[1]->due_date->toDateString())->toBe('2026-07-15');
});

it('folds the rounding remainder into the last milestone when the schedule covers the whole invoice', function () {
    $invoice = invoiceWithTotal(333);

    app(SaveInvoicePaymentRequests::class)->handle($invoice, [
        ['label' => 'Half', 'type' => 'percent', 'percent' => 50],
        ['label' => 'Half', 'type' => 'percent', 'percent' => 50],
    ]);

    $rows = $invoice->paymentRequests()->orderBy('sort_order')->get();
    expect($rows->sum('amount_cents'))->toBe(333)          // foots exactly to the total
        ->and([$rows[0]->amount_cents, $rows[1]->amount_cents])->toBe([167, 166]);
});

it('rejects a schedule that exceeds the invoice total', function () {
    $invoice = invoiceWithTotal(10000);

    expect(fn () => app(SaveInvoicePaymentRequests::class)->handle($invoice, [
        ['label' => 'A', 'type' => 'percent', 'percent' => 60],
        ['label' => 'B', 'type' => 'percent', 'percent' => 60],
    ]))->toThrow(PostingValidationException::class);

    expect($invoice->paymentRequests()->count())->toBe(0);
});

it('rejects an out-of-range percentage', function () {
    $invoice = invoiceWithTotal(10000);

    expect(fn () => app(SaveInvoicePaymentRequests::class)->handle($invoice, [
        ['label' => 'Too much', 'type' => 'percent', 'percent' => 150],
    ]))->toThrow(PostingValidationException::class);
});

it('derives milestone paid status from cumulative payments', function () {
    $invoice = invoiceWithTotal(10000);
    app(SaveInvoicePaymentRequests::class)->handle($invoice, [
        ['label' => 'Deposit', 'type' => 'percent', 'percent' => 30],
        ['label' => 'Balance', 'type' => 'percent', 'percent' => 70],
    ]);

    // A 3,000 payment covers exactly the first milestone.
    $invoice->update(['amount_paid_cents' => 3000]);
    $invoice->load('paymentRequests');

    $service = app(PaymentRequestScheduleStatus::class);
    $schedule = $service->for($invoice);

    expect($schedule[0]['status'])->toBe(PaymentRequestStatus::Paid)
        ->and($schedule[1]['status'])->toBe(PaymentRequestStatus::Requested)
        ->and($service->nextDueAmountCents($invoice))->toBe(7000);

    // Paying in full marks everything paid.
    $invoice->update(['amount_paid_cents' => 10000]);
    $invoice->load('paymentRequests');
    expect(app(PaymentRequestScheduleStatus::class)->nextDueAmountCents($invoice))->toBeNull();
});

it('treats a cancelled milestone as cancelled and not consuming payment', function () {
    $invoice = invoiceWithTotal(10000);
    app(SaveInvoicePaymentRequests::class)->handle($invoice, [
        ['label' => 'Cancelled deposit', 'type' => 'fixed', 'amount_cents' => 3000, 'status' => 'cancelled'],
        ['label' => 'Balance', 'type' => 'fixed', 'amount_cents' => 7000],
    ]);

    $invoice->update(['amount_paid_cents' => 7000]);
    $invoice->load('paymentRequests');

    $schedule = app(PaymentRequestScheduleStatus::class)->for($invoice);
    expect($schedule[0]['status'])->toBe(PaymentRequestStatus::Cancelled)
        ->and($schedule[1]['status'])->toBe(PaymentRequestStatus::Paid); // 7,000 covers it despite the cancelled row above
});

it('saves a schedule from the invoice page and shows it', function () {
    $invoice = invoiceWithTotal(10000);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->assertOk()
        ->set('editingSchedule', true)
        ->set('milestones', [
            ['label' => '50% deposit', 'type' => 'percent', 'value' => '50', 'due_date' => null],
            ['label' => 'On completion', 'type' => 'fixed', 'value' => '50.00', 'due_date' => null],
        ])
        ->call('savePaymentSchedule')
        ->assertHasNoErrors()
        ->assertSet('editingSchedule', false)
        ->assertSee('50% deposit')
        ->assertSee('On completion');

    expect($invoice->paymentRequests()->count())->toBe(2)
        ->and((int) $invoice->paymentRequests()->sum('amount_cents'))->toBe(10000);
});

it('surfaces an over-total schedule error on the invoice page', function () {
    $invoice = invoiceWithTotal(10000);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->set('editingSchedule', true)
        ->set('milestones', [
            ['label' => 'A', 'type' => 'fixed', 'value' => '90.00', 'due_date' => null],
            ['label' => 'B', 'type' => 'fixed', 'value' => '90.00', 'due_date' => null],
        ])
        ->call('savePaymentSchedule')
        ->assertHasErrors('milestones');

    expect($invoice->paymentRequests()->count())->toBe(0);
});
