<?php

use App\Enums\CompanyRole;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
});

it('hides the Import from QuickBooks settings link when no import is in progress', function () {
    $this->get(route('settings.invoices', ['company' => $this->company]))
        ->assertOk()
        ->assertDontSee('Import from QuickBooks');
});

it('shows the Import from QuickBooks settings link while an import is in progress', function () {
    DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'status' => DataMigrationStatus::InProgress,
        'mode' => DataMigrationMode::OpeningBalance,
        'conversion_date' => now(),
        'current_step' => 2,
        'started_at' => now(),
    ]);

    $this->get(route('settings.invoices', ['company' => $this->company]))
        ->assertOk()
        ->assertSee('Import from QuickBooks');
});
