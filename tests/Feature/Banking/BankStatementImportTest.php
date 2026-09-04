<?php

use App\Actions\Banking\ConfirmStatementLineSuggestions;
use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\BankStatementFormat;
use App\Enums\BankStatementImportStatus;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Banking\Import\Contracts\StatementIntelligence;
use App\Services\Banking\Import\DTO\ColumnMapping;
use App\Services\Banking\Import\DTO\ParsedTransaction;
use App\Services\Banking\Import\StatementImportCommitter;
use App\Services\Banking\Import\StatementImportProcessor;
use App\Services\Posting\BillPoster;
use App\Services\Posting\JournalPoster;
use App\Services\Reconciliation\BankReconciliationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->revenue = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('code', '6010')->first(); // Bank Charges
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** Positive $signedBankCents = a deposit (debit to the bank); negative = a withdrawal. */
function postedBankEntry(Account $bank, Account $other, int $signedBankCents, string $date): JournalEntry
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'entry_date' => $date,
        'memo' => 'test',
    ]);

    $abs = abs($signedBankCents);

    if ($signedBankCents >= 0) {
        $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => $abs, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $other->id, 'debit_cents' => 0, 'credit_cents' => $abs, 'line_order' => 1]);
    } else {
        $entry->lines()->create(['account_id' => $other->id, 'debit_cents' => $abs, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => $abs, 'line_order' => 1]);
    }

    app(JournalPoster::class)->post($entry->refresh());

    return $entry->fresh('lines');
}

function makeImport(Account $bank, string $content, BankStatementFormat $format = BankStatementFormat::Csv, string $ext = 'csv', string $mime = 'text/csv'): BankStatementImport
{
    $import = BankStatementImport::create([
        'account_id' => $bank->id,
        'source_format' => $format->value,
        'original_filename' => "statement.{$ext}",
        'status' => BankStatementImportStatus::Uploaded->value,
    ]);

    $path = 'attachments/'.$bank->company_id.'/bank_statement_imports/'.$import->id.'/'.Str::ulid().'.'.$ext;
    Storage::disk('local')->put($path, $content);

    $attachment = Attachment::create([
        'attachable_type' => $import->getMorphClass(),
        'attachable_id' => $import->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => "statement.{$ext}",
        'mime_type' => $mime,
        'size_bytes' => strlen($content),
    ]);

    $import->update(['attachment_id' => $attachment->id]);

    return $import->fresh();
}

/** A minimal one-transaction OFX 1.x body. */
function oneTxnOfx(string $fitid, string $name, string $amount, string $date): string
{
    return "OFXHEADER:100\nDATA:OFXSGML\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>CAD"
        ."<BANKTRANLIST><DTSTART>{$date}<DTEND>{$date}"
        ."<STMTTRN><DTPOSTED>{$date}<TRNAMT>{$amount}<FITID>{$fitid}<NAME>{$name}</STMTTRN>"
        ."</BANKTRANLIST><LEDGERBAL><BALAMT>0.00<DTASOF>{$date}</LEDGERBAL>"
        .'</STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';
}

it('parses and auto-matches a statement against the existing ledger', function () {
    postedBankEntry($this->bank, $this->revenue, 200000, '2026-01-05'); // payroll deposit
    postedBankEntry($this->bank, $this->expense, -450, '2026-01-03');    // coffee
    postedBankEntry($this->bank, $this->expense, -12000, '2026-01-06');  // hydro

    $csv = "Date,Description,Amount,Balance\n2026-01-03,COFFEE SHOP,-4.50,995.50\n2026-01-05,PAYROLL,2000.00,2995.50\n2026-01-06,HYDRO,-120.00,2875.50\n";
    $import = makeImport($this->bank, $csv);

    app(StatementImportProcessor::class)->process($import);
    $import->refresh();

    expect($import->status)->toBe(BankStatementImportStatus::Ready)
        ->and($import->line_count)->toBe(3)
        ->and($import->matched_count)->toBe(3)
        ->and($import->statement_end_balance_cents)->toBe(287550)
        ->and($import->lines()->where('match_status', StatementLineMatchStatus::Matched->value)->count())->toBe(3);
});

it('commits matches plus an added line into an in-progress reconciliation that then clears', function () {
    $deposit = postedBankEntry($this->bank, $this->revenue, 200000, '2026-01-05');
    $depositBankLineId = $deposit->lines->firstWhere('account_id', $this->bank->id)->id;

    // Balance column lets the importer derive the closing balance (199500) so the
    // reconciliation opens already balanced once the fee is added.
    $csv = "Date,Description,Amount,Balance\n2026-01-05,PAYROLL,2000.00,2000.00\n2026-01-31,MONTHLY FEE,-5.00,1995.00\n";
    $import = makeImport($this->bank, $csv);

    app(StatementImportProcessor::class)->process($import);

    // The fee is unmatched → the user chooses to add it under Bank Charges.
    $fee = $import->lines()->where('match_status', StatementLineMatchStatus::Unmatched->value)->firstOrFail();
    $fee->update([
        'match_status' => StatementLineMatchStatus::Created->value,
        'suggested_account_id' => $this->expense->id,
    ]);

    $rec = app(StatementImportCommitter::class)->commit($import->fresh());

    expect($rec->isInProgress())->toBeTrue()
        ->and($rec->markedLineIds())->toContain($depositBankLineId)
        ->and($rec->markedLineIds())->toHaveCount(2);

    // ending 199500 = 200000 deposit − 500 fee → difference 0 → completes cleanly.
    $completed = app(BankReconciliationService::class)->complete($rec);

    expect($completed->isCompleted())->toBeTrue()
        ->and(JournalLine::find($depositBankLineId)->cleared_at)->not->toBeNull();

    $import->refresh();
    expect($import->isCommitted())->toBeTrue()
        ->and($import->created_count)->toBe(1)
        ->and($import->bank_reconciliation_id)->toBe($rec->id);
});

it('flags re-uploaded transactions as duplicates so nothing clears twice', function () {
    postedBankEntry($this->bank, $this->revenue, 200000, '2026-01-05');
    $csv = "Date,Description,Amount\n2026-01-05,PAYROLL,2000.00\n";

    $first = makeImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($first);
    $first->update(['status' => BankStatementImportStatus::Committed->value]);

    $second = makeImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($second);

    $second->refresh();
    expect($second->duplicate_count)->toBe(1)
        ->and($second->lines()->first()->match_status)->toBe(StatementLineMatchStatus::Duplicate);
});

it('proposes a suggestion when the amount matches but the date is off', function () {
    postedBankEntry($this->bank, $this->revenue, 50000, '2026-01-20'); // booked 12 days later

    $csv = "Date,Description,Amount\n2026-01-05,CONSULTING,500.00\n";
    $import = makeImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($import);

    $line = $import->lines()->first();
    expect($line->match_status)->toBe(StatementLineMatchStatus::Suggested)
        ->and($line->matched_journal_line_id)->not->toBeNull();
});

it('drives the upload, review and commit wizard end to end', function () {
    $deposit = postedBankEntry($this->bank, $this->revenue, 200000, '2026-01-05');
    $depositBankLineId = $deposit->lines->firstWhere('account_id', $this->bank->id)->id;

    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    $csv = "Date,Description,Amount,Balance\n2026-01-05,PAYROLL,2000.00,2000.00\n2026-01-31,MONTHLY FEE,-5.00,1995.00\n";
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    $component = Livewire::test('pages::banking.import', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->set('upload', $file)
        ->call('startImport');

    // The sync queue ran the parse+match job inline.
    $import = BankStatementImport::query()->latest('id')->firstOrFail();
    expect($import->status)->toBe(BankStatementImportStatus::Ready)
        ->and($import->matched_count)->toBe(1);

    $fee = $import->lines()->where('match_status', StatementLineMatchStatus::Unmatched->value)->firstOrFail();

    // Choosing a category turns the unmatched fee into an "Add".
    $component->set("lineCategory.{$fee->id}", $this->expense->id);
    expect($fee->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);

    $component->call('commitImport')
        ->assertRedirect(route('banking.reconcile', ['company' => $this->company->slug, 'account' => $this->bank->id]));

    $rec = BankReconciliation::query()->forAccount($this->bank->id)->inProgress()->firstOrFail();

    expect($rec->markedLineIds())->toContain($depositBankLineId)
        ->and($rec->markedLineIds())->toHaveCount(2)
        ->and($import->fresh()->isCommitted())->toBeTrue();
});

it('parses and matches an OFX statement with no column mapping', function () {
    postedBankEntry($this->bank, $this->revenue, 200000, '2026-01-05');

    $import = makeImport($this->bank, oneTxnOfx('FIT-1', 'PAYROLL', '2000.00', '20260105'), BankStatementFormat::Ofx, 'ofx', 'application/x-ofx');
    app(StatementImportProcessor::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(BankStatementImportStatus::Ready)
        ->and($import->matched_count)->toBe(1)
        ->and($import->lines()->first()->external_id)->toBe('FIT-1');
});

it('dedups OFX re-imports by FITID even when the description changes', function () {
    $first = makeImport($this->bank, oneTxnOfx('FIT-9', 'PAYROLL', '2000.00', '20260105'), BankStatementFormat::Ofx, 'ofx', 'application/x-ofx');
    app(StatementImportProcessor::class)->process($first);
    $first->update(['status' => BankStatementImportStatus::Committed->value]);

    $second = makeImport($this->bank, oneTxnOfx('FIT-9', 'PAYROLL DEPOSIT renamed', '2000.00', '20260105'), BankStatementFormat::Ofx, 'ofx', 'application/x-ofx');
    app(StatementImportProcessor::class)->process($second);

    expect($second->fresh()->duplicate_count)->toBe(1)
        ->and($second->lines()->first()->match_status)->toBe(StatementLineMatchStatus::Duplicate);
});

it('renders the import page through the app layout', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    $this->get(route('banking.import', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Import statement');
});

it('uses the AI layer to map an unrecognizable CSV when it is enabled', function () {
    app()->instance(StatementIntelligence::class, new class implements StatementIntelligence
    {
        public function isEnabled(): bool
        {
            return true;
        }

        public function inferMapping(array $headers, array $sampleRows): ?ColumnMapping
        {
            return new ColumnMapping(
                amountMode: 'single',
                dateColumn: 'Col1',
                descriptionColumns: ['Col2'],
                amountColumn: 'Col3',
                dateFormat: 'Y-m-d',
            );
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
    });

    postedBankEntry($this->bank, $this->revenue, 200000, '2026-01-05');

    // Headers the deterministic detector cannot recognise.
    $import = makeImport($this->bank, "Col1,Col2,Col3\n2026-01-05,Payroll,2000.00\n");
    app(StatementImportProcessor::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(BankStatementImportStatus::Ready)
        ->and($import->lines()->first()->amount_cents)->toBe(200000)
        ->and($import->matched_count)->toBe(1);
});

it('pauses at NeedsMapping for an unrecognizable CSV when AI is disabled', function () {
    // Default container binding is the Null intelligence (AI off in the test env).
    $import = makeImport($this->bank, "Col1,Col2,Col3\n2026-01-05,Payroll,2000.00\n");
    app(StatementImportProcessor::class)->process($import);

    expect($import->fresh()->status)->toBe(BankStatementImportStatus::NeedsMapping);
});

it('uses AI to extract a PDF when the deterministic structurer finds nothing', function () {
    app()->instance(StatementIntelligence::class, new class implements StatementIntelligence
    {
        public function isEnabled(): bool
        {
            return true;
        }

        public function inferMapping(array $headers, array $sampleRows): ?ColumnMapping
        {
            return null;
        }

        public function extractTransactions(string $statementText): array
        {
            return [];
        }

        public function extractTransactionsFromPdf(string $absolutePath): array
        {
            return [new ParsedTransaction(
                date: CarbonImmutable::parse('2026-01-05'),
                amountCents: 200000,
                description: 'PAYROLL',
            )];
        }

        public function lastError(): ?string
        {
            return null;
        }
    });

    postedBankEntry($this->bank, $this->revenue, 200000, '2026-01-05');

    // A PDF with prose only — no date-led rows for the heuristic to find.
    $pdf = Pdf::loadHTML('<html><body><pre>Account summary. No itemised transaction rows here.</pre></body></html>')->output();
    $import = makeImport($this->bank, $pdf, BankStatementFormat::Pdf, 'pdf', 'application/pdf');

    app(StatementImportProcessor::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(BankStatementImportStatus::Ready)
        ->and($import->lines()->count())->toBe(1)
        ->and($import->matched_count)->toBe(1)
        ->and($import->parse_meta['ai_used'])->toBeTrue();
});

/** A StatementIntelligence that is enabled but whose service is unreachable. */
function downIntelligence(): StatementIntelligence
{
    return new class implements StatementIntelligence
    {
        public function isEnabled(): bool
        {
            return true;
        }

        public function inferMapping(array $headers, array $sampleRows): ?ColumnMapping
        {
            return null;
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
            return 'request_failed';
        }
    };
}

it('falls back to manual mapping and flags the outage when the AI service is down', function () {
    app()->instance(StatementIntelligence::class, downIntelligence());

    $import = makeImport($this->bank, "Col1,Col2,Col3\n2026-01-05,Payroll,2000.00\n");
    app(StatementImportProcessor::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(BankStatementImportStatus::NeedsMapping)
        ->and($import->parse_meta['ai_unavailable'] ?? false)->toBeTrue();
});

it('reports a temporary AI outage when a PDF cannot be read without it', function () {
    app()->instance(StatementIntelligence::class, downIntelligence());

    $pdf = Pdf::loadHTML('<html><body><pre>Account summary. No itemised transaction rows here.</pre></body></html>')->output();
    $import = makeImport($this->bank, $pdf, BankStatementFormat::Pdf, 'pdf', 'application/pdf');
    app(StatementImportProcessor::class)->process($import);

    $import->refresh();
    expect($import->status)->toBe(BankStatementImportStatus::Failed)
        ->and($import->error_message)->toContain('temporarily unavailable');
});

it('refuses to commit over an unconfirmed suggestion, then imports it once confirmed', function () {
    postedBankEntry($this->bank, $this->revenue, 200000, '2026-01-05');

    $csv = "Date,Description,Amount,Balance\n2026-01-05,PAYROLL,2000.00,2000.00\n2026-01-31,MONTHLY FEE,-5.00,1995.00\n";
    $import = makeImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($import);

    // The pipeline (or a rule) pre-filled a category but nobody confirmed it.
    $fee = $import->lines()->where('match_status', StatementLineMatchStatus::Unmatched->value)->firstOrFail();
    $fee->forceFill(['suggested_account_id' => $this->expense->id, 'match_reason' => 'Remembered'])->save();

    $committer = app(StatementImportCommitter::class);

    expect($committer->unconfirmedSuggestionCount($import->fresh()))->toBe(1)
        ->and(fn () => $committer->commit($import->fresh()))
        ->toThrow(PostingValidationException::class, 'not been confirmed');

    expect($import->fresh()->isCommitted())->toBeFalse()
        ->and(JournalEntry::query()->where('source_type', BankStatementImport::class)->count())->toBe(0);

    $confirmed = app(ConfirmStatementLineSuggestions::class)->handle($import->fresh());
    $rec = $committer->commit($import->fresh());

    expect($confirmed)->toBe(1)
        ->and($fee->fresh()->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($fee->fresh()->created_journal_entry_id)->not->toBeNull()
        ->and($rec->markedLineIds())->toContain((int) $fee->fresh()->matched_journal_line_id);
});

it('can import without the unconfirmed suggestions, leaving them in For Review', function () {
    $csv = "Date,Description,Amount,Balance\n2026-01-31,MONTHLY FEE,-5.00,1995.00\n";
    $import = makeImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($import);

    $fee = $import->lines()->firstOrFail();
    $fee->forceFill(['suggested_account_id' => $this->expense->id])->save();

    app(StatementImportCommitter::class)->commit($import->fresh(), null, true);

    expect($import->fresh()->isCommitted())->toBeTrue()
        ->and($fee->fresh()->match_status)->toBe(StatementLineMatchStatus::Unmatched)
        ->and($fee->fresh()->created_journal_entry_id)->toBeNull()
        ->and(BankStatementLine::query()->forReview()->whereKey($fee->id)->exists())->toBeTrue();

    // A committed import can no longer have its suggestions confirmed in bulk.
    expect(fn () => app(ConfirmStatementLineSuggestions::class)->handle($import->fresh()))
        ->toThrow(PostingValidationException::class);
});

it('confirming a line is a no-op when it carries no suggestion or is already confirmed', function () {
    $csv = "Date,Description,Amount,Balance\n2026-01-31,MONTHLY FEE,-5.00,1995.00\n";
    $import = makeImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($import);

    $fee = $import->lines()->firstOrFail();
    $action = app(ConfirmStatementLineSuggestions::class);

    expect($action->handleLine($fee))->toBeFalse();

    $fee->forceFill(['suggested_account_id' => $this->expense->id])->save();

    expect($action->handleLine($fee->fresh()))->toBeTrue()
        ->and($action->handleLine($fee->fresh()))->toBeFalse()
        ->and($fee->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);
});

it('commits a confirmed vendor line as an expense and a confirmed bill offer as a bill payment', function () {
    $vendor = Contact::factory()->vendor()->create();
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => 'BILL-77',
        'bill_date' => '2026-01-02',
        'due_date' => '2026-01-20',
        'lines' => [['account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 25000]],
    ]);
    app(BillPoster::class)->post($bill);

    $csv = "Date,Description,Amount\n2026-01-10,L SOCIO DIGITAL FEE,-120.00\n2026-01-12,BILL PAYMENT,-250.00\n";
    $import = makeImport($this->bank, $csv);
    app(StatementImportProcessor::class)->process($import);

    $lines = $import->lines()->orderBy('txn_date')->get();
    $lines[0]->forceFill(['suggested_account_id' => $this->expense->id, 'suggested_contact_id' => $vendor->id, 'match_status' => StatementLineMatchStatus::Created->value])->save();
    $lines[1]->forceFill(['suggested_contact_id' => $vendor->id, 'suggested_bill_id' => $bill->id, 'match_status' => StatementLineMatchStatus::Created->value])->save();

    $rec = app(StatementImportCommitter::class)->commit($import->fresh());

    expect(Expense::query()->where('payee_contact_id', $vendor->id)->count())->toBe(1)
        ->and(BillPayment::query()->where('contact_id', $vendor->id)->count())->toBe(1)
        ->and($bill->fresh()->balanceCents())->toBe(0)
        ->and($rec->markedLineIds())->toHaveCount(2);
});
