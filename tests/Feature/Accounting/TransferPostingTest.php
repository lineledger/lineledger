<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\SaveAccount;
use App\Actions\Banking\SaveTransfer;
use App\Enums\AccountSubtype;
use App\Enums\TransferStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Transfer;
use App\Services\Posting\TransferPoster;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    // A second home-currency bank to transfer into.
    $this->savings = app(SaveAccount::class)->handle([
        'code' => '1011', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('posts a same-currency transfer: DR destination / CR source', function () {
    $transfer = Transfer::create([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $this->savings->id,
        'transfer_no' => 'XFR-000001',
        'transfer_date' => now()->toDateString(),
        'from_amount_cents' => 5000,
        'to_amount_cents' => 5000,
    ]);

    $entry = app(TransferPoster::class)->post($transfer);
    $transfer->refresh();

    expect($transfer->status)->toBe(TransferStatus::Posted);
    expect($entry->isBalanced())->toBeTrue();
    expect($entry->lines)->toHaveCount(2); // no FX gain/loss leg

    expect($this->bank->fresh()->balance_cents)->toBe(-5000);  // source credited
    expect($this->savings->fresh()->balance_cents)->toBe(5000); // destination debited
});

it('voids a posted transfer and reverses the GL', function () {
    $transfer = Transfer::create([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $this->savings->id,
        'transfer_no' => 'XFR-000002',
        'transfer_date' => now()->toDateString(),
        'from_amount_cents' => 2500,
        'to_amount_cents' => 2500,
    ]);

    $poster = app(TransferPoster::class);
    $poster->post($transfer);
    $poster->void($transfer->fresh());

    $transfer->refresh();

    expect($transfer->status)->toBe(TransferStatus::Void);
    expect($this->bank->fresh()->balance_cents)->toBe(0);
    expect($this->savings->fresh()->balance_cents)->toBe(0);
});

it('posts a bank → credit-card transfer that pays down the card (DR card / CR bank)', function () {
    // Regression guard: transfers were restricted to Bank accounts in the form + API
    // even though the poster is subtype-agnostic; bank → credit-card = pay down the card.
    $card = $this->company->accounts()->create([
        'code' => 'CC-VISA',
        'name' => 'Visa',
        'subtype' => AccountSubtype::CreditCard->value,
        'type' => AccountSubtype::CreditCard->type()->value,
        'normal_balance' => AccountSubtype::CreditCard->type()->normalBalance()->value,
        'is_active' => true,
    ]);

    $transfer = Transfer::create([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $card->id,
        'transfer_no' => 'XFR-CC',
        'transfer_date' => now()->toDateString(),
        'from_amount_cents' => 7500,
        'to_amount_cents' => 7500,
    ]);

    $entry = app(TransferPoster::class)->post($transfer);

    expect($entry->isBalanced())->toBeTrue()
        ->and($this->bank->fresh()->balance_cents)->toBe(-7500) // bank credited (cash out)
        ->and($card->fresh()->balance_cents)->toBe(-7500);      // card debited (liability paid down)
});

it('posts a cross-currency transfer with a realized exchange gain', function () {
    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    $usdBank = app(SaveAccount::class)->handle([
        'code' => '1015', 'name' => 'USD Chequing', 'subtype' => AccountSubtype::Bank->value,
        'currency_code' => 'USD',
    ]);

    // Send 130.00 CAD, receive 100.00 USD locked at 1.35 → 135.00 CAD home value.
    $transfer = Transfer::create([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $usdBank->id,
        'transfer_no' => 'XFR-000003',
        'transfer_date' => '2026-03-01',
        'from_amount_cents' => 13000,
        'to_amount_cents' => 10000,
        'to_fx_rate' => '1.35',
    ]);

    $entry = app(TransferPoster::class)->post($transfer);
    $transfer->refresh();

    expect($entry->isBalanced())->toBeTrue();
    expect($entry->lines)->toHaveCount(3); // DR USD bank, CR CAD bank, CR exchange gain

    expect($usdBank->fresh()->balance_cents)->toBe(13500)
        ->and($usdBank->fresh()->foreignBalanceCents())->toBe(10000)
        ->and($this->bank->fresh()->balance_cents)->toBe(-13000);

    $exchange = Account::find($this->company->exchange_gain_loss_account_id);
    expect($exchange->fresh()->balance_cents)->toBe(-500); // 5.00 CAD credit = realized gain

    expect($transfer->to_currency_code)->toBe('USD')
        ->and($transfer->home_amount_cents)->toBe(13000);
});

it('rejects a transfer between the same account', function () {
    expect(fn () => app(SaveTransfer::class)->handle([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $this->bank->id,
        'transfer_date' => now()->toDateString(),
        'from_amount_cents' => 5000,
        'to_amount_cents' => 5000,
    ]))->toThrow(RuntimeException::class);
});

it('rejects a zero-amount transfer', function () {
    expect(fn () => app(SaveTransfer::class)->handle([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $this->savings->id,
        'transfer_date' => now()->toDateString(),
        'from_amount_cents' => 0,
        'to_amount_cents' => 0,
    ]))->toThrow(RuntimeException::class);
});

it('blocks posting a transfer in a company-locked period', function () {
    $this->company->forceFill(['lock_date' => '2026-01-31'])->save();

    $transfer = Transfer::create([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $this->savings->id,
        'transfer_no' => 'XFR-000004',
        'transfer_date' => '2026-01-15',
        'from_amount_cents' => 5000,
        'to_amount_cents' => 5000,
    ]);

    expect(fn () => app(TransferPoster::class)->post($transfer))
        ->toThrow(PeriodLockedException::class);
});
