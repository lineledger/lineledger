<?php

use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Actions\Fundraising\SaveDonation;
use App\Actions\Fundraising\SaveGrant;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\DonationReceiptStatus;
use App\Enums\DonationStatus;
use App\Enums\GrantStatus;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Account;
use App\Models\Company;
use App\Models\DonationReceipt;
use App\Models\EditLock;
use App\Services\EditLocks\EditLockManager;
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
    app(EnsureFundraisingAccounts::class)->handle($this->company);

    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->locks = app(EditLockManager::class);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $deferred = Account::query()->where('type', AccountType::Liability->value)->where('name', 'like', '%Deferred%')->firstOrFail();

    $this->donation = app(SaveDonation::class)->handle([
        'donation_date' => '2026-03-01',
        'amount_cents' => 20000,
        'deposit_to_account_id' => $bank->id,
        'is_restricted' => false,
        'notes' => 'Original notes',
    ]);

    $this->grant = app(SaveGrant::class)->handle([
        'name' => 'Operating Grant',
        'award_amount_cents' => 120000,
        'is_restricted' => true,
        'deposit_to_account_id' => $bank->id,
        'deferred_account_id' => $deferred->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
        'notes' => 'Original notes',
    ]);

    $this->receipt = DonationReceipt::factory()->create([
        'amount_cents' => 10000,
        'eligible_amount_cents' => 10000,
        'notes' => 'Original notes',
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

dataset('charity edit forms', [
    'donation' => ['pages::donations.form', 'donation', 'donation', 'amount'],
    'donation receipt' => ['pages::donation-receipts.form', 'donationReceipt', 'receipt', 'amount'],
    'grant' => ['pages::grants.form', 'grant', 'grant', 'award'],
]);

it('takes the lock when a draft is opened for editing', function (string $component, string $param, string $record, string $amountField) {
    $this->actingAs($this->jane);

    $form = Livewire::test($component, ['company' => $this->company, $param => $this->{$record}])
        ->assertSet('editLockBlocked', false);

    expect($form->get('editLockToken'))->toHaveLength(40)
        ->and(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
})->with('charity edit forms');

it('blocks a second member and refuses their crafted save', function (string $component, string $param, string $record, string $amountField) {
    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company, $param => $this->{$record}]);

    $before = $this->{$record}->fresh()->getAttributes();

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $this->{$record}])
        ->assertSet('editLockBlocked', true)
        ->assertSee($this->jane->name)
        ->assertDontSee(__('Save draft'))
        ->set($amountField, '100.00')
        ->set('notes', 'Bob was here')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', 'Someone else is editing this'))
        ->assertNoRedirect();

    expect($this->{$record}->fresh()->getAttributes())->toEqual($before);
})->with('charity edit forms');

it('lets the member holding the lock save the draft', function (string $component, string $param, string $record, string $amountField) {
    $this->actingAs($this->jane);

    Livewire::test($component, ['company' => $this->company, $param => $this->{$record}])
        // The forms prefill the amount as formatted currency, which their own
        // parser rejects, so the amount is typed again as a user would.
        ->set($amountField, '100.00')
        ->set('notes', 'Updated by Jane')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($this->{$record}->fresh()->notes)->toBe('Updated by Jane');
})->with('charity edit forms');

it('refuses a show-page action while another member is editing', function (string $component, string $param, string $record, string $method, BackedEnum $unchanged) {
    $this->locks->acquire($this->{$record}, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $this->{$record}->fresh()])
        ->assertSee($this->jane->name)
        ->call($method)
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', $this->jane->name));

    expect($this->{$record}->fresh()->status)->toBe($unchanged);
})->with([
    'donation post' => ['pages::donations.show', 'donation', 'donation', 'post', DonationStatus::Draft],
    'donation receipt issue' => ['pages::donation-receipts.show', 'donationReceipt', 'receipt', 'issue', DonationReceiptStatus::Draft],
    'grant award' => ['pages::grants.show', 'grant', 'grant', 'postAward', GrantStatus::Draft],
]);

it('lets the member holding the lock post the donation from its show page', function () {
    $held = $this->locks->acquire($this->donation, $this->jane);

    $this->actingAs($this->jane);
    Livewire::test('pages::donations.show', ['company' => $this->company, 'donation' => $this->donation->fresh()])
        ->call('post');

    expect($this->donation->fresh()->status)->toBe(DonationStatus::Posted)
        ->and($this->locks->currentVersion($this->donation))->not->toBe($held->version);
});

it('guards exactly the charity show page methods that change the record', function (string $component, array $methods) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    $guarded = collect((new ReflectionClass($class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe($methods);
})->with([
    'donations' => ['pages::donations.show', ['confirmDelete', 'post', 'void']],
    'donation receipts' => ['pages::donation-receipts.show', ['issue', 'reissue', 'void']],
    'grants' => ['pages::grants.show', ['confirmDelete', 'postAward', 'recognize', 'void']],
]);
