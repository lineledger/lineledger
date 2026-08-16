<?php

use App\Enums\CompanyRole;
use App\Enums\DonationReceiptStatus;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\DonationReceipt;
use App\Models\User;
use App\Services\Charity\DonationReceiptIssuer;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'organization_type' => 'charity',
        'charity_registration_number' => '123456789RR0001',
        'features_fundraising' => true,
        'fiscal_year_start_month' => 1,
    ]);
    app()->instance('current_company', $this->company);

    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
});

afterEach(fn () => app()->forgetInstance('current_company'));

test('the donation receipt pages render for a registered charity', function () {
    Livewire::test('pages::donation-receipts.index', ['company' => $this->company])->assertOk();
    Livewire::test('pages::donation-receipts.form', ['company' => $this->company])->assertOk();
});

test('the donation receipt pages are gated to registered charities', function () {
    $forProfit = Company::factory()->create(['organization_type' => 'corporation', 'address_country' => 'CA', 'features_fundraising' => true]);
    $forProfit->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $forProfit);

    Livewire::test('pages::donation-receipts.index', ['company' => $forProfit])->assertStatus(403);
    Livewire::test('pages::donation-receipts.form', ['company' => $forProfit])->assertStatus(403);
});

test('the form creates a draft donation receipt and redirects to it', function () {
    Livewire::test('pages::donation-receipts.form', ['company' => $this->company])
        ->set('gift_type', 'cash')
        ->set('gift_date', '2026-03-01')
        ->set('amount', '250.00')
        ->set('advantage', '50.00')
        ->set('advantage_description', 'Gala dinner')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $receipt = DonationReceipt::query()->latest('id')->first();
    expect($receipt)->not->toBeNull();
    expect($receipt->amount_cents)->toBe(25000);
    expect($receipt->advantage_cents)->toBe(5000);
    expect($receipt->eligible_amount_cents)->toBe(20000);
    expect($receipt->status)->toBe(DonationReceiptStatus::Draft);
});

test('the form rejects a zero gift amount', function () {
    Livewire::test('pages::donation-receipts.form', ['company' => $this->company])
        ->set('gift_date', '2026-03-01')
        ->set('amount', '0')
        ->call('save')
        ->assertHasErrors('amount');

    expect(DonationReceipt::query()->count())->toBe(0);
});

test('the show page issues a draft receipt and writes an audit log entry', function () {
    $receipt = DonationReceipt::factory()->create(['donor_name' => 'Jane Donor', 'amount_cents' => 10000, 'eligible_amount_cents' => 10000]);

    Livewire::test('pages::donation-receipts.show', ['company' => $this->company, 'donationReceipt' => $receipt])
        ->call('issue')
        ->assertHasNoErrors();

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Issued);
    expect(AccountingAuditLog::query()->where('action', 'donation_receipt.issued')->exists())->toBeTrue();
});

test('the show page surfaces a CRA validation failure as an error toast instead of issuing', function () {
    // Advantage equals the gift → eligible amount is zero → not issuable.
    $receipt = DonationReceipt::factory()->create([
        'amount_cents' => 10000,
        'advantage_cents' => 10000,
        'advantage_description' => 'Dinner',
        'eligible_amount_cents' => 0,
    ]);

    Livewire::test('pages::donation-receipts.show', ['company' => $this->company, 'donationReceipt' => $receipt])
        ->call('issue');

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Draft);
});

test('the show page voids an issued receipt with a reason', function () {
    $receipt = DonationReceipt::factory()->create(['amount_cents' => 10000, 'eligible_amount_cents' => 10000]);
    app(DonationReceiptIssuer::class)->issue($receipt);

    Livewire::test('pages::donation-receipts.show', ['company' => $this->company, 'donationReceipt' => $receipt->fresh()])
        ->set('voidReason', 'Donor requested correction')
        ->call('void')
        ->assertHasNoErrors();

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Void);
    expect($receipt->fresh()->void_reason)->toBe('Donor requested correction');
});

test('the show page reissues an issued receipt as a fresh draft', function () {
    $receipt = DonationReceipt::factory()->create(['amount_cents' => 10000, 'eligible_amount_cents' => 10000]);
    app(DonationReceiptIssuer::class)->issue($receipt);

    Livewire::test('pages::donation-receipts.show', ['company' => $this->company, 'donationReceipt' => $receipt->fresh()])
        ->call('reissue')
        ->assertRedirect();

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Void);
    expect(DonationReceipt::query()->where('reissued_from_id', $receipt->id)->where('status', 'draft')->exists())->toBeTrue();
});

test('the print route returns the receipt PDF for an issued receipt', function () {
    $receipt = DonationReceipt::factory()->create(['amount_cents' => 10000, 'eligible_amount_cents' => 10000]);
    app(DonationReceiptIssuer::class)->issue($receipt);

    $this->get(route('donation-receipts.print', ['company' => $this->company, 'donationReceipt' => $receipt->fresh()]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
