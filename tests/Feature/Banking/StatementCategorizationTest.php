<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BankRuleMatchType;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\StatementLineMatchStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Services\Banking\Import\StatementImportProcessor;
use App\Services\Banking\Import\StatementSuggestionPipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');

    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expenseA = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $this->expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->skip(1)->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function stageCsvImport(Account $bank, string $csv): BankStatementImport
{
    $import = BankStatementImport::create([
        'account_id' => $bank->id,
        'source_format' => BankStatementFormat::Csv->value,
        'original_filename' => 'statement.csv',
        'status' => BankStatementImportStatus::Uploaded->value,
    ]);

    $path = 'attachments/'.$bank->company_id.'/bank_statement_imports/'.$import->id.'/'.Str::ulid().'.csv';
    Storage::disk('local')->put($path, $csv);

    $attachment = Attachment::create([
        'attachable_type' => $import->getMorphClass(),
        'attachable_id' => $import->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'statement.csv',
        'mime_type' => 'text/csv',
        'size_bytes' => strlen($csv),
    ]);

    $import->update(['attachment_id' => $attachment->id]);

    return $import->fresh();
}

function seedCreatedHistory(Account $bank, string $description, int $accountId): void
{
    $hist = BankStatementImport::factory()->committed()->create(['account_id' => $bank->id]);

    BankStatementLine::factory()->create([
        'bank_statement_import_id' => $hist->id,
        'account_id' => $bank->id,
        'txn_date' => now()->subDays(20)->toDateString(),
        'amount_cents' => -450,
        'description' => $description,
        'match_status' => StatementLineMatchStatus::Created->value,
        'suggested_account_id' => $accountId,
    ]);
}

function twoLineCsv(): string
{
    return "Date,Description,Amount\n"
        .now()->subDays(2)->toDateString().",Tim Hortons,-9.99\n"
        .now()->subDays(1)->toDateString().",New Merchant Co,-50.00\n";
}

/** Find a parsed line by a fragment of its description. */
function lineLike(BankStatementImport $import, string $needle): BankStatementLine
{
    return $import->lines()->get()->first(
        fn (BankStatementLine $l): bool => str_contains(strtolower((string) $l->description), strtolower($needle))
    );
}

it('fills a category from history and leaves an unseen merchant blank with AI off', function () {
    Http::fake();

    seedCreatedHistory($this->bank, 'TIM HORTONS', $this->expenseA->id);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $tim = lineLike($import, 'tim');
    $new = lineLike($import, 'new merchant');

    expect($tim->suggested_account_id)->toBe($this->expenseA->id)
        ->and($tim->match_reason)->toContain('categorized')
        ->and($new->suggested_account_id)->toBeNull();

    Http::assertNothingSent();
});

it('falls back to AI for an unseen merchant when the gate is on, batching only the unseen line', function () {
    config()->set('inbox.ai.enabled', true);
    config()->set('inbox.ai.driver', 'http');
    config()->set('services.anthropic.key', 'test-key');
    $this->company->setInboxState(['ocr_enabled' => true]);

    Http::fake(['*/v1/messages' => Http::response(['content' => [[
        'type' => 'tool_use',
        'name' => 'classify_transactions',
        'input' => ['classifications' => [['index' => 0, 'account_code' => $this->expenseB->code]]],
    ]]], 200)]);

    seedCreatedHistory($this->bank, 'TIM HORTONS', $this->expenseA->id);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $tim = lineLike($import, 'tim');
    $new = lineLike($import, 'new merchant');

    expect($tim->suggested_account_id)->toBe($this->expenseA->id) // history, not AI
        ->and($new->suggested_account_id)->toBe($this->expenseB->id)
        ->and($new->match_reason)->toContain('AI');

    // The history-matched line is never sent to the model; only the unseen one is.
    Http::assertSent(function ($request) {
        $content = $request->data()['messages'][0]['content'] ?? '';

        return str_contains($content, 'New Merchant Co') && ! str_contains($content, 'Tim Hortons');
    });
});

it('does not call AI when the company toggle is off', function () {
    config()->set('inbox.ai.enabled', true);
    config()->set('inbox.ai.driver', 'http');
    config()->set('services.anthropic.key', 'test-key');
    // company inbox toggle left OFF

    Http::fake();

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    expect(lineLike($import, 'new merchant')->suggested_account_id)->toBeNull();
    Http::assertNothingSent();
});

it('lets an explicit bank rule win over history', function () {
    BankRule::create([
        'name' => 'Coffee',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'tim',
        'action_account_id' => $this->expenseB->id,
        'is_active' => true,
        'priority' => 1,
    ]);

    seedCreatedHistory($this->bank, 'TIM HORTONS', $this->expenseA->id);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $tim = lineLike($import, 'tim');

    expect($tim->suggested_account_id)->toBe($this->expenseB->id) // rule, not history
        ->and($tim->match_reason)->toContain('rule');
});

it('is idempotent — re-running the pipeline does not overwrite suggestions', function () {
    seedCreatedHistory($this->bank, 'TIM HORTONS', $this->expenseA->id);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    app(StatementSuggestionPipeline::class)->fill($import->fresh());

    expect(lineLike($import, 'tim')->suggested_account_id)->toBe($this->expenseA->id);
});
