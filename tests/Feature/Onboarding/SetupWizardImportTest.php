<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\DataMigrationMode;
use App\Models\Account;
use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->companies()->detach();
    $this->user->forceFill(['current_company_id' => null])->save();
    $this->actingAs($this->user);
});

test('the import fork creates a minimal-chart company and an opening-balance run', function () {
    $component = Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Imported Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('startMode', 'import')
        ->set('importMode', 'opening_balance')
        ->call('beginImport')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Imported Co')->firstOrFail();
    $component->assertSet('createdCompanyId', $company->id);

    // Minimal system chart only — no industry operating accounts.
    expect(Account::withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(10);

    $run = DataMigrationRun::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
    expect($run->mode)->toBe(DataMigrationMode::OpeningBalance);
    // The import opens on the setup step so the user picks the conversion date.
    expect((int) $run->current_step)->toBe(1);
    expect($run->isStepComplete('setup'))->toBeFalse();
});

test('the import fork can start a full-history run', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'History Co')
        ->set('country', 'US')
        ->set('region', 'WA')
        ->set('startMode', 'import')
        ->set('importMode', 'full_history')
        ->call('beginImport')
        ->assertHasNoErrors();

    $run = DataMigrationRun::withoutGlobalScopes()
        ->where('company_id', Company::where('name', 'History Co')->value('id'))
        ->firstOrFail();

    expect($run->mode)->toBe(DataMigrationMode::FullHistory);
    expect($run->steps())->toHaveCount(9);
});

test('createdCompanyId cannot be set from the client (locked against tenant forgery)', function () {
    // Regression for the cross-tenant IDOR: without #[Locked] a client could POST a
    // syncInput to point the embedded importer at an arbitrary company. Only the
    // server-side beginImport() may assign it.
    $victim = Company::factory()->create();

    expect(fn () => Livewire::test('pages::welcome.setup-wizard')->set('createdCompanyId', $victim->id))
        ->toThrow(Exception::class, 'Cannot update locked property');
});

test('the import fork requires a mode to be chosen', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'No Mode Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('startMode', 'import')
        ->set('importMode', '')
        ->call('beginImport')
        ->assertHasErrors(['importMode']);
});

test('the migration component mounts embedded with a preset mode and ignores the query string', function () {
    $company = Company::factory()->create(['address_country' => 'CA']);
    $company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    Livewire::test('pages::migration.import', ['company' => $company, 'embedded' => true, 'presetMode' => 'full_history'])
        ->assertSet('embedded', true);

    $run = DataMigrationRun::withoutGlobalScopes()->where('company_id', $company->id)->firstOrFail();
    expect($run->mode)->toBe(DataMigrationMode::FullHistory);
});

test('the control-accounts step hydrates with the live system accounts', function () {
    $company = Company::factory()->create(['address_country' => 'CA']);
    $company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $seededAr = Account::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('subtype', AccountSubtype::AccountsReceivable->value)
        ->where('is_system', true)
        ->first();

    Livewire::test('pages::migration.import', ['company' => $company, 'embedded' => true, 'presetMode' => 'opening_balance'])
        ->assertSet('controlMapping.accounts_receivable', $seededAr->id);
});

test('both modes include the confirm_control_accounts step at position 3', function () {
    expect(DataMigrationMode::OpeningBalance->steps()[3])->toBe('confirm_control_accounts');
    expect(DataMigrationMode::FullHistory->steps()[3])->toBe('confirm_control_accounts');
});

test('step completion stays keyed by step name across the renumber', function () {
    $company = Company::factory()->create(['address_country' => 'CA']);
    $run = DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'mode' => DataMigrationMode::OpeningBalance,
        'conversion_date' => now(),
        'current_step' => 2,
    ]);

    $run->recordStepResult('chart_of_accounts', ['rows' => 5]);

    expect($run->fresh()->isStepComplete('chart_of_accounts'))->toBeTrue();
    expect($run->fresh()->resolveCurrentStepByKey())->toBe(1); // setup still incomplete → first incomplete
});
