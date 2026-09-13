<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Audit\PostedMutationGate;
use App\Services\Posting\ChequePoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('offers Reconcile, Import statement and Bank rules under the Actions menu', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    Livewire::actingAs($user)
        ->test('pages::banking.register', ['company' => $company])
        ->assertSee('Actions')
        ->assertSee('Reconcile')
        ->assertSee('Import statement')
        ->assertSee('Bank rules')
        ->assertSeeHtml(route('banking.import', ['company' => $company->slug]))
        ->assertSeeHtml(route('banking.rules', ['company' => $company->slug]));
});

it('shows cleared state read-only — the register cannot clear or unclear a line', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $cheque = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => '2001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Test',
    ]);

    $cheque->lines()->create([
        'account_id' => $expense->id,
        'description' => 'X',
        'amount_cents' => 1000,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    $bankLine = JournalLine::query()
        ->where('account_id', $bank->id)
        ->where('credit_cents', 1000)
        ->first();

    expect($bankLine->cleared_at)->toBeNull();

    $this->actingAs($user);

    $response = $this->get(route('banking.register', ['company' => $company->slug, 'account' => $bank->id]));
    $response->assertOk();

    // Clearing belongs to the reconciliation, so the register offers no way to
    // do it: no per-row toggle, no bulk actions, and no statement-balance field
    // to keep in step by hand.
    $register = Livewire::test('pages::banking.register', ['company' => $company])
        ->set('account_id', $bank->id);

    foreach (['toggleClear', 'clearAll', 'uncleared'] as $method) {
        expect(method_exists($register->instance(), $method))->toBeFalse();
    }

    $register->assertDontSee('Statement balance')
        ->assertDontSee('Clear all')
        ->assertDontSee('Unclear all')
        // The Show cleared filter stays — it is how you hide accounted-for rows.
        ->assertSee('Show cleared');

    expect($bankLine->fresh()->cleared_at)->toBeNull();

    // A line cleared by a reconciliation still reads as cleared here.
    PostedMutationGate::within(fn () => $bankLine->forceFill(['cleared_at' => now()])->save());

    Livewire::test('pages::banking.register', ['company' => $company])
        ->set('account_id', $bank->id)
        ->assertSeeHtml('data-test="register-cleared-mark"');

    app()->forgetInstance('current_company');
});

it('marks a voided cheque and its reversal void, and leaves them out once cleared rows are hidden', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);
    $this->actingAs($user);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $post = function (string $number, int $cents) use ($bank, $expense): Cheque {
        $cheque = Cheque::create([
            'bank_account_id' => $bank->id,
            'cheque_no' => $number,
            'cheque_date' => '2026-08-27',
            'payee_name' => 'Receiver General',
        ]);
        $cheque->lines()->create(['account_id' => $expense->id, 'description' => 'X', 'amount_cents' => $cents, 'line_order' => 0]);
        app(ChequePoster::class)->post($cheque);

        return $cheque;
    };

    $voided = $post('3001', 1520000);
    $post('3002', 96978);
    app(ChequePoster::class)->void($voided->fresh(), CarbonImmutable::parse('2026-09-12'));

    $register = Livewire::test('pages::banking.register', ['company' => $company])
        ->set('account_id', $bank->id);

    // Everything shows: the voided cheque and its reversal are marked void, the
    // live cheque waits to clear.
    expect($register->instance()->lines)->toHaveCount(3)
        ->and($register->instance()->voidLineIds)->toHaveCount(2);
    $register->assertSeeHtml('data-test="register-void-mark"');

    // Hiding cleared rows leaves only what is outstanding: the live cheque.
    $register->set('showCleared', false);

    expect($register->instance()->lines->pluck('credit_cents')->all())->toBe([96978]);

    app()->forgetInstance('current_company');
});
