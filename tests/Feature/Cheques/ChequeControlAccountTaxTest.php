<?php

use App\Actions\Banking\SaveCheque;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A cheque line coded to Accounts Receivable / Payable carries no sales tax:
 * the balance it settles already includes the tax its originating invoice or
 * bill recorded. Taxing the settlement again would gross a non-recoverable code
 * into the AR/AP leg itself — moving the contact's sub-ledger by more than the
 * payment — and would claim an input tax credit that was never incurred.
 *
 * A cheque coded to an ordinary expense account is taxed exactly as before.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->orderBy('code')->firstOrFail();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function chequeTaxForm(): Testable
{
    return Livewire::test('pages::cheques.form', ['company' => test()->company]);
}

/**
 * The posted GL legs of the only cheque, as [account_id => net debit cents].
 *
 * @return array<int, int>
 */
function chequeLegAmounts(): array
{
    return JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q->where('source_type', Cheque::class))
        ->get()
        ->groupBy('account_id')
        ->map(fn ($lines) => (int) $lines->sum(fn (JournalLine $l) => (int) $l->debit_cents - (int) $l->credit_cents))
        ->all();
}

it('clears a tax code already on the line when the account becomes Accounts Receivable', function () {
    $component = chequeTaxForm()
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id])
        ->set('lines.0.tax_overrides.'.$this->gst->id, '7.00');

    // Taxed normally while it is an expense line.
    expect($component->get('lines.0.tax_cents'))->toBe(700);

    $component->set('lines.0.account_id', $this->ar->id);

    expect($component->get('lines.0.tax_code_id'))->toBeNull()
        ->and($component->get('lines.0.secondary_tax_code_id'))->toBeNull()
        ->and($component->get('lines.0.tax_code_ids'))->toBe([])
        ->and($component->get('lines.0.tax_overrides'))->toBe([])
        ->and($component->get('lines.0.tax_cents'))->toBe(0)
        ->and($component->get('lines.0.secondary_tax_cents'))->toBe(0)
        ->and($component->get('lines.0.total'))->toBe(10000);
});

it('never fills an Accounts Payable line from the account default tax code', function () {
    $this->ap->update(['default_tax_code_id' => $this->gst->id]);

    $component = chequeTaxForm()
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.account_id', $this->ap->id);

    expect($component->get('lines.0.tax_code_id'))->toBeNull()
        ->and($component->get('lines.0.tax_cents'))->toBe(0);
});

it('swaps the tax picker for an explanation on a control-account line', function () {
    chequeTaxForm()
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.tax_code_ids', [$this->gst->id])
        ->assertSeeHtml('data-test="line-tax"')
        ->assertSeeHtml('data-test="line-tax-override"')
        ->assertDontSeeHtml('data-test="line-tax-excluded"')
        ->set('lines.0.account_id', $this->ar->id)
        ->assertDontSeeHtml('data-test="line-tax"')
        ->assertDontSeeHtml('data-test="line-tax-override"')
        ->assertSeeHtml('data-test="line-tax-excluded"')
        ->assertSee('Included in the invoice')
        ->set('lines.0.account_id', $this->ap->id)
        ->assertSee('Included in the bill');
});

it('shows zero tax when reopening a cheque saved with tax on an Accounts Receivable line', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    // A row written before the rule existed: tax on an AR line.
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '1001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Margaret Schaeffer',
    ]);
    $cheque->lines()->create([
        'account_id' => $this->ar->id,
        'contact_id' => $customer->id,
        'amount_cents' => 29900,
        'tax_code_id' => $this->gst->id,
        'tax_cents' => 1495,
        'line_order' => 0,
    ]);
    $cheque->refresh();
    $cheque->recalculateAmount();

    expect($cheque->fresh()->amount_cents)->toBe(31395);

    $component = Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque]);

    expect($component->get('lines.0.tax_cents'))->toBe(0)
        ->and($component->get('lines.0.tax_code_id'))->toBeNull()
        ->and($component->get('lines.0.total'))->toBe(29900);

    $component->call('saveDraft')->assertHasNoErrors();

    expect($cheque->fresh()->amount_cents)->toBe(29900)
        ->and($cheque->fresh()->lines()->firstOrFail()->tax_cents)->toBe(0);
});

it('drops tax on a control-account line handed straight to the action', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    $cheque = app(SaveCheque::class)->handle([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '1002',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Margaret Schaeffer',
        'lines' => [
            [
                'account_id' => $this->ar->id,
                'contact_id' => $customer->id,
                'amount_cents' => 29900,
                'tax_code_id' => $this->gst->id,
                'tax_override_cents' => 1495,
            ],
            [
                'account_id' => $this->expense->id,
                'amount_cents' => 10000,
                'tax_code_id' => $this->gst->id,
            ],
        ],
    ]);

    [$arLine, $expenseLine] = $cheque->lines()->orderBy('line_order')->get()->all();

    expect($arLine->tax_code_id)->toBeNull()
        ->and($arLine->tax_cents)->toBe(0)
        ->and($arLine->tax_override_cents)->toBeNull();

    // The ordinary expense line in the same cheque is untouched.
    expect($expenseLine->tax_code_id)->toBe($this->gst->id)
        ->and($expenseLine->tax_cents)->toBe(500);

    expect($cheque->fresh()->amount_cents)->toBe(29900 + 10500);
});

it('posts an Accounts Receivable refund with no input tax credit leg', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);
    $gstPayable = Account::query()->where('subtype', AccountSubtype::TaxPayable->value)->orderBy('code')->firstOrFail();

    chequeTaxForm()
        ->set('payee_contact_id', $customer->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_id', $customer->id)
        ->set('lines.0.amount', '299.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id])
        ->call('postCheque')
        ->assertHasNoErrors();

    $legs = chequeLegAmounts();

    expect($legs)->toHaveCount(2)
        ->and($legs[$this->ar->id])->toBe(29900)
        ->and($legs[$this->bank->id])->toBe(-29900)
        ->and($legs)->not->toHaveKey($gstPayable->id);

    expect(Cheque::firstOrFail()->amount_cents)->toBe(29900);
});

it('posts an Accounts Payable line without tax and moves the vendor balance by the payment', function () {
    $vendor = Contact::factory()->vendor()->create(['company_id' => $this->company->id]);

    chequeTaxForm()
        ->set('payee_contact_id', $vendor->id)
        ->set('lines.0.account_id', $this->ap->id)
        ->set('lines.0.contact_id', $vendor->id)
        ->set('lines.0.amount', '500.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id])
        ->call('postCheque')
        ->assertHasNoErrors();

    $legs = chequeLegAmounts();

    expect($legs)->toHaveCount(2)
        ->and($legs[$this->ap->id])->toBe(50000)
        ->and($legs[$this->bank->id])->toBe(-50000);

    // AP is a credit-balance sub-ledger, so a $500 payment reduces it by $500.
    expect((int) $vendor->fresh()->ap_balance_cents)->toBe(-50000);
});

it('still taxes an ordinary expense cheque line', function () {
    chequeTaxForm()
        ->set('payee_name', 'Tax Vendor')
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id])
        ->call('postCheque')
        ->assertHasNoErrors();

    $gstPayable = Account::query()->where('subtype', AccountSubtype::TaxPayable->value)->orderBy('code')->firstOrFail();
    $legs = chequeLegAmounts();

    expect($legs[$this->expense->id])->toBe(10000)
        ->and($legs[$gstPayable->id])->toBe(500)
        ->and($legs[$this->bank->id])->toBe(-10500);
});

it('reports a cheque already posted with tax on a control-account line', function () {
    $customer = Contact::factory()->customer()->create(['company_id' => $this->company->id]);

    // Post a clean AR refund, then force the bad tax back onto the stored line
    // the way a pre-rule cheque would carry it.
    chequeTaxForm()
        ->set('payee_contact_id', $customer->id)
        ->set('lines.0.account_id', $this->ar->id)
        ->set('lines.0.contact_id', $customer->id)
        ->set('lines.0.amount', '299.00')
        ->call('postCheque')
        ->assertHasNoErrors();

    $cheque = Cheque::firstOrFail();

    $this->artisan('integrity:check', ['company' => $this->company->id, '--no-alert' => true])
        ->assertExitCode(0);

    $cheque->lines()->firstOrFail()->update(['tax_code_id' => $this->gst->id, 'tax_cents' => 1495]);

    $this->artisan('integrity:check', ['company' => $this->company->id, '--no-alert' => true])
        ->expectsOutputToContain('carries sales tax on an Accounts Receivable / Payable line')
        ->assertFailed();
});
