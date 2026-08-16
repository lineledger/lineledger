<?php

use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\FundType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postedEntry(): JournalEntry
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-000001',
        'entry_date' => '2026-03-31',
        'memo' => 'Accrual',
    ]);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 10000, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 10000, 'line_order' => 1]);

    return app(JournalPoster::class)->post($entry);
}

it('creates a balanced draft reversal with swapped debits and credits', function () {
    $original = postedEntry();

    $reversal = app(ReverseJournalEntry::class)->handle($original, CarbonImmutable::parse('2026-04-01'));

    expect($reversal->is_posted)->toBeFalse()
        ->and($reversal->entry_date->toDateString())->toBe('2026-04-01')
        ->and($reversal->reverses_entry_id)->toBe($original->id)
        ->and($reversal->isBalanced())->toBeTrue();

    $originalLines = $original->fresh()->lines->keyBy('account_id');
    foreach ($reversal->lines as $line) {
        expect($line->debit_cents)->toBe($originalLines[$line->account_id]->credit_cents)
            ->and($line->credit_cents)->toBe($originalLines[$line->account_id]->debit_cents);
    }
});

it('leaves the original posted and not voided', function () {
    $original = postedEntry();

    app(ReverseJournalEntry::class)->handle($original, CarbonImmutable::parse('2026-04-01'));

    expect($original->fresh()->is_posted)->toBeTrue()
        ->and($original->fresh()->isVoided())->toBeFalse();
});

it('stamps the original reversed_by_entry_id only once the reversal is posted', function () {
    $original = postedEntry();

    $reversal = app(ReverseJournalEntry::class)->handle($original, CarbonImmutable::parse('2026-04-01'));

    // Draft reversal: original not yet linked.
    expect($original->fresh()->reversed_by_entry_id)->toBeNull();

    app(JournalPoster::class)->post($reversal);

    expect($original->fresh()->reversed_by_entry_id)->toBe($reversal->id);
});

it('offers Reverse only for posted, non-voided entries on the show page', function () {
    $owner = User::factory()->create();
    $this->company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($owner);

    $posted = postedEntry();

    $draft = JournalEntry::create(['entry_no' => 'JE-000002', 'entry_date' => '2026-03-31']);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $draft->lines()->create(['account_id' => $bank->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'line_order' => 0]);
    $draft->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'line_order' => 1]);

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $posted])
        ->assertSeeHtml('data-test="reverse-entry-button"');

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $draft])
        ->assertDontSeeHtml('data-test="reverse-entry-button"');
});

it('creates the reversal through the show page action', function () {
    $owner = User::factory()->create();
    $this->company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($owner);

    $original = postedEntry();

    Livewire::test('pages::journal.show', ['company' => $this->company, 'entry' => $original])
        ->assertSet('reverseDate', '2026-04-01')
        ->set('reverseDate', '2026-04-15')
        ->call('reverse')
        ->assertHasNoErrors();

    $reversal = JournalEntry::query()->where('reverses_entry_id', $original->id)->firstOrFail();
    expect($reversal->is_posted)->toBeFalse()
        ->and($reversal->entry_date->toDateString())->toBe('2026-04-15');
});

it('preserves the fund dimension on the reversal lines', function () {
    // Regression guard: reversal copied class_id/location_id but dropped fund_id,
    // corrupting the nonprofit fund dimension on the reversing entry.
    $fund = Fund::create(['name' => 'Building Fund', 'fund_type' => FundType::Restricted, 'is_default' => false, 'is_active' => true]);

    $entry = JournalEntry::create(['entry_no' => 'JE-FUND', 'entry_date' => '2026-03-31', 'memo' => 'Restricted gift']);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 10000, 'credit_cents' => 0, 'line_order' => 0, 'fund_id' => $fund->id]);
    $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 10000, 'line_order' => 1, 'fund_id' => $fund->id]);
    $original = app(JournalPoster::class)->post($entry);

    $reversal = app(ReverseJournalEntry::class)->handle($original, CarbonImmutable::parse('2026-04-01'));

    expect($reversal->lines)->toHaveCount(2)
        ->and($reversal->lines->pluck('fund_id')->unique()->values()->all())->toBe([$fund->id]);
});
