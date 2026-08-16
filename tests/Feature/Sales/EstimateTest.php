<?php

use App\Actions\Sales\SaveEstimate;
use App\Enums\AccountSubtype;
use App\Enums\EstimateStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Services\Posting\TaxCalculator;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Acme Corp',
        'is_customer' => true,
    ]);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @param  array<int, array<string, mixed>>|null  $lines
 */
function estimateData(array $overrides = [], ?array $lines = null): array
{
    return array_merge([
        'contact_id' => test()->customer->id,
        'estimate_no' => null,
        'estimate_date' => test()->company->currentDateTime()->toDateString(),
        'expires_on' => null,
        'terms_id' => null,
        'memo' => null,
        'customer_message' => null,
        'lines' => $lines ?? [[
            'item_id' => null,
            'account_id' => test()->incomeAccount->id,
            'description' => 'Consulting',
            'quantity' => '2',
            'unit_price_cents' => 5000,
            'tax_code_id' => null,
        ]],
    ], $overrides);
}

it('creates an estimate with an auto number and correct totals', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateData());

    expect($estimate->estimate_no)->toBe('EST-000001');
    expect($estimate->status)->toBe(EstimateStatus::Pending);
    expect($estimate->subtotal_cents)->toBe(10000);
    expect($estimate->tax_cents)->toBe(0);
    expect($estimate->total_cents)->toBe(10000);
    expect($estimate->lines)->toHaveCount(1);
});

it('rolls tax into the estimate totals', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $calc = app(TaxCalculator::class);
    $expected = $calc->line('1', 10000, $gst);

    $estimate = app(SaveEstimate::class)->handle(estimateData(lines: [[
        'item_id' => null,
        'account_id' => $this->incomeAccount->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $gst->id,
    ]]));

    expect($estimate->subtotal_cents)->toBe($expected['subtotal_cents']);
    expect($estimate->tax_cents)->toBe($expected['tax_cents']);
    expect($estimate->total_cents)->toBe($expected['subtotal_cents'] + $expected['tax_cents']);
});

it('updates an estimate in place without changing its status', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateData());
    $estimate->update(['status' => EstimateStatus::Accepted]);

    $updated = app(SaveEstimate::class)->handle(estimateData([
        'estimate_no' => $estimate->estimate_no,
        'memo' => 'Revised',
    ], lines: [[
        'item_id' => null,
        'account_id' => $this->incomeAccount->id,
        'description' => 'Revised line',
        'quantity' => '3',
        'unit_price_cents' => 5000,
        'tax_code_id' => null,
    ]]), $estimate);

    expect($updated->id)->toBe($estimate->id);
    expect($updated->status)->toBe(EstimateStatus::Accepted);
    expect($updated->memo)->toBe('Revised');
    expect($updated->lines)->toHaveCount(1);
    expect($updated->total_cents)->toBe(15000);
});

it('never posts to the general ledger', function () {
    $before = JournalEntry::count();

    app(SaveEstimate::class)->handle(estimateData());

    expect(JournalEntry::count())->toBe($before);
});

it('accepts and rejects an estimate via the show page', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateData());

    Livewire::test('pages::estimates.show', ['company' => $this->company, 'estimate' => $estimate])
        ->call('accept');

    expect($estimate->fresh()->status)->toBe(EstimateStatus::Accepted);

    Livewire::test('pages::estimates.show', ['company' => $this->company, 'estimate' => $estimate->fresh()])
        ->call('reject');

    expect($estimate->fresh()->status)->toBe(EstimateStatus::Rejected);
});

it('derives Expired for a pending estimate past its expiry date', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateData([
        'expires_on' => $this->company->currentDateTime()->subDay()->toDateString(),
    ]));

    expect($estimate->isExpired())->toBeTrue();
    expect($estimate->effectiveStatus())->toBe(EstimateStatus::Expired);
    expect($estimate->effectiveStatus()->canConvert())->toBeFalse();
});

it('does not expire an accepted estimate past its date', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateData([
        'expires_on' => $this->company->currentDateTime()->subDay()->toDateString(),
    ]));
    $estimate->update(['status' => EstimateStatus::Accepted]);

    expect($estimate->fresh()->isExpired())->toBeFalse();
    expect($estimate->fresh()->effectiveStatus())->toBe(EstimateStatus::Accepted);
});

it('does not expire a pending estimate with a future expiry date', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateData([
        'expires_on' => $this->company->currentDateTime()->addDay()->toDateString(),
    ]));

    expect($estimate->isExpired())->toBeFalse();
    expect($estimate->effectiveStatus())->toBe(EstimateStatus::Pending);
});

it('blocks editing a converted estimate', function () {
    $estimate = app(SaveEstimate::class)->handle(estimateData());
    $estimate->update(['status' => EstimateStatus::Converted]);

    Livewire::test('pages::estimates.form', ['company' => $this->company, 'estimate' => $estimate])
        ->assertStatus(403);
});

it('renders the index listing an estimate', function () {
    app(SaveEstimate::class)->handle(estimateData());

    Livewire::test('pages::estimates.index', ['company' => $this->company])
        ->assertOk()
        ->assertSee('EST-000001')
        ->assertSee('Acme Corp');
});

it('renders the create form with a prefilled number', function () {
    Livewire::test('pages::estimates.form', ['company' => $this->company])
        ->assertOk()
        ->assertSet('estimate_no', 'EST-000001')
        ->assertSee('New estimate');
});
