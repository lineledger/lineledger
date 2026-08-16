<?php

use App\Actions\Sales\SaveSalesReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deposit;
use App\Models\SalesReceipt;
use App\Models\User;
use App\Services\Posting\SalesReceiptPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->uf = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->where('is_active', true)->firstOrFail();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->contact = Contact::factory()->customer()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postSalesReceiptToUf(int $cents): SalesReceipt
{
    $sr = app(SaveSalesReceipt::class)->handle([
        'contact_id' => test()->contact->id,
        'receipt_date' => '2026-06-01',
        'deposit_to_account_id' => test()->uf->id,
        'lines' => [['account_id' => test()->income->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);
    app(SalesReceiptPoster::class)->post($sr);

    return $sr->fresh();
}

it('lists a UF-parked sales receipt in the deposit picker and batches it into the bank', function () {
    $sr = postSalesReceiptToUf(7500);
    $total = (int) $sr->total_cents;

    $component = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->set('bank_account_id', $this->bank->id);

    $row = collect($component->get('availableReceipts'))->firstWhere('receipt_id', $sr->id);
    expect($row)->not->toBeNull()
        ->and($row['source'])->toBe('sales')
        ->and($row['amount'])->toBe($total);

    $component->call('save')->assertHasNoErrors();

    $deposit = Deposit::query()->firstOrFail();
    expect($deposit->status->value)->toBe('posted');

    $entry = $deposit->journalEntry()->with('lines')->first();
    expect($entry->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe($total)
        ->and($entry->lines->firstWhere('account_id', $this->uf->id)->credit_cents)->toBe($total);
});

it('drops a deposited sales receipt from a later deposit picker', function () {
    $sr = postSalesReceiptToUf(5000);

    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->set('bank_account_id', $this->bank->id)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = Livewire::test('pages::deposits.form', ['company' => $this->company]);

    expect(collect($fresh->get('availableReceipts'))->firstWhere('receipt_id', $sr->id))->toBeNull();
});
