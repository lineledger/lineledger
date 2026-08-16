<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use App\Services\Printing\ChequePdfRenderer;

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

    expect($data['date_mmddyyyy'])->toBe('05202026');
    expect($data['date_slashed'])->toBe('5/20/2026');
    expect($data['payee'])->toBe('Jane Doe');
    expect($data['amount_numeric'])->toBe('**5.00');
    expect($data['total_numeric'])->toBe('5.00');
    expect($data['memo'])->toBe('test');
    expect($data['amount_words'])->toEndWith('Five and 00/100');
    expect($data['lines'])->toHaveCount(1);
    expect($data['lines'][0])->toMatchArray([
        'account' => $cheque->lines[0]->account->code,
        'description' => 'Rent',
        'amount' => '5.00',
    ]);
});
