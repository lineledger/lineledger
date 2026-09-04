<?php

use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BankRuleMatchType;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\ExpenseStatus;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Services\Banking\Import\StatementImportProcessor;
use App\Services\Banking\Import\StatementSuggestionPipeline;
use App\Services\Posting\BillPoster;
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

function openBillForCategorizationTest(Contact $vendor, int $cents): Bill
{
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => 'BILL-'.fake()->unique()->numerify('####'),
        'bill_date' => now()->subDays(10)->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
        'lines' => [['account_id' => test()->expenseA->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('a rule that names a vendor fills the payee too', function () {
    $vendor = Contact::factory()->vendor()->create();
    BankRule::create([
        'name' => 'Coffee',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'tim',
        'action_account_id' => $this->expenseB->id,
        'action_contact_id' => $vendor->id,
        'is_active' => true,
        'priority' => 1,
    ]);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $tim = lineLike($import, 'tim');

    expect($tim->suggested_contact_id)->toBe($vendor->id)
        ->and($tim->suggestion_source)->toBe(StatementSuggestionSource::Rule);
});

it('history remembers the vendor and matches on the merchant key through the pipeline', function () {
    Http::fake();
    $vendor = Contact::factory()->vendor()->create();

    seedCreatedHistory($this->bank, 'PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812', $this->expenseA->id);
    BankStatementLine::query()->latest('id')->firstOrFail()->forceFill(['suggested_contact_id' => $vendor->id])->save();

    $csv = "Date,Description,Amount\n".now()->subDays(1)->toDateString().",\"Pre-Authorized Payment, L SOCIO DIGITAL FEE/FRA    ,\",-2520.00\n";
    $import = stageCsvImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($import);

    $line = lineLike($import, 'socio');

    expect($line->suggested_account_id)->toBe($this->expenseA->id)
        ->and($line->suggested_contact_id)->toBe($vendor->id)
        ->and($line->suggestion_source)->toBe(StatementSuggestionSource::History)
        ->and($line->match_status)->toBe(StatementLineMatchStatus::Unmatched) // pre-filled, not confirmed
        ->and($line->match_reason)->toContain('Looks like');
});

it('offers the open bill for the same amount, vendor-scoped when the payee is known', function () {
    $vendor = Contact::factory()->vendor()->create();
    $bill = openBillForCategorizationTest($vendor, 5000);
    BankRule::create([
        'name' => 'New merchant',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'new merchant',
        'action_account_id' => $this->expenseB->id,
        'action_contact_id' => $vendor->id,
        'is_active' => true,
        'priority' => 1,
    ]);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $line = lineLike($import, 'new merchant');

    expect($line->suggested_bill_id)->toBe($bill->id)
        ->and($line->suggestion_source)->toBe(StatementSuggestionSource::Rule) // the account came from the rule
        ->and($line->match_reason)->toContain('open bill');
});

it('offers an unambiguous company-wide open bill and fills the vendor default account', function () {
    $vendor = Contact::factory()->vendor()->create(['default_expense_account_id' => $this->expenseB->id]);
    $bill = openBillForCategorizationTest($vendor, 5000);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $line = lineLike($import, 'new merchant');

    expect($line->suggested_bill_id)->toBe($bill->id)
        ->and($line->suggested_contact_id)->toBe($vendor->id)
        ->and($line->suggested_account_id)->toBe($this->expenseB->id)
        ->and($line->suggestion_source)->toBe(StatementSuggestionSource::OpenBill);
});

it('does not offer a bill when two open bills share the amount', function () {
    openBillForCategorizationTest(Contact::factory()->vendor()->create(), 5000);
    openBillForCategorizationTest(Contact::factory()->vendor()->create(), 5000);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    expect(lineLike($import, 'new merchant')->suggested_bill_id)->toBeNull();
});

it('lets a merchant-key rule beat a broad contains rule at the same priority', function () {
    BankRule::create([
        'name' => 'Any payment',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'payment',
        'action_account_id' => $this->expenseA->id,
        'is_active' => true,
        'priority' => 0,
    ]);
    BankRule::create([
        'name' => 'L Socio Digital',
        'match_type' => BankRuleMatchType::MerchantKey->value,
        'match_pattern' => 'pre authorized payment l socio digital fee fra',
        'action_account_id' => $this->expenseB->id,
        'is_active' => true,
        'priority' => 0,
    ]);

    $csv = "Date,Description,Amount\n".now()->subDays(1)->toDateString().",\"Pre-Authorized Payment, L SOCIO DIGITAL FEE/FRA REF 8812\",-2520.00\n";
    $import = stageCsvImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($import);

    expect(lineLike($import, 'socio')->suggested_account_id)->toBe($this->expenseB->id);
});

it('stays idempotent across the new layers', function () {
    $vendor = Contact::factory()->vendor()->create(['default_expense_account_id' => $this->expenseB->id]);
    $bill = openBillForCategorizationTest($vendor, 5000);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);
    app(StatementSuggestionPipeline::class)->fill($import->fresh());

    $line = lineLike($import, 'new merchant');

    expect($line->suggested_bill_id)->toBe($bill->id)
        ->and($line->suggested_account_id)->toBe($this->expenseB->id)
        ->and(substr_count((string) $line->match_reason, 'open bill'))->toBe(1);
});

it('fills the tax code from the vendor default when the rule set the account but not the tax', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $vendor = Contact::factory()->vendor()->create(['default_tax_code_id' => $gst->id]);
    BankRule::create([
        'name' => 'Coffee',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'tim',
        'action_account_id' => $this->expenseB->id,
        'action_contact_id' => $vendor->id,
        'is_active' => true,
        'priority' => 1,
    ]);

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $tim = lineLike($import, 'tim');

    expect($tim->suggested_account_id)->toBe($this->expenseB->id)
        ->and($tim->suggested_tax_code_id)->toBe($gst->id)
        ->and($tim->suggestion_source)->toBe(StatementSuggestionSource::Rule);
});

it('history remembers the tax code the payee was recorded with last time', function () {
    Http::fake();
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();

    $entry = JournalEntry::create(['entry_no' => 'JE-HIST-TAX', 'entry_date' => now()->subDays(20)->toDateString(), 'memo' => 'x']);
    $expense = Expense::create([
        'payment_account_id' => $this->bank->id,
        'expense_date' => now()->subDays(20)->toDateString(),
        'payee_name' => 'Tim Hortons',
        'amount_cents' => 1050,
        'status' => ExpenseStatus::Posted->value,
        'posted_at' => now(),
        'journal_entry_id' => $entry->id,
    ]);
    $expense->lines()->create(['account_id' => $this->expenseA->id, 'amount_cents' => 1000, 'tax_code_id' => $gst->id, 'tax_cents' => 50, 'line_order' => 0]);

    seedCreatedHistory($this->bank, 'TIM HORTONS', $this->expenseA->id);
    BankStatementLine::query()->latest('id')->firstOrFail()->forceFill(['created_journal_entry_id' => $entry->id])->save();

    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $tim = lineLike($import, 'tim');

    expect($tim->suggested_account_id)->toBe($this->expenseA->id)
        ->and($tim->suggested_tax_code_id)->toBe($gst->id)
        ->and($tim->suggestion_source)->toBe(StatementSuggestionSource::History);
});

it('a stored bill allocation counts as an unconfirmed suggestion', function () {
    $import = stageCsvImport($this->bank, twoLineCsv());
    app(StatementImportProcessor::class)->process($import);

    $line = lineLike($import, 'new merchant');
    $line->forceFill(['suggested_bill_allocations' => [['bill_id' => 1, 'amount_cents' => 5000]]])->save();

    expect($line->fresh()->hasUnconfirmedSuggestion())->toBeTrue()
        ->and($line->fresh()->suggestedBillAllocations())->toBe([['bill_id' => 1, 'amount_cents' => 5000]])
        ->and($import->lines()->unconfirmedSuggestions()->count())->toBe(1);
});

it('offers a multi-bill allocation when no single bill matches and the payee is known', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Two Bills Co']);
    $a = openBillForCategorizationTest($vendor, 3000);
    $b = openBillForCategorizationTest($vendor, 2000);
    BankRule::create([
        'name' => 'New merchant',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'new merchant',
        'action_account_id' => $this->expenseB->id,
        'action_contact_id' => $vendor->id,
        'is_active' => true,
        'priority' => 1,
    ]);

    $import = stageCsvImport($this->bank, twoLineCsv()); // New Merchant Co is -50.00
    app(StatementImportProcessor::class)->process($import);

    $line = lineLike($import, 'new merchant');

    expect($line->suggested_bill_id)->toBeNull()
        ->and($line->suggestedBillAllocations())->toBe([['bill_id' => $a->id, 'amount_cents' => 3000], ['bill_id' => $b->id, 'amount_cents' => 2000]])
        ->and($line->match_reason)->toContain('2 open bills from Two Bills Co')
        ->and($line->hasUnconfirmedSuggestion())->toBeTrue();

    // Idempotent: a second fill leaves the offer as is.
    app(StatementSuggestionPipeline::class)->fill($import->fresh());
    expect(lineLike($import, 'new merchant')->suggestedBillAllocations())->toHaveCount(2);
});
