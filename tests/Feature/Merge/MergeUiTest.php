<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function mergeUiExpense(string $code, string $name, array $overrides = []): Account
{
    return Account::create([
        'code' => $code,
        'name' => $name,
        'subtype' => AccountSubtype::Expense,
        'type' => AccountSubtype::Expense->type(),
        'normal_balance' => AccountSubtype::Expense->type()->normalBalance(),
        'is_active' => true,
        ...$overrides,
    ]);
}

it('merges an account from the chart of accounts page', function () {
    $loser = mergeUiExpense('6101', 'Software (dup)');
    $survivor = mergeUiExpense('6201', 'Software');

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openMerge', $loser->id)
        ->set('mergeTargetId', $survivor->id)
        ->set('mergeConfirmed', true)
        ->call('merge')
        ->assertHasNoErrors();

    expect(Account::withTrashed()->find($loser->id)->trashed())->toBeTrue()
        ->and(Account::find($survivor->id))->not->toBeNull();
});

it('excludes wrong-subtype accounts and descendants from merge targets', function () {
    $loser = mergeUiExpense('6101', 'Software (dup)');
    $survivor = mergeUiExpense('6201', 'Software');
    $child = mergeUiExpense('6102', 'Software child', ['parent_id' => $loser->id]);
    $grandchild = mergeUiExpense('6103', 'Software grandchild', ['parent_id' => $child->id]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openMerge', $loser->id);

    $values = collect($component->instance()->mergeTargets)->pluck('value');

    expect($values)->toContain($survivor->id)
        ->not->toContain($loser->id)
        ->not->toContain($child->id)
        ->not->toContain($grandchild->id)
        ->not->toContain($income->id);
});

it('surfaces a merge guard failure as an error on the target field', function () {
    $loser = mergeUiExpense('6101', 'Software (dup)');
    $inactive = mergeUiExpense('6201', 'Software (inactive)', ['is_active' => false]);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openMerge', $loser->id)
        ->set('mergeTargetId', $inactive->id)
        ->set('mergeConfirmed', true)
        ->call('merge')
        ->assertHasErrors(['mergeTargetId']);

    expect(Account::withTrashed()->find($loser->id)->trashed())->toBeFalse();
});

it('requires the confirmation checkbox before merging', function () {
    $loser = mergeUiExpense('6101', 'Software (dup)');
    $survivor = mergeUiExpense('6201', 'Software');

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openMerge', $loser->id)
        ->set('mergeTargetId', $survivor->id)
        ->call('merge')
        ->assertHasErrors(['mergeConfirmed']);

    expect(Account::withTrashed()->find($loser->id)->trashed())->toBeFalse();
});

it('merges a customer from the customers page', function () {
    $loser = Contact::factory()->customer()->create(['display_name' => 'Jane D. (dup)']);
    $survivor = Contact::factory()->customer()->create(['display_name' => 'Jane Doe']);

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openMerge', $loser->id)
        ->set('mergeTargetId', $survivor->id)
        ->set('mergeConfirmed', true)
        ->call('merge')
        ->assertHasNoErrors();

    expect(Contact::withTrashed()->find($loser->id)->trashed())->toBeTrue()
        ->and(Contact::find($survivor->id)->is_customer)->toBeTrue();
});

it('merges a vendor from the vendors page', function () {
    $loser = Contact::factory()->vendor()->create(['display_name' => 'Hydro One (dup)']);
    $survivor = Contact::factory()->vendor()->create(['display_name' => 'Hydro One']);

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('openMerge', $loser->id)
        ->set('mergeTargetId', $survivor->id)
        ->set('mergeConfirmed', true)
        ->call('merge')
        ->assertHasNoErrors();

    expect(Contact::withTrashed()->find($loser->id)->trashed())->toBeTrue()
        ->and(Contact::find($survivor->id)->is_vendor)->toBeTrue();
});
