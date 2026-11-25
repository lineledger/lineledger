<?php

use App\Enums\AccountSubtype;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\CompanyRole;
use App\Jobs\ProcessBankStatementImport;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankStatementImport;
use App\Models\Company;
use App\Models\User;
use App\Services\Banking\Import\Contracts\StatementIntelligence;
use App\Services\Banking\Import\DTO\ColumnMapping;
use App\Services\Banking\Import\StatementImportProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Bank-statement AI is doubly opt-in, like receipt reading and AI insights:
 * the operator's BANK_IMPORT_AI_ENABLED + key, and each organization's own
 * `banking.ai_import` switch (off by default) on its edit page.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();

    // The operator half: an enabled intelligence that maps any CSV, counting its calls.
    $this->intelligence = new class implements StatementIntelligence
    {
        public int $calls = 0;

        public function isEnabled(): bool
        {
            return true;
        }

        public function inferMapping(array $headers, array $sampleRows): ?ColumnMapping
        {
            $this->calls++;

            return new ColumnMapping(amountMode: 'single', dateColumn: 'Col1', descriptionColumns: ['Col2'], amountColumn: 'Col3', dateFormat: 'Y-m-d');
        }

        public function extractTransactions(string $statementText): array
        {
            return [];
        }

        public function extractTransactionsFromPdf(string $absolutePath): array
        {
            return [];
        }

        public function lastError(): ?string
        {
            return null;
        }
    };
    app()->instance(StatementIntelligence::class, $this->intelligence);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** An uploaded CSV whose headers the deterministic detector can't place. */
function bankAiSwitchImport(Account $bank): BankStatementImport
{
    $content = "Col1,Col2,Col3\n2026-01-05,Payroll,2000.00\n";

    $import = BankStatementImport::create([
        'account_id' => $bank->id,
        'source_format' => BankStatementFormat::Csv->value,
        'original_filename' => 'statement.csv',
        'status' => BankStatementImportStatus::Uploaded->value,
    ]);

    $path = 'attachments/'.$bank->company_id.'/bank_statement_imports/'.$import->id.'/'.Str::ulid().'.csv';
    Storage::disk('local')->put($path, $content);

    $attachment = Attachment::create([
        'attachable_type' => $import->getMorphClass(),
        'attachable_id' => $import->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'statement.csv',
        'mime_type' => 'text/csv',
        'size_bytes' => strlen($content),
    ]);

    $import->update(['attachment_id' => $attachment->id]);

    return $import->fresh();
}

it('is off for an organization until it opts in', function () {
    expect($this->company->bankImportAiEnabled())->toBeFalse();

    $import = bankAiSwitchImport($this->bank);
    app(StatementImportProcessor::class)->process($import);

    expect($import->fresh()->status)->toBe(BankStatementImportStatus::NeedsMapping)
        ->and($this->intelligence->calls)->toBe(0);
});

it('uses AI once the organization opts in', function () {
    $this->company->setBankingState(['ai_import' => true]);

    $import = bankAiSwitchImport($this->bank);
    app(StatementImportProcessor::class)->process($import);

    expect($import->fresh()->status)->toBe(BankStatementImportStatus::Ready)
        ->and($this->intelligence->calls)->toBe(1);
});

it('reads the switch from the import’s organization when the job runs', function () {
    $import = bankAiSwitchImport($this->bank);

    // Another organization, which has opted in, is bound when the job's
    // processor is resolved; the job then binds the import's own.
    $other = Company::factory()->create();
    $other->setBankingState(['ai_import' => true]);
    app()->instance('current_company', $other);

    (new ProcessBankStatementImport($import->id))->handle(app(StatementImportProcessor::class));

    expect($this->intelligence->calls)->toBe(0);

    $this->company->setBankingState(['ai_import' => true]);
    app()->instance('current_company', $other);

    (new ProcessBankStatementImport($import->id))->handle(app(StatementImportProcessor::class));

    expect($this->intelligence->calls)->toBe(1);
});

it('offers the switch on the edit page only when the operator has turned bank-import AI on', function () {
    $this->actingAs($this->owner);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->assertDontSee('Use AI to read bank statements');

    config(['banking.statement_import.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->assertSee('Use AI to read bank statements')
        ->set('bankImportAi', true);

    expect($this->company->fresh()->bankImportAiEnabled())->toBeTrue();

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('bankImportAi', false);

    expect($this->company->fresh()->bankImportAiEnabled())->toBeFalse();
});

it('keeps a member who can’t change settings from flipping it', function () {
    config(['banking.statement_import.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);
    $member = User::factory()->create();
    $this->company->members()->attach($member, ['role' => CompanyRole::Custom->value]);
    $this->actingAs($member);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('bankImportAi', true)
        ->assertForbidden();

    expect($this->company->fresh()->bankImportAiEnabled())->toBeFalse();
});
