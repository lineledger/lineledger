<?php

use App\Actions\Sales\SaveCreditMemo;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\CreditMemoStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\CreditMemoPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->forCountry(Country::Canada, 'BC')->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::factory()->create(['is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->pst = TaxCode::where('code', 'PST-BC')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('totals both taxes on the edit form, matching the saved credit memo', function () {
    // GST 5% + PST 7% on 150.00, and a -25.00 line with the codes the other way round.
    $memo = app(SaveCreditMemo::class)->handle([
        'contact_id' => $this->customer->id,
        'credit_memo_date' => now()->toDateString(),
        'lines' => [
            ['account_id' => $this->income->id, 'quantity' => 1, 'unit_price_cents' => 15000,
                'tax_code_id' => $this->gst->id, 'secondary_tax_code_id' => $this->pst->id],
            ['account_id' => $this->income->id, 'quantity' => 1, 'unit_price_cents' => -2500,
                'tax_code_id' => $this->pst->id, 'secondary_tax_code_id' => $this->gst->id],
        ],
    ]);

    expect($memo->total_cents)->toBe(14000);

    $totals = Livewire::test('pages::credit-memos.form', ['company' => $this->company, 'credit_memo' => $memo])
        ->instance()->totals;

    expect($totals)->toBe(['subtotal' => 12500, 'tax' => 1500, 'total' => 14000]);
});

it('totals both taxes while entering a new credit memo', function () {
    $totals = Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->pst->id])
        ->instance()->totals;

    expect($totals)->toBe(['subtotal' => 10000, 'tax' => 1200, 'total' => 11200]);
});

it('saves and posts a credit memo with a negative-quantity line at its net total', function () {
    // A credit memo that refunds a premium service and re-charges the customer
    // for the standard one they received instead (quantity -1), plus the GST and
    // a card fee on the difference coded straight to their accounts.
    $accounts = Account::query()->whereIn('code', ['4000', '4100', '4900', '2200'])->get()->keyBy('code');

    $lines = [
        ['4000', 'Premium service', '1', '995.00'],
        ['4100', 'Standard service', '-1', '195.00'],
        ['2200', 'GST', '1', '40.00'],
        ['4900', 'Card processing fee', '1', '20.16'],
    ];

    $form = Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->call('selectContact', $this->customer->id);

    foreach ($lines as $i => [$code, $description, $quantity, $price]) {
        if ($i > 0) {
            $form->call('addLine');
        }

        $form->set("lines.{$i}.account_id", $accounts[$code]->id)
            ->set("lines.{$i}.tax_code_ids", [])
            ->set("lines.{$i}.description", $description)
            ->set("lines.{$i}.quantity", $quantity)
            ->set("lines.{$i}.unit_price", $price);
    }

    expect($form->instance()->totals)->toBe(['subtotal' => 86016, 'tax' => 0, 'total' => 86016]);

    $form->call('postCreditMemo')->assertHasNoErrors()->assertRedirect();

    $memo = CreditMemo::query()->with('lines')->sole();
    $standard = $memo->lines->firstWhere('description', 'Standard service');

    expect($memo->status)->toBe(CreditMemoStatus::Posted)
        ->and($memo->subtotal_cents)->toBe(86016)
        ->and($memo->total_cents)->toBe(86016)
        ->and($standard->quantity)->toBe('-1.0000')
        ->and((int) $standard->unit_price_cents)->toBe(19500)
        ->and((int) $standard->line_subtotal_cents)->toBe(-19500);
});

it('refuses to save a credit memo whose lines net to zero, with an error on the lines', function () {
    Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->call('selectContact', $this->customer->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.tax_code_ids', [])
        ->set('lines.0.unit_price', '100.00')
        ->call('addLine')
        ->set('lines.1.account_id', $this->income->id)
        ->set('lines.1.tax_code_ids', [])
        ->set('lines.1.quantity', '-2')
        ->set('lines.1.unit_price', '50.00')
        ->call('saveDraft')
        ->assertHasErrors(['lines' => 'The credit memo total must be greater than zero.'])
        ->assertNoRedirect();

    expect(CreditMemo::query()->count())->toBe(0);
});

it('refuses to post a credit memo whose total, tax included, is below zero', function () {
    // The subtotal (100.00 - 95.00) is positive, but the GST and PST taken off
    // the negative line pull the total the form shows below zero.
    Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->call('selectContact', $this->customer->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.tax_code_ids', [])
        ->set('lines.0.unit_price', '100.00')
        ->call('addLine')
        ->set('lines.1.account_id', $this->income->id)
        ->set('lines.1.tax_code_ids', [$this->gst->id, $this->pst->id])
        ->set('lines.1.quantity', '-1')
        ->set('lines.1.unit_price', '95.00')
        ->assertSet('lines.1.total', -10640)
        ->call('postCreditMemo')
        ->assertHasErrors(['lines' => 'The credit memo total must be greater than zero.'])
        ->assertNoRedirect();

    expect(CreditMemo::query()->count())->toBe(0);
});

it('leaves a posted credit memo untouched when an edit would take its total below zero', function () {
    $memo = app(SaveCreditMemo::class)->handle([
        'contact_id' => $this->customer->id,
        'credit_memo_date' => now()->toDateString(),
        'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 10000]],
    ]);
    app(CreditMemoPoster::class)->post($memo);

    Livewire::test('pages::credit-memos.form', ['company' => $this->company, 'credit_memo' => $memo->fresh()])
        ->set('lines.0.quantity', '-1')
        ->call('postCreditMemo')
        ->assertHasErrors(['lines' => 'The credit memo total must be greater than zero.'])
        ->assertNoRedirect();

    $memo->refresh();

    expect($memo->total_cents)->toBe(10000)
        ->and($memo->lines()->sole()->quantity)->toBe('1.0000')
        ->and((int) $memo->journalEntry->lines->sum('debit_cents'))->toBe(10000);
});
