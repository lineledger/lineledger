<?php

use App\Enums\DataMigrationStatus;
use App\Models\Company;
use App\Models\DataMigrationRun;
use Carbon\CarbonImmutable;

it('stores step_results containing malformed UTF-8 without throwing', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $run = DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'status' => DataMigrationStatus::InProgress,
        'conversion_date' => CarbonImmutable::now(),
        'current_step' => 1,
        'step_results' => [],
    ]);

    // "\xC3\x28" is an invalid UTF-8 sequence; before the fix this threw a
    // JsonEncodingException and aborted the import job mid-stream.
    $run->recordStepResult('general_ledger', [
        'status' => 'failed',
        'errors' => [['row' => 6976, 'message' => "Account 'bad \xC3\x28 name' not found."]],
    ]);

    $fresh = $run->fresh();

    expect($fresh->isStepComplete('general_ledger'))->toBeTrue()
        ->and($fresh->step_results['general_ledger']['errors'][0]['message'])->toBeString();

    app()->forgetInstance('current_company');
});
