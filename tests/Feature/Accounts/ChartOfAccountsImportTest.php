<?php

use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** Build a fake CSV upload from a string body. */
function accountsImportCsv(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent('accounts.csv', $body);
}

it('streams a downloadable template with the importer columns', function () {
    $response = $this->get(route('accounts.template', ['company' => $this->company->slug]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())->toContain('code,name,subtype,parent_code,description');
});

it('imports new accounts and resolves a parent referenced by code', function () {
    $csv = <<<'CSV'
    code,name,subtype,parent_code,description
    9900,Custom Parent Expense,expense,,Parent
    9910,Custom Child Expense,expense,9900,Child of 9900
    CSV;

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('importFile', accountsImportCsv($csv))
        ->call('previewImport');

    // The preview marks both rows for creation (summary['created'] only fills on commit).
    expect($component->get('importErrors'))->toBe([]);
    expect(collect($component->get('importPreviewRows'))->where('action', 'create'))->toHaveCount(2);

    $component->call('runImport');

    $parent = Account::query()->where('code', '9900')->first();
    $child = Account::query()->where('code', '9910')->first();

    expect($parent)->not->toBeNull();
    expect($child)->not->toBeNull();
    expect($child->parent_id)->toBe($parent->id);
    expect($child->name)->toBe('Custom Child Expense');
});

it('skips a row whose code already exists, leaving the account untouched', function () {
    $existing = Account::query()->orderBy('code')->firstOrFail();

    $csv = "code,name,subtype,parent_code,description\n"
        ."{$existing->code},Renamed By Import,expense,,\n"
        ."9930,Genuinely New Account,expense,,\n";

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('importFile', accountsImportCsv($csv))
        ->call('previewImport');

    expect(collect($component->get('importPreviewRows'))->where('action', 'create'))->toHaveCount(1);
    expect($component->get('importSummary')['skipped_existing'])->toBe(1);

    $component->call('runImport');

    // The pre-existing account keeps its original name; only the new code lands.
    expect($existing->fresh()->name)->toBe($existing->name);
    expect(Account::query()->where('code', $existing->code)->count())->toBe(1);
    expect(Account::query()->where('code', '9930')->exists())->toBeTrue();
});

it('flags a code that is duplicated within the file and creates nothing', function () {
    // Two different accounts sharing one code: caught in preview so the commit
    // transaction never trips the unique index (and then rolls everything back).
    $csv = <<<'CSV'
    code,name,subtype,parent_code,description
    9960,First Use Of Code,expense,,
    9970,Unrelated Account,expense,,
    9960,Second Use Of Code,income,,
    CSV;

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('importFile', accountsImportCsv($csv))
        ->call('previewImport');

    expect(collect($component->get('importErrors'))->pluck('message')->implode(' '))
        ->toContain("Duplicate code '9960'");

    $component->call('runImport');

    // Nothing lands — not even the rows that came before the duplicate.
    expect(Account::query()->whereIn('code', ['9960', '9970'])->exists())->toBeFalse();
});

it('reports an error and creates nothing when a subtype is invalid', function () {
    $csv = <<<'CSV'
    code,name,subtype,parent_code,description
    9940,Bad Subtype Account,not_a_real_subtype,,
    CSV;

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->set('importFile', accountsImportCsv($csv))
        ->call('runImport');

    expect($component->get('importErrors'))->not->toBe([]);
    expect(Account::query()->where('code', '9940')->exists())->toBeFalse();
});
