<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * The download route is the only externally-reachable surface in Phase 1 that
 * streams a whole company's GL out of the system. These tests pin:
 *
 *  - happy path (Owner with signed URL gets the ZIP),
 *  - signed-middleware enforcement (unsigned + time-expired),
 *  - role gate (Admin member is forbidden — backups include API keys, member
 *    emails, and the full GL, so only Owner downloads),
 *  - the cross-tenant IDOR vector documented in the project's
 *    cross-tenant-idor-livewire memory (foreign-tenant backup must 404),
 *  - lifecycle states (Pending → 409, Ready-but-file-gone → 410).
 */
beforeEach(function () {
    Storage::fake('local');

    $this->company = Company::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    $this->filePath = "backups/{$this->company->id}/backup.zip";
    Storage::disk('local')->put($this->filePath, 'PK'.str_repeat("\0", 32));

    $this->backup = CompanyBackup::factory()
        ->for($this->company)
        ->create([
            'requested_by_user_id' => $this->owner->id,
            'status' => CompanyBackupStatus::Ready,
            'file_path' => $this->filePath,
            'file_size_bytes' => 34,
            'sha256' => str_repeat('a', 64),
            'app_version' => '1.0.0',
            'schema_version' => 1,
            'expires_at' => now()->addDays(7),
        ]);
});

function backupUrl(Company $company, CompanyBackup $backup, CarbonImmutable|Carbon|null $expires = null): string
{
    return URL::temporarySignedRoute(
        'settings.backup.download',
        $expires ?? now()->addHour(),
        ['company' => $company, 'backup' => $backup->id],
    );
}

it('lets an owner download a ready backup via a signed URL', function () {
    $this->actingAs($this->owner);

    $response = $this->get(backupUrl($this->company, $this->backup));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/zip');
    expect($response->headers->get('x-content-sha256'))->toBe(str_repeat('a', 64));
    expect($response->headers->get('content-disposition'))
        ->toContain("lineledger-backup-{$this->company->slug}-{$this->backup->id}.zip");
});

it('rejects an unsigned URL with 403', function () {
    $this->actingAs($this->owner);

    $unsigned = route('settings.backup.download', [
        'company' => $this->company,
        'backup' => $this->backup->id,
    ]);

    $this->get($unsigned)->assertForbidden();
});

it('rejects an expired signed URL with 403', function () {
    $this->actingAs($this->owner);

    $url = backupUrl($this->company, $this->backup, now()->addHour());

    Carbon::setTestNow(now()->addHours(2));

    try {
        $this->get($url)->assertForbidden();
    } finally {
        Carbon::setTestNow();
    }
});

it('forbids a non-owner member of the same company', function () {
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $this->company->members()->attach($admin, ['role' => CompanyRole::Admin->value]);

    $this->actingAs($admin);

    $this->get(backupUrl($this->company, $this->backup))->assertForbidden();
});

it('returns 404 when a user from another company tries to download via that other company URL (IDOR vector)', function () {
    $otherCompany = Company::factory()->create();
    $otherUser = User::factory()->create(['email_verified_at' => now()]);
    $otherCompany->members()->attach($otherUser, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($otherUser);

    // Forge a signed URL under the attacker's own company slug but pointing
    // at the victim company's backup id. SubstituteBindings runs before
    // EnsureCompanyMembership in the default Laravel 13 priority list, so
    // the CompanyBackup model gets bound without the global CompanyScope —
    // the controller's `$backup->company_id === $company->id` guard is what
    // turns this back into a 404.
    $url = URL::temporarySignedRoute(
        'settings.backup.download',
        now()->addHour(),
        ['company' => $otherCompany, 'backup' => $this->backup->id],
    );

    $this->get($url)->assertNotFound();
});

it('returns 410 Gone when the backup row is Ready but the file is missing', function () {
    Storage::disk('local')->delete($this->filePath);

    $this->actingAs($this->owner);

    $this->get(backupUrl($this->company, $this->backup))->assertStatus(410);
});

it('returns 409 Conflict when the backup is not yet Ready', function () {
    $this->backup->update(['status' => CompanyBackupStatus::Pending]);

    $this->actingAs($this->owner);

    $this->get(backupUrl($this->company, $this->backup))->assertStatus(409);
});
