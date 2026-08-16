<?php

use App\Actions\Sales\SaveSalesReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\SalesReceiptPoster;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->uf = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $this->contact = Contact::factory()->customer()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('includes pay-now sales receipts in Sales by Customer (pre-tax subtotal)', function () {
    $sr = app(SaveSalesReceipt::class)->handle([
        'contact_id' => $this->contact->id,
        'receipt_date' => '2026-06-01',
        'deposit_to_account_id' => $this->uf->id,
        'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 6000]],
    ]);
    app(SalesReceiptPoster::class)->post($sr);

    $rows = app(SalesPurchaseReportBuilder::class)->salesByDimension(
        $this->company,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
        'contact',
    );

    $row = $rows->firstWhere('key', $this->contact->id);

    expect($row)->not->toBeNull()
        ->and($row['amount_cents'])->toBe(6000);
});
