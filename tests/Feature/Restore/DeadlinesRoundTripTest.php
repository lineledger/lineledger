<?php

use App\Actions\Deadlines\CompleteDeadlineOccurrence;
use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Enums\DeadlineDigestFrequency;
use App\Enums\DeadlineStatus;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\CompanyRestore;
use App\Models\Deadline;
use App\Models\DeadlineNotificationSetting;
use App\Models\DeadlineOccurrence;
use App\Models\Membership;
use App\Models\TaxAgency;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use App\Services\Restore\CompanyImporter;
use Illuminate\Support\Facades\Storage;

it('round-trips deadlines, their occurrences and email settings onto the restored company', function () {
    Storage::fake('local');
    Storage::fake('public');

    $owner = User::factory()->create(['email' => 'deadline-owner@acme.test']);
    $companyA = Company::factory()->create(['name' => 'Deadline Co']);
    Membership::create(['company_id' => $companyA->id, 'user_id' => $owner->id, 'role' => CompanyRole::Owner]);
    app()->instance('current_company', $companyA);
    test()->actingAs($owner);

    $agency = TaxAgency::query()->firstOrCreate(['name' => 'Canada Revenue Agency']);
    $deadline = Deadline::factory()->create([
        'title' => 'GST/HST return',
        'tax_agency_id' => $agency->id,
        'assigned_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'period_anchor' => '2026-01-01',
        'track_from' => '2026-01-01',
    ]);
    app(DeadlineOccurrenceSync::class)->sync($deadline);
    $first = DeadlineOccurrence::query()->orderBy('due_date')->first();
    app(CompleteDeadlineOccurrence::class)->handle($companyA, $first, ['completed_on' => '2026-02-20', 'amount' => '321.00', 'confirmation_number' => 'CONF-1']);
    DeadlineNotificationSetting::query()->create(['user_id' => $owner->id, 'digest' => DeadlineDigestFrequency::Daily]);

    $occurrenceCount = DeadlineOccurrence::query()->count();

    // ---- Export ----
    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $owner->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);
    app(CompanyExporter::class)->export($backup);
    $backup->refresh();

    $zipAbs = Storage::disk('local')->path($backup->file_path);
    $restoreRelative = 'restores/'.basename($zipAbs);
    Storage::disk('local')->put($restoreRelative, file_get_contents($zipAbs));

    $restore = CompanyRestore::create([
        'requested_by_user_id' => $owner->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $restoreRelative,
        'file_size_bytes' => Storage::disk('local')->size($restoreRelative),
        'sha256' => hash_file('sha256', Storage::disk('local')->path($restoreRelative)),
    ]);

    app()->forgetInstance('current_company');
    app(CompanyImporter::class)->import($restore);
    $restore->refresh();

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed);

    $companyB = Company::find($restore->company_id);
    app()->instance('current_company', $companyB);

    $deadlineB = Deadline::query()->where('company_id', $companyB->id)->sole();
    $agencyB = TaxAgency::query()->where('company_id', $companyB->id)->where('name', 'Canada Revenue Agency')->value('id');
    $doneB = DeadlineOccurrence::query()->where('company_id', $companyB->id)->where('status', DeadlineStatus::Done->value)->sole();

    expect($deadlineB->id)->not->toBe($deadline->id)
        ->and($deadlineB->tax_agency_id)->toBe($agencyB)
        ->and($deadlineB->assigned_user_id)->toBe($owner->id)
        ->and(DeadlineOccurrence::query()->where('company_id', $companyB->id)->count())->toBe($occurrenceCount)
        ->and($doneB->deadline_id)->toBe($deadlineB->id)
        ->and($doneB->completed_by_user_id)->toBe($owner->id)
        ->and($doneB->amount_cents)->toBe(32100)
        ->and($doneB->confirmation_number)->toBe('CONF-1')
        ->and(DeadlineNotificationSetting::query()->where('company_id', $companyB->id)->sole()->digest)->toBe(DeadlineDigestFrequency::Daily);
});
