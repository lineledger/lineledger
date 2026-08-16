<?php

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Backup\ReferencedUsersExporter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->companyA = Company::factory()->create();
    $this->companyB = Company::factory()->create();

    $this->postingUser = User::factory()->create([
        'name' => 'Posting Polly',
        'email' => 'polly@example.test',
    ]);

    $this->voidingUser = User::factory()->create([
        'name' => 'Voiding Vince',
        'email' => 'vince@example.test',
    ]);

    $this->otherCompanyUser = User::factory()->create([
        'name' => 'Other Olive',
        'email' => 'olive@example.test',
    ]);

    app()->instance('current_company', $this->companyA);
    JournalEntry::create([
        'entry_no' => 'JE-A-1',
        'entry_date' => CarbonImmutable::create(2026, 5, 1),
        'memo' => 'first',
        'posted_by_user_id' => $this->postingUser->id,
    ]);
    JournalEntry::create([
        'entry_no' => 'JE-A-2',
        'entry_date' => CarbonImmutable::create(2026, 5, 2),
        'memo' => 'second',
        'posted_by_user_id' => $this->voidingUser->id,
        'voided_by_user_id' => $this->voidingUser->id,
    ]);

    // A journal entry in the OTHER company referencing a user we should not export.
    app()->instance('current_company', $this->companyB);
    JournalEntry::create([
        'entry_no' => 'JE-B-1',
        'entry_date' => CarbonImmutable::create(2026, 5, 1),
        'memo' => 'foreign',
        'posted_by_user_id' => $this->otherCompanyUser->id,
    ]);

    app()->forgetInstance('current_company');

    $this->workDir = sys_get_temp_dir().'/backup-users-test-'.uniqid();
    mkdir($this->workDir, 0755, true);
});

afterEach(function () {
    if (is_dir($this->workDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->workDir);
    }
});

it('writes users.json with only the users referenced by this company', function () {
    $exporter = new ReferencedUsersExporter;

    $result = $exporter->exportReferencedUsers($this->companyA->id, $this->workDir);

    $path = $this->workDir.'/users.json';
    expect(file_exists($path))->toBeTrue();

    $raw = file_get_contents($path);
    $users = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $emails = array_column($users, 'email');

    expect($emails)->toContain('polly@example.test')
        ->and($emails)->toContain('vince@example.test')
        ->and($emails)->not->toContain('olive@example.test');

    expect($result['count'])->toBe(count($users))
        ->and($result['sha256'])->toBe(hash('sha256', $raw))
        ->and($result['bytes'])->toBe(strlen($raw));
});

it('only includes id, email, and name fields for each user', function () {
    $exporter = new ReferencedUsersExporter;

    $exporter->exportReferencedUsers($this->companyA->id, $this->workDir);

    $users = json_decode(file_get_contents($this->workDir.'/users.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($users as $u) {
        expect(array_keys($u))->toBe(['id', 'email', 'name']);
    }
});

it('returns an empty list when the company has no audit references', function () {
    $isolated = Company::factory()->create();
    $exporter = new ReferencedUsersExporter;

    $result = $exporter->exportReferencedUsers($isolated->id, $this->workDir);

    expect($result['count'])->toBe(0);

    $users = json_decode(file_get_contents($this->workDir.'/users.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($users)->toBe([]);
});
