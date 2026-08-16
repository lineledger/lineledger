<?php

use App\Enums\AccountSubtype;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankStatementImport;
use App\Models\Company;
use App\Services\Banking\Import\StatementImportProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The consumers that used to call `Storage::disk(...)->path()` — which throws on
 * object storage. These prove they now work against an attachment whose `disk`
 * is not the local filesystem.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');

    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->orderBy('code')
        ->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function makeRemoteImport(Account $bank, string $content, string $disk): BankStatementImport
{
    $import = BankStatementImport::create([
        'account_id' => $bank->id,
        'source_format' => BankStatementFormat::Csv->value,
        'original_filename' => 'statement.csv',
        'status' => BankStatementImportStatus::Uploaded->value,
    ]);

    $path = 'attachments/'.$bank->company_id.'/bank_statement_imports/'.$import->id.'/'.Str::ulid().'.csv';
    Storage::disk($disk)->put($path, $content);

    $attachment = Attachment::create([
        'attachable_type' => $import->getMorphClass(),
        'attachable_id' => $import->id,
        'disk' => $disk,
        'path' => $path,
        'original_filename' => 'statement.csv',
        'mime_type' => 'text/csv',
        'size_bytes' => strlen($content),
    ]);

    $import->update(['attachment_id' => $attachment->id]);

    return $import->fresh();
}

it('parses a bank statement stored on object storage', function () {
    $csv = "Date,Description,Amount,Balance\n"
        ."2026-01-03,COFFEE SHOP,-4.50,995.50\n"
        ."2026-01-05,PAYROLL,2000.00,2995.50\n";

    $import = makeRemoteImport($this->bank, $csv, 's3');

    app(StatementImportProcessor::class)->process($import);

    $import->refresh();

    expect($import->status)->not->toBe(BankStatementImportStatus::Failed)
        ->and($import->lines()->count())->toBe(2)
        ->and($import->lines()->orderBy('txn_date')->first()->description)->toBe('COFFEE SHOP');
});

it('parses the same statement identically from the local disk', function () {
    $csv = "Date,Description,Amount,Balance\n"
        ."2026-01-03,COFFEE SHOP,-4.50,995.50\n"
        ."2026-01-05,PAYROLL,2000.00,2995.50\n";

    $remote = makeRemoteImport($this->bank, $csv, 's3');
    $local = makeRemoteImport($this->bank, $csv, 'local');

    app(StatementImportProcessor::class)->process($remote);
    app(StatementImportProcessor::class)->process($local);

    expect($remote->refresh()->lines()->pluck('description')->all())
        ->toBe($local->refresh()->lines()->pluck('description')->all());
});

it('fails clearly when the import has no uploaded file at all', function () {
    $import = BankStatementImport::create([
        'account_id' => $this->bank->id,
        'source_format' => BankStatementFormat::Csv->value,
        'original_filename' => 'statement.csv',
        'status' => BankStatementImportStatus::Uploaded->value,
    ]);

    expect(fn () => app(StatementImportProcessor::class)->process($import))
        ->toThrow(RuntimeException::class, 'has no uploaded file');
});
