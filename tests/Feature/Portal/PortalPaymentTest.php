<?php

use App\Actions\Portal\EnsureStripeAccounts;
use App\Actions\Portal\RecordStripePayment;
use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Services\Posting\InvoicePoster;
use App\Services\Stripe\StripePaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    $this->company->forceFill(['stripe_account_id' => 'acct_test123'])->save();
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $this->customer = Contact::create(['display_name' => 'Acme', 'email' => 'a@x.test', 'is_customer' => true]);

    $this->invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-PAY-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $this->invoice->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Services',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($this->invoice);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function arControlBalanceCents(Company $company): int
{
    $arIds = Account::query()
        ->where('company_id', $company->id)
        ->where('subtype', AccountSubtype::AccountsReceivable->value)
        ->pluck('id');

    return (int) DB::table('journal_lines')
        ->whereIn('account_id', $arIds)
        ->where('is_posted', true)
        ->sum(DB::raw('debit_cents - credit_cents'));
}

it('records a settled payment as a posted receipt that closes the invoice', function () {
    expect(arControlBalanceCents($this->company))->toBe(10000);

    app(RecordStripePayment::class)->handle($this->company, 'pi_1', 10000, 290, $this->customer->id, [$this->invoice->id]);

    $receipt = CustomerReceipt::firstWhere('stripe_payment_intent_id', 'pi_1');

    expect($receipt)->not->toBeNull()
        ->and($receipt->status)->toBe(ReceiptStatus::Posted)
        ->and((int) $receipt->amount_cents)->toBe(10000)
        ->and((int) $receipt->stripe_fee_cents)->toBe(290)
        ->and($receipt->journal_entry_id)->not->toBeNull();

    $this->invoice->refresh();
    expect($this->invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->balanceCents())->toBe(0);

    // Receipt cleared AR back to zero.
    expect(arControlBalanceCents($this->company))->toBe(0);
});

it('books the processing fee to a separate journal entry', function () {
    app(RecordStripePayment::class)->handle($this->company, 'pi_2', 10000, 290, $this->customer->id, [$this->invoice->id]);

    $ledger = app(EnsureStripeAccounts::class)->handle($this->company);

    $feeDebit = (int) DB::table('journal_lines')->where('account_id', $ledger['fees']->id)->where('is_posted', true)->sum('debit_cents');
    $clearingNet = (int) DB::table('journal_lines')->where('account_id', $ledger['clearing']->id)->where('is_posted', true)->sum(DB::raw('debit_cents - credit_cents'));

    // Fee expense debited 290; clearing nets to the un-paid-out balance (10000 in - 290 fee).
    expect($feeDebit)->toBe(290)
        ->and($clearingNet)->toBe(10000 - 290);
});

it('is idempotent on the PaymentIntent id', function () {
    $first = app(RecordStripePayment::class)->handle($this->company, 'pi_3', 10000, 290, $this->customer->id, [$this->invoice->id]);
    $second = app(RecordStripePayment::class)->handle($this->company, 'pi_3', 10000, 290, $this->customer->id, [$this->invoice->id]);

    expect($second->id)->toBe($first->id)
        ->and(CustomerReceipt::where('stripe_payment_intent_id', 'pi_3')->count())->toBe(1);
});

it('posts a receipt when a signed Connect webhook arrives', function () {
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    // Avoid hitting Stripe for the fee lookup.
    $this->mock(StripePaymentService::class, function ($mock) {
        $mock->shouldReceive('feeForPaymentIntent')->andReturn(290);
    });

    $payload = json_encode([
        'id' => 'evt_1',
        'type' => 'payment_intent.succeeded',
        'account' => 'acct_test123',
        'data' => ['object' => [
            'id' => 'pi_hook_1',
            'object' => 'payment_intent',
            'amount' => 10000,
            'metadata' => ['contact_id' => (string) $this->customer->id, 'invoice_ids' => (string) $this->invoice->id],
        ]],
    ]);

    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

    $this->call(
        'POST',
        route('stripe.webhook'),
        [],
        [],
        [],
        ['HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertOk();

    $receipt = CustomerReceipt::firstWhere('stripe_payment_intent_id', 'pi_hook_1');
    expect($receipt)->not->toBeNull()
        ->and($receipt->status)->toBe(ReceiptStatus::Posted);

    $this->invoice->refresh();
    expect($this->invoice->status)->toBe(InvoiceStatus::Paid);
});

it('rejects a webhook with a bad signature', function () {
    config()->set('services.stripe.webhook_secret', 'whsec_test');

    $this->call(
        'POST',
        route('stripe.webhook'),
        [],
        [],
        [],
        ['HTTP_Stripe-Signature' => 't=1,v1=deadbeef', 'CONTENT_TYPE' => 'application/json'],
        '{"id":"evt_x","type":"payment_intent.succeeded"}',
    )->assertStatus(400);
});
