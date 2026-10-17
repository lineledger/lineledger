<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use App\Services\Printing\ChequePdfRenderer;
use App\Support\Locales;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    Locales::apply('en');
});

function postedCheque(): Cheque
{
    $cheque = Cheque::create([
        'bank_account_id' => test()->bank->id,
        'cheque_no' => '1001',
        'cheque_date' => '2026-05-20',
        'payee_name' => 'Jane Doe',
        'memo' => 'test',
    ]);

    $cheque->lines()->create([
        'account_id' => test()->expense->id,
        'description' => 'Rent',
        'amount_cents' => 500,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    return $cheque->fresh(['bankAccount', 'payee', 'lines.account']);
}

it('serves a PDF from the cheque print endpoint', function () {
    $cheque = postedCheque();

    $response = $this->get(route('cheques.print', [
        'company' => $this->company->slug,
        'cheque' => $cheque->id,
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
});

it('prepares cheque draw data from the Cheque model', function () {
    $cheque = postedCheque();

    $data = app(ChequePdfRenderer::class)->dataFor($cheque);

    expect($data['date_comb'])->toBe('20260520');
    expect($data['date_slashed'])->toBe('5/20/2026');
    expect($data['payee'])->toBe('Jane Doe');
    expect($data['amount_numeric'])->toBe('**5.00');
    expect($data['total_numeric'])->toBe('5.00');
    expect($data['memo'])->toBe('test');
    expect($data['amount_words'])->toBe('*****Five and 00/100');
    expect($data['lines'])->toHaveCount(1);
    // The voucher names the account the way QuickBooks does: "200 · Customer
    // Receivables" — the code alone means nothing to whoever opens the envelope.
    expect($data['lines'][0])->toMatchArray([
        'account' => $cheque->lines[0]->account->code.' · '.$cheque->lines[0]->account->name,
        'description' => 'Rent',
        'amount' => '5.00',
    ]);
});

it('formats the cheque amount in words in the payee document locale', function () {
    $this->company->update(['locale' => 'en']);
    $vendor = Contact::create([
        'display_name' => 'Fournisseur Québec',
        'is_vendor' => true,
        'locale' => 'fr',
    ]);

    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'payee_contact_id' => $vendor->id,
        'cheque_no' => '1002',
        'cheque_date' => '2026-05-20',
        'payee_name' => $vendor->display_name,
        'memo' => 'Loyer',
    ]);

    $cheque->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Loyer',
        'amount_cents' => 123456,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    Locales::apply('en');

    $data = app(ChequePdfRenderer::class)->dataFor($cheque->fresh(['bankAccount', 'payee', 'lines.account']));

    expect($data['amount_words'])->toBe('*****Mille Deux Cent Trente-Quatre et 56/100')
        ->and(app()->getLocale())->toBe('en');
});
