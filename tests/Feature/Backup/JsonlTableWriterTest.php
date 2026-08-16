<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Services\Backup\JsonlTableWriter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->companyA = Company::factory()->create(['name' => 'Alpha Co']);
    $this->companyB = Company::factory()->create(['name' => 'Bravo Co']);

    // Seed 3 contacts for A and 2 for B. We toggle `current_company` per group
    // so the BelongsToCompany trait stamps the right tenant during creation.
    app()->instance('current_company', $this->companyA);
    Contact::factory()->customer()->create(['display_name' => 'Alpha-Acme']);
    Contact::factory()->customer()->create(['display_name' => 'Alpha-Beta']);
    Contact::factory()->customer()->create(['display_name' => 'Alpha-Gamma']);

    app()->instance('current_company', $this->companyB);
    Contact::factory()->customer()->create(['display_name' => 'Bravo-Acme']);
    Contact::factory()->customer()->create(['display_name' => 'Bravo-Beta']);

    app()->forgetInstance('current_company');

    $this->workDir = sys_get_temp_dir().'/backup-jsonl-test-'.uniqid();
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

it('writes one JSON object per row scoped to the given company', function () {
    $writer = new JsonlTableWriter;

    $result = $writer->write(
        modelClass: Contact::class,
        companyId: $this->companyA->id,
        workDir: $this->workDir,
        tableName: 'contacts',
    );

    expect($result['rows'])->toBe(3);

    $path = $this->workDir.'/data/contacts.jsonl';
    expect(file_exists($path))->toBeTrue();

    $lines = array_values(array_filter(explode("\n", file_get_contents($path))));
    expect($lines)->toHaveCount(3);

    $names = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        expect($row)->toBeArray()
            ->and($row['company_id'])->toBe($this->companyA->id);
        $names[] = $row['display_name'];
    }

    sort($names);
    expect($names)->toBe(['Alpha-Acme', 'Alpha-Beta', 'Alpha-Gamma']);
});

it('computes the sha256 of the written byte stream and reports byte count', function () {
    $writer = new JsonlTableWriter;

    $result = $writer->write(
        modelClass: Contact::class,
        companyId: $this->companyA->id,
        workDir: $this->workDir,
        tableName: 'contacts',
    );

    $path = $this->workDir.'/data/contacts.jsonl';
    $expectedHash = hash_file('sha256', $path);
    $expectedBytes = filesize($path);

    expect($result['sha256'])->toBe($expectedHash)
        ->and($result['bytes'])->toBe($expectedBytes);
});

it('does not leak rows from other companies', function () {
    $writer = new JsonlTableWriter;

    $writer->write(
        modelClass: Contact::class,
        companyId: $this->companyA->id,
        workDir: $this->workDir,
        tableName: 'contacts',
    );

    $contents = file_get_contents($this->workDir.'/data/contacts.jsonl');

    expect($contents)->not->toContain('Bravo-Acme')
        ->and($contents)->not->toContain('Bravo-Beta');
});

it('applies a row transform closure before writing each line', function () {
    $writer = new JsonlTableWriter;

    $writer->write(
        modelClass: Contact::class,
        companyId: $this->companyA->id,
        workDir: $this->workDir,
        tableName: 'contacts',
        rowTransform: function (array $row): array {
            unset($row['email']);
            $row['_transformed'] = true;

            return $row;
        },
    );

    $line = strtok(file_get_contents($this->workDir.'/data/contacts.jsonl'), "\n");
    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    expect($row)->toHaveKey('_transformed')
        ->and($row)->not->toHaveKey('email');
});

it('filters via the companies branch when exporting the companies table itself', function () {
    $writer = new JsonlTableWriter;

    $result = $writer->write(
        modelClass: Company::class,
        companyId: $this->companyA->id,
        workDir: $this->workDir,
        tableName: 'companies',
    );

    expect($result['rows'])->toBe(1);

    $line = strtok(file_get_contents($this->workDir.'/data/companies.jsonl'), "\n");
    $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    expect($row['id'])->toBe($this->companyA->id)
        ->and($row['name'])->toBe('Alpha Co');
});

it('honors a caller-supplied scope closure for tables without a company_id column', function () {
    // Seed a parent journal_entry for each company; the lines child table
    // lacks a `company_id` column in production, but here we exercise the
    // $scope branch by writing journal_entries via an explicit scope.
    app()->instance('current_company', $this->companyA);
    JournalEntry::create([
        'entry_no' => 'JE-A-1',
        'entry_date' => CarbonImmutable::create(2026, 5, 1),
        'memo' => 'alpha',
    ]);

    app()->instance('current_company', $this->companyB);
    JournalEntry::create([
        'entry_no' => 'JE-B-1',
        'entry_date' => CarbonImmutable::create(2026, 5, 1),
        'memo' => 'bravo',
    ]);
    app()->forgetInstance('current_company');

    $writer = new JsonlTableWriter;

    $companyAId = $this->companyA->id;
    $result = $writer->write(
        modelClass: JournalEntry::class,
        companyId: $this->companyA->id,
        workDir: $this->workDir,
        tableName: 'journal_entries',
        scope: fn ($q) => $q->where('company_id', $companyAId),
    );

    expect($result['rows'])->toBe(1);

    $contents = file_get_contents($this->workDir.'/data/journal_entries.jsonl');

    expect($contents)->toContain('JE-A-1')
        ->and($contents)->not->toContain('JE-B-1');
});
