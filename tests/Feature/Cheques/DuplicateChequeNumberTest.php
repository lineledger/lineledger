<?php

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->payee = Contact::create(['display_name' => 'Receiver General - CRA', 'is_vendor' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function writeCheque(string $number, string $date = '2026-08-28'): Cheque
{
    $cheque = Cheque::create([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => $number,
        'cheque_date' => $date,
        'payee_contact_id' => test()->payee->id,
        'payee_name' => 'Receiver General - CRA',
    ]);

    $cheque->lines()->create([
        'account_id' => test()->expense->id,
        'description' => 'Remittance',
        'amount_cents' => 43877,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque->fresh('lines'));

    return $cheque->fresh('lines');
}

function chequeFormLines(): array
{
    return [[
        'account_id' => test()->expense->id,
        'description' => 'Remittance',
        'amount' => '107.98',
        'tax_code_id' => null,
        'secondary_tax_code_id' => null,
        'tax_code_ids' => [],
        'tax_overrides' => [],
        'class_id' => null,
        'location_id' => null,
        'auto_tax_cents' => 0,
        'tax_cents' => 0,
        'secondary_tax_cents' => 0,
        'total' => 0,
    ]];
}

it('posts a second cheque with a number already used on the same bank account', function () {
    writeCheque('DD');

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->bank->id)
        ->set('cheque_no', 'DD')
        ->set('cheque_date', '2026-09-09')
        ->set('payee_contact_id', $this->payee->id)
        ->set('payee_name', 'Receiver General - CRA')
        ->set('lines', chequeFormLines())
        ->call('postCheque')
        ->assertHasNoErrors();

    $dd = Cheque::query()->where('cheque_no', 'DD')->get();

    expect($dd)->toHaveCount(2)
        ->and($dd->pluck('status')->all())->each->toBe(ChequeStatus::Posted);
});

it('reuses a number freed by a void without complaint', function () {
    $first = writeCheque('e-transfer');
    app(ChequePoster::class)->void($first->fresh('lines'));

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->bank->id)
        ->set('cheque_no', 'e-transfer')
        ->set('cheque_date', '2026-09-09')
        ->set('payee_contact_id', $this->payee->id)
        ->set('payee_name', 'Receiver General - CRA')
        ->set('lines', chequeFormLines())
        ->call('postCheque')
        ->assertHasNoErrors();

    expect(Cheque::query()->where('cheque_no', 'e-transfer')->count())->toBe(2);
});

it('warns about a repeated number without blocking the save', function () {
    writeCheque('2272');

    $component = Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('bank_account_id', $this->bank->id)
        ->set('cheque_no', '2272');

    $component->assertSee('Already used on this account');

    // A number nobody has used draws no warning.
    $component->set('cheque_no', '2273')->assertDontSee('Already used on this account');
});

it('does not warn a cheque about its own number when editing it', function () {
    $cheque = writeCheque('DD3');

    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSet('cheque_no', 'DD3')
        ->assertDontSee('Already used on this account');
});
