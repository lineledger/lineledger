<?php

use App\Enums\AccountSubtype;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Jobs\ReplayGeneralLedgerImport;
use App\Models\Account;
use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Services\Migration\ContactLinkBackfiller;
use App\Services\Migration\Importers\GeneralLedgerReplayImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->run = DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'status' => DataMigrationStatus::InProgress,
        'mode' => DataMigrationMode::FullHistory,
        'conversion_date' => CarbonImmutable::create(2026, 7, 31),
        'current_step' => 5,
        'step_results' => [],
        'started_at' => now(),
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('runs the replay job, marks the step complete, and deletes the source file', function () {
    Storage::fake('local');

    $bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Deposit,2024-01-01,,,,{$bank->name},750.00,\n"
        .",,,,,,{$income->name},,750.00\n";

    Storage::disk('local')->put('migrations/gl.csv', $csv);

    (new ReplayGeneralLedgerImport(
        companyId: (int) $this->company->id,
        runId: (int) $this->run->id,
        storedPaths: ['migrations/gl.csv'],
        sourceFormat: 'csv',
        autoCreateAccounts: false,
        linkContactNames: true,
    ))->handle(app(GeneralLedgerReplayImporter::class), app(ContactLinkBackfiller::class));

    $run = $this->run->fresh();
    expect($run->isStepComplete('general_ledger'))->toBeTrue()
        ->and($run->step_results['general_ledger']['committed'])->toBe(1);

    Storage::disk('local')->assertMissing('migrations/gl.csv');

    $bank->recomputeBalance();
    expect((int) $bank->balance_cents)->toBe(75000);
});
