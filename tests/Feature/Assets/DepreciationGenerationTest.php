<?php

use App\Enums\AccountSubtype;
use App\Enums\AssetStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetDepreciationEntry;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Assets\DepreciationGenerator;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->accumDep = Account::query()
        ->where('subtype', AccountSubtype::FixedAsset->value)
        ->where('name', 'Accumulated Depreciation')
        ->firstOrFail();
    $this->depExpense = Account::query()
        ->where('subtype', AccountSubtype::Expense->value)
        ->orderBy('code')
        ->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

function makeDepreciableAsset(array $overrides = []): Asset
{
    // 360000¢ over 36 months → an even 10000¢ per month.
    return Asset::factory()->create(array_merge([
        'in_service_date' => '2026-04-10',
        'cost_cents' => 360000,
        'salvage_value_cents' => 0,
        'useful_life_months' => 36,
        'auto_depreciate' => true,
        'accumulated_depreciation_account_id' => test()->accumDep->id,
        'depreciation_expense_account_id' => test()->depExpense->id,
    ], $overrides));
}

/**
 * @return Collection<int, JournalEntry>
 */
function runDepreciation(): Collection
{
    return app(DepreciationGenerator::class)
        ->generateDue(test()->company, test()->company->currentDateTime()->startOfDay());
}

it('generates one balanced draft journal entry per completed month, never posting', function () {
    $asset = makeDepreciableAsset(); // in service April → only April has ended by May 24.

    $created = runDepreciation();

    expect($created)->toHaveCount(1);

    $entry = $created->first()->load('lines');

    expect($entry->is_posted)->toBeFalse()
        ->and($entry->source_type)->toBeNull()
        ->and($entry->entry_date->toDateString())->toBe('2026-04-30')
        ->and($entry->memo)->toBe('Monthly depreciation — April 2026')
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->lines)->toHaveCount(2);

    $debit = $entry->lines->firstWhere('debit_cents', '>', 0);
    $credit = $entry->lines->firstWhere('credit_cents', '>', 0);

    expect($debit->account_id)->toBe($this->depExpense->id)
        ->and((int) $debit->debit_cents)->toBe(10000)
        ->and($debit->memo)->toBe($asset->asset_no.' — '.$asset->name)
        ->and($credit->account_id)->toBe($this->accumDep->id)
        ->and((int) $credit->credit_cents)->toBe(10000)
        ->and($credit->memo)->toBe($asset->asset_no.' — '.$asset->name);

    $pivots = AssetDepreciationEntry::query()->where('asset_id', $asset->id)->get();

    expect($pivots)->toHaveCount(1)
        ->and($pivots->first()->period->toDateString())->toBe('2026-04-01')
        ->and($pivots->first()->amount_cents)->toBe(10000)
        ->and($pivots->first()->journal_entry_id)->toBe($entry->id);
});

it('catches up every completed month since the in-service date', function () {
    makeDepreciableAsset(['in_service_date' => '2026-02-01']);

    $created = runDepreciation();

    expect($created)->toHaveCount(3)
        ->and($created->map(fn (JournalEntry $e) => $e->entry_date->toDateString())->all())
        ->toBe(['2026-02-28', '2026-03-31', '2026-04-30']);
});

it('excludes the current, incomplete month', function () {
    makeDepreciableAsset(['in_service_date' => '2026-05-01']);

    expect(runDepreciation())->toHaveCount(0)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(AssetDepreciationEntry::query()->count())->toBe(0);
});

it('is idempotent — a second run generates nothing new', function () {
    makeDepreciableAsset(['in_service_date' => '2026-02-01']);

    expect(runDepreciation())->toHaveCount(3);
    expect(runDepreciation())->toHaveCount(0);

    expect(JournalEntry::query()->count())->toBe(3)
        ->and(AssetDepreciationEntry::query()->count())->toBe(3);
});

it('permanently skips months ending on or before the lock date', function () {
    $this->company->update(['lock_date' => '2026-02-28']);
    makeDepreciableAsset(['in_service_date' => '2026-01-15']);

    $created = runDepreciation();

    expect($created)->toHaveCount(2)
        ->and($created->map(fn (JournalEntry $e) => $e->entry_date->toDateString())->all())
        ->toBe(['2026-03-31', '2026-04-30']);

    // Locked months get no pivot rows and never come back.
    expect(AssetDepreciationEntry::query()->orderBy('period')->get()
        ->map(fn (AssetDepreciationEntry $row) => $row->period->toDateString())->all())
        ->toBe(['2026-03-01', '2026-04-01']);
    expect(runDepreciation())->toHaveCount(0);
});

it('stops generating from the disposal month onward', function () {
    makeDepreciableAsset([
        'in_service_date' => '2026-01-15',
        'status' => AssetStatus::Disposed->value,
        'disposed_at' => '2026-03-10',
    ]);

    $created = runDepreciation();

    expect($created)->toHaveCount(2)
        ->and($created->map(fn (JournalEntry $e) => $e->entry_date->toDateString())->all())
        ->toBe(['2026-01-31', '2026-02-28']);
});

it('regenerates a month after its draft entry is deleted', function () {
    makeDepreciableAsset();
    $entry = runDepreciation()->first();

    $entry->lines()->delete();
    $entry->delete();

    // The pivot rows cascade away with the entry, re-opening the month.
    expect(AssetDepreciationEntry::query()->count())->toBe(0);

    $regenerated = runDepreciation();

    expect($regenerated)->toHaveCount(1)
        ->and($regenerated->first()->entry_date->toDateString())->toBe('2026-04-30');
    expect(AssetDepreciationEntry::query()->count())->toBe(1);
});

it('does not regenerate a voided month', function () {
    makeDepreciableAsset();
    $entry = runDepreciation()->first();

    $poster = app(JournalPoster::class);
    $entry = $poster->post($entry);
    $poster->void($entry);

    expect($entry->fresh()->isVoided())->toBeTrue()
        ->and(AssetDepreciationEntry::query()->count())->toBe(1);

    $countBefore = JournalEntry::query()->count(); // original + reversal

    expect(runDepreciation())->toHaveCount(0)
        ->and(JournalEntry::query()->count())->toBe($countBefore);
});

it('generates nothing when auto_depreciate is off', function () {
    makeDepreciableAsset(['auto_depreciate' => false]);

    expect(runDepreciation())->toHaveCount(0)
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('bundles multiple assets due in the same month into one entry', function () {
    makeDepreciableAsset();
    makeDepreciableAsset(['in_service_date' => '2026-04-20', 'cost_cents' => 180000]); // 5000¢/month

    $created = runDepreciation();

    expect($created)->toHaveCount(1);

    $entry = $created->first()->load('lines');

    expect($entry->lines)->toHaveCount(4)
        ->and($entry->totalDebitsCents())->toBe(15000)
        ->and($entry->isBalanced())->toBeTrue();

    expect(AssetDepreciationEntry::query()->where('journal_entry_id', $entry->id)->count())->toBe(2);
});

it('generates via the artisan command with --sync', function () {
    makeDepreciableAsset();

    $this->artisan('depreciation:generate', ['company' => $this->company->id, '--sync' => true])
        ->assertExitCode(0);

    expect(JournalEntry::query()->where('memo', 'Monthly depreciation — April 2026')->count())->toBe(1);
});
