<?php

use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Actions\Fundraising\SaveDonation;
use App\Actions\Fundraising\SaveGrant;
use App\Actions\Inventory\SaveStockAdjustment;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\DonationReceiptStatus;
use App\Enums\TaxReturnPaymentDirection;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Grant;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\StockAdjustment;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Models\User;
use App\Services\Deletion\TransactionDeleter;
use App\Services\Fundraising\DonationPoster;
use App\Services\Fundraising\GrantPoster;
use App\Services\Fundraising\RecognizeDeferredContribution;
use App\Services\Posting\StockAdjustmentPoster;
use App\Services\Posting\TaxReturnPaymentPoster;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'address_country' => 'CA', 'organization_type' => 'non_profit', 'features_fundraising' => true, 'fiscal_year_start_month' => 1,
    ]);
    app()->instance('current_company', $this->company);
    app(EnsureFundraisingAccounts::class)->handle($this->company);
    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->owner);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->revenue = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();
    $this->grantRevenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Grant%')->first() ?? $this->revenue;
    $this->deferred = Account::query()->where('name', 'like', '%Deferred%')->firstOrFail();
    $this->deleter = app(TransactionDeleter::class);
});

function pr4Donation(): Donation
{
    $donation = app(SaveDonation::class)->handle([
        'donation_date' => now()->toDateString(), 'amount_cents' => 20000,
        'deposit_to_account_id' => test()->bank->id, 'revenue_account_id' => test()->revenue->id, 'is_restricted' => false,
    ]);
    app(DonationPoster::class)->post($donation);

    return $donation->fresh();
}

function pr4Grant(): Grant
{
    $grant = app(SaveGrant::class)->handle([
        'name' => 'Operating Grant', 'award_amount_cents' => 120000, 'is_restricted' => true,
        'deposit_to_account_id' => test()->bank->id, 'deferred_account_id' => test()->deferred->id, 'revenue_account_id' => test()->grantRevenue->id,
        'period_start' => now()->startOfYear()->toDateString(), 'period_end' => now()->endOfYear()->toDateString(),
    ]);
    app(GrantPoster::class)->postAward($grant);

    return $grant->fresh();
}

function pr4Adjustment(Item $item, int $qty, int $cost): StockAdjustment
{
    $adjustment = app(SaveStockAdjustment::class)->handle([
        'adjustment_date' => now()->toDateString(), 'reason' => 'opening_balance', 'notes' => 'Count',
        'lines' => [['item_id' => $item->id, 'qty_change' => $qty, 'unit_cost_cents' => $cost]],
    ]);
    app(StockAdjustmentPoster::class)->post($adjustment);

    return $adjustment->fresh();
}

it('deletes a donation and puts the ledger back', function () {
    $before = deletionLedgerState($this->company);
    $donation = pr4Donation();

    $this->deleter->delete($donation);

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and(Donation::withTrashed()->whereKey($donation->id)->exists())->toBeFalse();
});

it('refuses to delete a donation an official receipt was issued for, and takes a draft receipt with it', function () {
    $donation = pr4Donation();
    $receipt = DonationReceipt::factory()->create([
        'company_id' => $this->company->id, 'donation_id' => $donation->id, 'status' => DonationReceiptStatus::Issued,
    ]);

    expect(fn () => $this->deleter->delete($donation->fresh()))->toThrow(DeletionBlockedException::class);

    $receipt->forceFill(['status' => DonationReceiptStatus::Draft])->save();
    $this->deleter->delete($donation->fresh());

    expect(DonationReceipt::withTrashed()->whereKey($receipt->id)->exists())->toBeFalse();
});

it('deletes a grant with its award and every recognition entry', function () {
    $before = deletionLedgerState($this->company);
    $grant = pr4Grant();
    app(RecognizeDeferredContribution::class)->recognize($grant->fresh(), 30000, now()->toDateString());
    app(RecognizeDeferredContribution::class)->recognize($grant->fresh(), 20000, now()->toDateString());
    $entryIds = JournalEntry::query()->where('source_type', Grant::class)->where('source_id', $grant->id)->pluck('id')->all();

    expect($entryIds)->toHaveCount(3);

    $record = $this->deleter->delete($grant->fresh());

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and(JournalEntry::query()->whereKey($entryIds)->exists())->toBeFalse()
        ->and($record->snapshot['journal_entries'])->toHaveCount(3)
        ->and(Artisan::call('audit:verify', ['company' => $this->company->id, '--full' => true, '--no-alert' => true]))->toBe(0);
});

it('deletes a stock adjustment while nothing has moved since, and refuses once something has', function () {
    $item = Item::factory()->tracked()->create(['company_id' => $this->company->id, 'name' => 'Widget']);
    $before = deletionLedgerState($this->company);
    $first = pr4Adjustment($item, 4, 1000);

    $this->deleter->delete($first);

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and((float) $item->fresh()->qty_on_hand_cached)->toBe(0.0);

    $kept = pr4Adjustment($item, 4, 1000);
    pr4Adjustment($item, 2, 1200);

    expect(fn () => $this->deleter->delete($kept->fresh()))->toThrow(DeletionBlockedException::class);
});

it('deletes a tax payment made after its return was filed', function () {
    $return = TaxReturn::create([
        'tax_agency_id' => TaxAgency::query()->value('id'), 'tax_return_no' => 'TR-1', 'status' => 'filed',
        'period_start' => now()->subQuarter()->startOfQuarter()->toDateString(), 'period_end' => now()->subQuarter()->endOfQuarter()->toDateString(),
    ]);
    $payment = TaxReturnPayment::create([
        'tax_return_id' => $return->id, 'payment_no' => 'TRP-1', 'payment_date' => now()->toDateString(),
        'direction' => TaxReturnPaymentDirection::Outgoing->value, 'bank_account_id' => $this->bank->id, 'net_amount_cents' => 500,
    ]);
    $before = deletionLedgerState($this->company);
    app(TaxReturnPaymentPoster::class)->post($payment);

    $this->deleter->delete($payment->fresh());

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and(TaxReturnPayment::withTrashed()->whereKey($payment->id)->exists())->toBeFalse();
});

it('deletes from the donation, grant and stock adjustment pages', function () {
    $donation = pr4Donation();

    Livewire::actingAs($this->owner)
        ->test('pages::donations.show', ['company' => $this->company, 'donation' => $donation])
        ->assertSeeHtml('data-test="delete-transaction-button"')
        ->call('confirmDelete')
        ->assertRedirect(route('donations.index', ['company' => $this->company->slug]));

    $grant = pr4Grant();

    Livewire::actingAs($this->owner)
        ->test('pages::grants.show', ['company' => $this->company, 'grant' => $grant])
        ->call('confirmDelete')
        ->assertRedirect(route('grants.index', ['company' => $this->company->slug]));

    $item = Item::factory()->tracked()->create(['company_id' => $this->company->id, 'name' => 'Gadget']);
    $adjustment = pr4Adjustment($item, 3, 500);

    Livewire::actingAs($this->owner)
        ->test('pages::inventory.adjustments', ['company' => $this->company])
        ->assertSeeHtml('data-test="delete-adjustment-button"')
        ->call('prepareDeleteAdjustment', $adjustment->id)
        ->assertSet('deletingAdjustmentId', $adjustment->id)
        ->call('confirmDelete');

    expect(Donation::withTrashed()->whereKey($donation->id)->exists())->toBeFalse()
        ->and(Grant::withTrashed()->whereKey($grant->id)->exists())->toBeFalse()
        ->and(StockAdjustment::withTrashed()->whereKey($adjustment->id)->exists())->toBeFalse();
});
