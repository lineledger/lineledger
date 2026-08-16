<?php

use App\Actions\Documents\MatchInboxItemToStatementLine;
use App\Actions\Documents\PromoteInboxItem;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Enums\StatementLineMatchStatus;
use App\Jobs\Inbox\ProcessInboxItem;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\InboxItem;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Inbox\OCR\ClaudeReceiptIntelligence;
use App\Services\Inbox\OCR\Contracts\ReceiptIntelligence;
use App\Services\Inbox\OCR\NullReceiptIntelligence;
use App\Services\Inbox\OCR\ReceiptTaxMapper;
use App\Services\Posting\ExpensePoster;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Stage an inbox item with a real on-disk attachment so the job can read it.
 */
function stageInboxItem(Company $company, User $user, string $mime = 'application/pdf'): InboxItem
{
    $item = InboxItem::create([
        'source' => InboxItemSource::Upload,
        'status' => InboxItemStatus::Pending,
        'original_filename' => 'receipt.pdf',
        'mime' => $mime,
        'created_by_user_id' => $user->id,
    ]);

    // Place a real blob on the fake disk and link it polymorphically, the way
    // AttachmentService would (UploadedFile::fake() is not a TemporaryUploadedFile,
    // so the service's upload() can't be exercised outside a Livewire round-trip).
    $path = 'attachments/'.$company->id.'/inbox_items/'.$item->id.'/'.Str::ulid().'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 fake receipt');

    $attachment = Attachment::create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'receipt.pdf',
        'mime_type' => $mime,
        'size_bytes' => 21,
        'uploaded_by_id' => $user->id,
    ]);

    $item->forceFill(['attachment_id' => $attachment->id])->save();

    return $item->refresh();
}

it('leaves the item in needs_review with no error on the Null OCR path', function () {
    app()->bind(ReceiptIntelligence::class, fn () => new NullReceiptIntelligence);

    $item = stageInboxItem($this->company, $this->user);

    (new ProcessInboxItem($item->id))->handle(app(ReceiptIntelligence::class));

    $item->refresh();

    expect($item->status)->toBe(InboxItemStatus::NeedsReview)
        ->and($item->ocr_error)->toBeNull()
        ->and($item->extracted)->toBeNull()
        ->and($item->suggested_document_type)->toBe('bill');
});

it('extracts vendor, amount and date from a faked Claude OCR response', function () {
    config()->set('inbox.ai.enabled', true);
    config()->set('services.anthropic.key', 'test-key');

    // Operator + tenant gates both open.
    $this->company->forceFill(['settings' => ['inbox' => ['ocr_enabled' => true]]])->save();

    Http::fake([
        '*/v1/messages' => Http::response([
            'content' => [[
                'type' => 'tool_use',
                'name' => 'provide_receipt',
                'input' => [
                    'vendor' => 'Staples Canada',
                    'total' => 42.50,
                    'currency' => 'CAD',
                    'date' => '2026-06-20',
                    'line_items' => [
                        ['description' => 'Printer paper', 'amount' => 42.50],
                    ],
                ],
            ]],
        ], 200),
    ]);

    app()->bind(ReceiptIntelligence::class, fn () => new ClaudeReceiptIntelligence(
        apiKey: 'test-key',
        baseUrl: 'https://api.anthropic.com',
        model: 'claude-sonnet-4-6',
    ));

    // A vendor that the fuzzy matcher should pick up.
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Staples Canada']);

    $item = stageInboxItem($this->company, $this->user);

    (new ProcessInboxItem($item->id))->handle(app(ReceiptIntelligence::class));

    $item->refresh();

    expect($item->status)->toBe(InboxItemStatus::NeedsReview)
        ->and($item->ocr_error)->toBeNull()
        ->and($item->extracted['vendor'])->toBe('Staples Canada')
        ->and($item->extracted['amount_cents'])->toBe(4250)
        ->and($item->extracted['currency'])->toBe('CAD')
        ->and($item->extracted['date'])->toBe('2026-06-20')
        ->and($item->suggested_contact_id)->toBe($vendor->id)
        ->and($item->suggested_document_type)->toBe('bill');
});

it('renders the index and review pages for a needs-review item', function () {
    $item = stageInboxItem($this->company, $this->user);
    $item->forceFill([
        'status' => InboxItemStatus::NeedsReview->value,
        'suggested_document_type' => 'bill',
        'extracted' => ['vendor' => 'Acme', 'amount_cents' => 4250, 'currency' => 'CAD', 'date' => '2026-06-20'],
    ])->save();

    $this->get(route('inbox.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('inbox-dropzone', false);

    // Regression: the review page used Flux Pro listbox selects, which 500 on the
    // free Flux build the project ships with.
    $this->get(route('inbox.show', ['company' => $this->company->slug, 'item' => $item->id]))
        ->assertOk()
        ->assertSee('inbox-line-account', false);
});

it('previews an image receipt with an <img> tag, never a blocked iframe', function () {
    // X-Frame-Options: DENY blanks any framed preview, so images must render inline.
    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();

    $this->get(route('inbox.show', ['company' => $this->company->slug, 'item' => $item->id]))
        ->assertOk()
        ->assertSee('<img', false)
        ->assertSee('inline=1', false)
        ->assertDontSee('<iframe', false);
});

it('hints to enable scanning in settings when the company toggle is off', function () {
    // Company toggle off (default) → point the owner at the settings page.
    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();

    $this->get(route('inbox.show', ['company' => $this->company->slug, 'item' => $item->id]))
        ->assertOk()
        ->assertSee('Auto-fill is off')
        ->assertSee('turned off for this organization')
        ->assertSee('Open inbox settings');
});

it('says scanning is unavailable on the server when only the operator gate is off', function () {
    // The owner has flipped the company toggle on, but the operator switch/key
    // is off — the message must not tell them to "turn it on", it already is.
    config()->set('inbox.ai.enabled', false);
    $this->company->setInboxState(['ocr_enabled' => true]);

    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();

    $this->get(route('inbox.show', ['company' => $this->company->slug, 'item' => $item->id]))
        ->assertOk()
        ->assertSee('Auto-fill is off')
        ->assertSee('not available on this server')
        ->assertDontSee('Open inbox settings');
});

it('shows no auto-fill hint when both OCR gates are on', function () {
    config()->set('inbox.ai.enabled', true);
    config()->set('inbox.ai.driver', 'http');
    config()->set('services.anthropic.key', 'test-key');
    $this->company->setInboxState(['ocr_enabled' => true]);

    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();

    $this->get(route('inbox.show', ['company' => $this->company->slug, 'item' => $item->id]))
        ->assertOk()
        ->assertDontSee('Auto-fill is off');
});

it('promotes an item into a DRAFT bill with the attachment carried over', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies']);
    $account = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();

    $item = stageInboxItem($this->company, $this->user);
    $item->forceFill([
        'status' => InboxItemStatus::NeedsReview->value,
        'suggested_contact_id' => $vendor->id,
        'suggested_document_type' => 'bill',
        'extracted' => [
            'vendor' => 'Acme Supplies',
            'amount_cents' => 12300,
            'currency' => 'CAD',
            'date' => '2026-06-15',
            'line_items' => [['description' => 'Widgets', 'amount_cents' => 12300]],
        ],
    ])->save();

    $attachmentId = $item->attachment_id;

    $bill = app(PromoteInboxItem::class)->handle($item->refresh(), [
        'document_type' => 'bill',
        'contact_id' => $vendor->id,
        'account_id' => $account->id,
    ]);

    $item->refresh();

    // Draft, never posted.
    expect($bill->status)->toBe(BillStatus::Draft)
        ->and($bill->journal_entry_id)->toBeNull()
        ->and($bill->total_cents)->toBe(12300)
        ->and($item->status)->toBe(InboxItemStatus::Promoted)
        ->and($item->promoted_document_type)->toBe('bill')
        ->and($item->promoted_document_id)->toBe($bill->id);

    // The OCR'd file is now attached to the bill.
    $attachment = Attachment::findOrFail($attachmentId);
    expect($attachment->attachable_type)->toBe($bill->getMorphClass())
        ->and($attachment->attachable_id)->toBe($bill->id);
});

it('promotes an item into a DRAFT expense', function () {
    $account = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $payment = Account::query()->where('type', AccountType::Asset->value)->orderBy('code')->firstOrFail();

    $item = stageInboxItem($this->company, $this->user);
    $item->forceFill([
        'status' => InboxItemStatus::NeedsReview->value,
        'suggested_document_type' => 'expense',
        'extracted' => [
            'vendor' => 'Coffee Shop',
            'amount_cents' => 850,
            'currency' => 'CAD',
            'date' => '2026-06-10',
            'line_items' => [],
        ],
    ])->save();

    $expense = app(PromoteInboxItem::class)->handle($item->refresh(), [
        'document_type' => 'expense',
        'account_id' => $account->id,
        'payment_account_id' => $payment->id,
    ]);

    $item->refresh();

    expect($expense)->toBeInstanceOf(Expense::class)
        ->and($expense->status->value)->toBe('draft')
        ->and($expense->journal_entry_id)->toBeNull()
        ->and($expense->amount_cents)->toBe(850)
        ->and($item->status)->toBe(InboxItemStatus::Promoted)
        ->and($item->promoted_document_type)->toBe('expense');
});

it('scopes inbox items to the current company', function () {
    $mine = stageInboxItem($this->company, $this->user);

    $other = Company::factory()->create();
    app()->instance('current_company', $other);

    expect(InboxItem::query()->count())->toBe(0);

    app()->instance('current_company', $this->company);

    expect(InboxItem::query()->pluck('id')->all())->toBe([$mine->id]);
});

it('promotes a receipt with GST split out as an input tax credit', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $account = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();

    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();

    // $100.00 pre-tax + $5.00 GST.
    $expense = app(PromoteInboxItem::class)->handle($item->refresh(), [
        'document_type' => 'expense',
        'vendor' => 'Acme Supplies',
        'payment_account_id' => $bank->id,
        'lines' => [[
            'account_id' => $account->id,
            'description' => 'Supplies',
            'amount_cents' => 10000,
            'tax_code_id' => $gst->id,
            'tax_override_cents' => 500,
        ]],
    ]);

    $line = $expense->lines()->first();
    expect($line->tax_code_id)->toBe($gst->id)
        ->and($line->tax_cents)->toBe(500);

    // Posting must break the GST out as a DEBIT to the agency's payable (the ITC).
    app(ExpensePoster::class)->post($expense->refresh());

    $payableAccountId = $gst->agency->payable_account_id;
    $itcLine = $expense->journalEntry->lines()->where('account_id', $payableAccountId)->first();

    expect($itcLine)->not->toBeNull()
        ->and((int) $itcLine->debit_cents)->toBe(500);
});

it('promotes a receipt into a reimbursement bill owed to an employee', function () {
    $employee = Contact::factory()->create(['is_employee' => true, 'display_name' => 'Business Owner']);
    $account = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();

    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();

    $bill = app(PromoteInboxItem::class)->handle($item->refresh(), [
        'document_type' => 'reimbursement',
        'contact_id' => $employee->id,
        'lines' => [['account_id' => $account->id, 'amount_cents' => 5000]],
    ]);

    $item->refresh();

    expect($bill)->toBeInstanceOf(Bill::class)
        ->and($bill->bill_type)->toBe(BillType::Reimbursement)
        ->and($bill->contact_id)->toBe($employee->id)
        ->and($bill->status)->toBe(BillStatus::Draft)
        ->and($item->promoted_document_type)->toBe('reimbursement');
});

it('refuses to create a reimbursement without an employee contact', function () {
    $account = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();

    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();

    expect(fn () => app(PromoteInboxItem::class)->handle($item->refresh(), [
        'document_type' => 'reimbursement',
        'contact_id' => null,
        'lines' => [['account_id' => $account->id, 'amount_cents' => 5000]],
    ]))->toThrow(RuntimeException::class);
});

it('maps an extracted GST tax line to the company GST tax code', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();

    $mapped = app(ReceiptTaxMapper::class)->map([
        'amount_cents' => 10500,
        'subtotal_cents' => 10000,
        'taxes' => [['label' => 'GST', 'rate_bp' => 500, 'amount_cents' => 500]],
    ]);

    expect($mapped['taxes'][0]['tax_code_id'])->toBe($gst->id);
});

it('extracts subtotal and tax lines from OCR and maps GST to the ITC code', function () {
    config()->set('inbox.ai.enabled', true);
    config()->set('services.anthropic.key', 'test-key');
    $this->company->forceFill(['settings' => ['inbox' => ['ocr_enabled' => true]]])->save();

    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();

    // The Bar Oso receipt: $249.00 subtotal, GST 5% $12.45, a 10% levy $13.00, $274.45 total.
    Http::fake([
        '*/v1/messages' => Http::response([
            'content' => [[
                'type' => 'tool_use',
                'name' => 'provide_receipt',
                'input' => [
                    'vendor' => 'Bar Oso',
                    'subtotal' => 249.00,
                    'total' => 274.45,
                    'currency' => 'CAD',
                    'date' => '2026-02-27',
                    'taxes' => [
                        ['label' => 'GST', 'rate' => 5, 'amount' => 12.45],
                        ['label' => 'PLT', 'rate' => 10, 'amount' => 13.00],
                    ],
                    'line_items' => [],
                ],
            ]],
        ], 200),
    ]);

    app()->bind(ReceiptIntelligence::class, fn () => new ClaudeReceiptIntelligence(
        apiKey: 'test-key',
        baseUrl: 'https://api.anthropic.com',
        model: 'claude-sonnet-4-6',
    ));

    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');

    (new ProcessInboxItem($item->id))->handle(app(ReceiptIntelligence::class));

    $item->refresh();

    expect($item->extracted['subtotal_cents'])->toBe(24900)
        ->and($item->extracted['amount_cents'])->toBe(27445)
        ->and($item->extracted['taxes'])->toHaveCount(2);

    // GST maps to the recoverable GST code; the unrecognised 10% levy stays unmapped.
    $gstTax = collect($item->extracted['taxes'])->firstWhere('label', 'GST');
    $levy = collect($item->extracted['taxes'])->firstWhere('label', 'PLT');

    expect($gstTax['amount_cents'])->toBe(1245)
        ->and($gstTax['tax_code_id'])->toBe($gst->id)
        ->and($levy['tax_code_id'])->toBeNull();
});

it('matches a receipt to a bank line in one posted entry with the ITC and no double-post', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $account = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();

    $import = BankStatementImport::factory()->create(['account_id' => $bank->id]);

    // $105.00 left the bank ($100.00 + $5.00 GST). Signed negative = money out.
    $line = BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
        'amount_cents' => -10500,
        'match_status' => StatementLineMatchStatus::Unmatched->value,
    ]);

    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();
    $attachmentId = $item->attachment_id;

    $entriesBefore = JournalEntry::query()->count();

    $expense = app(MatchInboxItemToStatementLine::class)->handle($item->refresh(), $line->refresh(), [
        'vendor' => 'Acme Supplies',
        'lines' => [[
            'account_id' => $account->id,
            'amount_cents' => 10000,
            'tax_code_id' => $gst->id,
            'tax_override_cents' => 500,
        ]],
    ]);

    $line->refresh();
    $expense->refresh();

    // Exactly ONE new entry — no second posting path. The line clears against it.
    expect(JournalEntry::query()->count())->toBe($entriesBefore + 1)
        ->and($line->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->created_journal_entry_id)->toBe($expense->journal_entry_id)
        ->and($expense->journal_entry_id)->not->toBeNull();

    // GST debits the agency payable (ITC); the bank account is credited the full $105.
    $entry = $expense->journalEntry;
    $itc = $entry->lines()->where('account_id', $gst->agency->payable_account_id)->first();
    $bankLeg = $entry->lines()->where('account_id', $bank->id)->first();

    expect((int) $itc->debit_cents)->toBe(500)
        ->and((int) $bankLeg->credit_cents)->toBe(10500)
        ->and($line->matched_journal_line_id)->toBe($bankLeg->id);

    // The receipt rode along onto the expense.
    $att = Attachment::find($attachmentId);
    expect($att->attachable_type)->toBe($expense->getMorphClass())
        ->and($att->attachable_id)->toBe($expense->id);
});

it('refuses to match a bank line that is already recorded', function () {
    $account = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();

    $import = BankStatementImport::factory()->create(['account_id' => $bank->id]);
    $entry = JournalEntry::create(['entry_no' => 'JE-INBOX-TEST', 'entry_date' => '2026-01-01', 'is_posted' => true]);
    $line = BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
        'amount_cents' => -5000,
        'match_status' => StatementLineMatchStatus::Created->value,
        'created_journal_entry_id' => $entry->id,
    ]);

    $item = stageInboxItem($this->company, $this->user, 'image/jpeg');
    $item->forceFill(['status' => InboxItemStatus::NeedsReview->value])->save();

    expect(fn () => app(MatchInboxItemToStatementLine::class)->handle($item->refresh(), $line->refresh(), [
        'vendor' => 'Acme',
        'lines' => [['account_id' => $account->id, 'amount_cents' => 5000]],
    ]))->toThrow(RuntimeException::class);
});
