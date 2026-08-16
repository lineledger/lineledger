<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Posting\JournalPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeAccount(string $code, string $name, AccountSubtype $subtype, array $overrides = []): Account
{
    return Account::create(array_merge([
        'code' => $code,
        'name' => $name,
        'subtype' => $subtype,
        'type' => $subtype->type(),
        'normal_balance' => $subtype->type()->normalBalance(),
    ], $overrides));
}

function postHierarchyEntry(Company $company, array $lines, string $date = '2026-06-01'): JournalEntry
{
    $entry = JournalEntry::create(['entry_no' => 'JE-'.uniqid(), 'entry_date' => $date]);

    foreach ($lines as $i => [$accountId, $debit, $credit]) {
        $entry->lines()->create([
            'account_id' => $accountId,
            'debit_cents' => $debit,
            'credit_cents' => $credit,
            'line_order' => $i,
        ]);
    }

    return app(JournalPoster::class)->post($entry);
}

it('renders children indented directly beneath their parent, before later roots', function () {
    $parent = makeAccount('1700', 'Vehicles Fleet', AccountSubtype::FixedAsset);
    makeAccount('1790', 'Fleet Trailers', AccountSubtype::FixedAsset, ['parent_id' => $parent->id]);
    makeAccount('1750', 'Standalone Machinery', AccountSubtype::FixedAsset);

    // Tree order: parent (1700), its child (1790), then the next root (1750)
    // would sort between them by raw code — the child still wins its spot.
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->assertSeeInOrder(['Vehicles Fleet', 'Fleet Trailers', 'Standalone Machinery']);
});

it('walks grandchildren at increasing depth', function () {
    $parent = makeAccount('1700', 'Vehicles Fleet', AccountSubtype::FixedAsset);
    $child = makeAccount('1710', 'Fleet Vans', AccountSubtype::FixedAsset, ['parent_id' => $parent->id]);
    $grandchild = makeAccount('1711', 'Fleet Van Tooling', AccountSubtype::FixedAsset, ['parent_id' => $child->id]);

    $rows = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->instance()
        ->treeRows
        ->flatten(1);

    $depths = $rows
        ->keyBy(fn (array $row) => $row['account']->id)
        ->map(fn (array $row) => $row['depth']);

    expect($depths[$parent->id])->toBe(0)
        ->and($depths[$child->id])->toBe(1)
        ->and($depths[$grandchild->id])->toBe(2);
});

it('rolls up a parent balance including posted activity on visible descendants', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $parent = makeAccount('1050', 'Cash Floats', AccountSubtype::Bank);
    $child = makeAccount('1060', 'Register Float', AccountSubtype::Bank, ['parent_id' => $parent->id]);

    postHierarchyEntry($this->company, [
        [$parent->id, 2000, 0],
        [$child->id, 5000, 0],
        [$income->id, 0, 7000],
    ]);

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company]);

    // Own balance + visible descendants: 20.00 + 50.00.
    expect($component->instance()->rollups[$parent->id])->toBe(7000);
    // Leaf accounts get no roll-up entry.
    expect($component->instance()->rollups)->not->toHaveKey($child->id);

    $component
        ->assertSeeHtml('data-test="account-rollup-balance"')
        ->assertSee('70.00')
        ->assertSee('incl. sub-accounts');
});

it('renders flat without roll-ups while searching', function () {
    $parent = makeAccount('1050', 'Cash Floats', AccountSubtype::Bank);
    $child = makeAccount('1060', 'Register Float', AccountSubtype::Bank, ['parent_id' => $parent->id]);

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('search', 'Float');

    $rows = $component->instance()->treeRows->flatten(1);

    expect($rows->pluck('depth')->unique()->all())->toBe([0]);
    expect($rows->pluck('account.id'))->toContain($child->id);

    $component->assertDontSeeHtml('data-test="account-rollup-balance"');
});

it('surfaces the child of a hidden inactive parent as a root', function () {
    $parent = makeAccount('1050', 'Cash Floats', AccountSubtype::Bank, ['is_active' => false]);
    $child = makeAccount('1060', 'Register Float', AccountSubtype::Bank, ['parent_id' => $parent->id]);

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company]);

    $rows = $component->instance()->treeRows->flatten(1)->keyBy(fn (array $row) => $row['account']->id);

    expect($rows)->not->toHaveKey($parent->id);
    expect($rows[$child->id]['depth'])->toBe(0);

    $component->assertSee('Register Float');
});
