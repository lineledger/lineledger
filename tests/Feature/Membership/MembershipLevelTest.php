<?php

use App\Actions\MasterData\SaveMembershipLevel;
use App\Enums\CompanyRole;
use App\Enums\RecurrenceFrequency;
use App\Models\Company;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Services\Backup\BackupTableRegistry;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['features_membership' => true]);
    app()->instance('current_company', $this->company);

    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
});

afterEach(fn () => app()->forgetInstance('current_company'));

test('SaveMembershipLevel creates a level with defaults', function () {
    $level = app(SaveMembershipLevel::class)->handle([
        'name' => 'Individual',
        'default_dues_cents' => 5000,
    ]);

    expect($level->name)->toBe('Individual');
    expect($level->default_dues_cents)->toBe(5000);
    expect($level->billing_frequency)->toBe(RecurrenceFrequency::Annual);
    expect($level->is_active)->toBeTrue();
    expect($level->company_id)->toBe($this->company->id);
});

test('SaveMembershipLevel updates an existing level', function () {
    $level = MembershipLevel::factory()->create(['name' => 'Individual', 'default_dues_cents' => 5000]);

    app(SaveMembershipLevel::class)->handle([
        'name' => 'Individual',
        'default_dues_cents' => 7500,
        'billing_frequency' => RecurrenceFrequency::Monthly->value,
    ], $level);

    expect($level->fresh()->default_dues_cents)->toBe(7500);
    expect($level->fresh()->billing_frequency)->toBe(RecurrenceFrequency::Monthly);
});

test('membership level names are unique per company', function () {
    MembershipLevel::factory()->create(['name' => 'Family']);

    MembershipLevel::factory()->create(['name' => 'Family']);
})->throws(QueryException::class);

test('the membership levels settings page renders when the feature is on', function () {
    Livewire::test('pages::settings.lists.membership-levels', ['company' => $this->company])->assertOk();
});

test('the membership levels settings page is gated on the feature flag', function () {
    $off = Company::factory()->create(['features_membership' => false]);
    $off->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $off);

    Livewire::test('pages::settings.lists.membership-levels', ['company' => $off])->assertStatus(403);
});

test('the settings page creates a membership level via the modal', function () {
    Livewire::test('pages::settings.lists.membership-levels', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Corporate')
        ->set('f_dues', '250.00')
        ->set('f_billing_frequency', RecurrenceFrequency::Annual->value)
        ->call('save')
        ->assertHasNoErrors();

    $level = MembershipLevel::query()->where('name', 'Corporate')->first();
    expect($level)->not->toBeNull();
    expect($level->default_dues_cents)->toBe(25000);
});

test('membership_levels is registered for backup', function () {
    $tables = array_column(BackupTableRegistry::tables(), 'table');
    expect($tables)->toContain('membership_levels');
});
