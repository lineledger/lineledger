<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Services\Posting\ControlAccountResolver;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    $this->action = new EnableCompanyCurrency;
});

it('enables a foreign currency with its own AR/AP control accounts', function () {
    $currency = $this->action->handle($this->company, 'USD');

    expect($currency)->toBeInstanceOf(CompanyCurrency::class)
        ->and($currency->currency_code)->toBe('USD')
        ->and($currency->is_home)->toBeFalse()
        ->and($currency->is_active)->toBeTrue();

    $ar = Account::withoutGlobalScopes()->find($currency->ar_account_id);
    $ap = Account::withoutGlobalScopes()->find($currency->ap_account_id);

    expect($ar->subtype)->toBe(AccountSubtype::AccountsReceivable)
        ->and($ar->currency_code)->toBe('USD')
        ->and($ar->is_system)->toBeTrue()
        ->and($ar->name)->toContain('(USD)')
        ->and($ap->subtype)->toBe(AccountSubtype::AccountsPayable)
        ->and($ap->currency_code)->toBe('USD');
});

it('lazily creates the FX gain/loss accounts and flips the company flag', function () {
    $this->action->handle($this->company, 'USD');
    $this->company->refresh();

    expect($this->company->multicurrency_enabled)->toBeTrue()
        ->and($this->company->exchange_gain_loss_account_id)->not->toBeNull()
        ->and($this->company->unrealized_gain_loss_account_id)->not->toBeNull();

    $gainLoss = Account::withoutGlobalScopes()->find($this->company->exchange_gain_loss_account_id);
    expect($gainLoss->subtype)->toBe(AccountSubtype::OtherExpense)
        ->and($gainLoss->currency_code)->toBeNull();
});

it('records the home currency as an is_home row', function () {
    $this->action->handle($this->company, 'USD');

    $home = CompanyCurrency::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('currency_code', 'CAD')
        ->first();

    expect($home)->not->toBeNull()
        ->and($home->is_home)->toBeTrue();
});

it('does not create a second set of FX accounts when enabling a second currency', function () {
    $this->action->handle($this->company, 'USD');
    $this->company->refresh();
    $gainLossId = $this->company->exchange_gain_loss_account_id;

    $this->action->handle($this->company, 'EUR');
    $this->company->refresh();

    expect($this->company->exchange_gain_loss_account_id)->toBe($gainLossId)
        ->and(CompanyCurrency::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(3); // CAD home + USD + EUR
});

it('reactivates rather than duplicating when re-enabling', function () {
    $first = $this->action->handle($this->company, 'USD');
    $second = $this->action->handle($this->company, 'USD');

    expect($second->id)->toBe($first->id)
        ->and(CompanyCurrency::withoutGlobalScopes()->where('company_id', $this->company->id)->where('currency_code', 'USD')->count())->toBe(1);
});

it('rejects the home currency', function () {
    $this->action->handle($this->company, 'CAD');
})->throws(DomainException::class);

it('rejects an unknown currency', function () {
    $this->action->handle($this->company, 'ZZZ');
})->throws(DomainException::class);

it('resolves home vs foreign control accounts', function () {
    $currency = $this->action->handle($this->company, 'USD');
    $resolver = new ControlAccountResolver;

    $homeAr = $resolver->resolve($this->company, AccountSubtype::AccountsReceivable, 'CAD');
    $nullAr = $resolver->resolve($this->company, AccountSubtype::AccountsReceivable, null);
    $usdAr = $resolver->resolve($this->company, AccountSubtype::AccountsReceivable, 'USD');

    expect($homeAr->currency_code)->toBeNull()
        ->and($nullAr->id)->toBe($homeAr->id)
        ->and($usdAr->id)->toBe($currency->ar_account_id)
        ->and($usdAr->currency_code)->toBe('USD');
});
