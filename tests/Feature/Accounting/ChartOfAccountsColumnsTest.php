<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\GridPreference;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('defaults to subtype and balance columns', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $bank->update(['description' => 'Primary operating chequing']);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->assertSet('visibleColumns', ['subtype', 'balance'])
        // The optional Description column stays hidden until opted in.
        ->assertDontSee('Primary operating chequing');
});

it('persists a column change to a grid preference row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('visibleColumns', ['description', 'balance']);

    $row = GridPreference::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('user_id', $user->id)
        ->where('grid_key', 'chart_of_accounts')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->visible_columns)->toBe(['description', 'balance']);
});

it('restores saved columns on remount and renders the opted-in column', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $bank->update(['description' => 'Primary operating chequing']);

    GridPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $user->id,
        'grid_key' => 'chart_of_accounts',
        'visible_columns' => ['description', 'balance'],
    ]);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->assertSet('visibleColumns', ['description', 'balance'])
        ->assertSee('Primary operating chequing');
});

it('drops unknown column keys from a stale saved preference', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    GridPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $user->id,
        'grid_key' => 'chart_of_accounts',
        'visible_columns' => ['balance', 'no_such_column'],
    ]);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->assertSet('visibleColumns', ['balance']);
});

// The dropdown always offers "Account ID (API)" as a checkbox, so these assert
// on the header/cell markers rather than the label text.
it('hides the Account ID column until it is opted in', function () {
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->assertDontSeeHtml('data-test="accounts-id-header"')
        ->assertDontSeeHtml('data-test="account-id-cell"')
        // The id a caller passes as `account_id` on /api/v1 (docs/api-v1.md).
        ->set('visibleColumns', ['subtype', 'balance', 'id'])
        ->assertSeeHtml('data-test="accounts-id-header"')
        ->assertSeeHtml('data-test="account-id-cell"');
});

it('renders each account\'s real id in the Account ID column', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    $html = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('visibleColumns', ['id'])
        ->html();

    expect($html)->toContain('data-test="account-id-cell">'.$bank->id.'</td>');
});

it('restores a saved Account ID column choice on remount', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    GridPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $user->id,
        'grid_key' => 'chart_of_accounts',
        'visible_columns' => ['balance', 'id'],
    ]);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->assertSet('visibleColumns', ['balance', 'id'])
        ->assertSeeHtml('data-test="accounts-id-header"');
});

it('keeps column choices isolated per user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    GridPreference::create([
        'company_id' => $this->company->id,
        'user_id' => $userA->id,
        'grid_key' => 'chart_of_accounts',
        'visible_columns' => ['description'],
    ]);

    $this->actingAs($userB);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->assertSet('visibleColumns', ['subtype', 'balance']);

    // And user B saving their own choice never touches user A's row.
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('visibleColumns', ['balance']);

    expect(GridPreference::withoutGlobalScopes()
        ->where('user_id', $userA->id)
        ->value('visible_columns'))->toBe(['description']);
});
