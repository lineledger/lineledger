<?php

use App\Actions\Companies\PurgeCompany;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DocumentFolder;
use App\Models\InboxItem;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MemorizedReport;
use App\Models\StockAdjustment;
use App\Models\StockLayer;
use App\Models\TaxReturn;
use App\Models\UserPreference;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\StockAdjustmentPoster;
use App\Support\Storage\StorageDisks;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Backup\AdversarialBackupFixture;
use Tests\Support\Backup\BackupRoundTrip;

/**
 * Each thing restores used to get wrong, checked by using the restored copy
 * the way the app would: the comparison in DemoRoundTripTest proves the rows
 * are identical, these prove the identical rows *work*.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->seed(DemoCompanySeeder::class);
    app()->forgetInstance('current_company');

    $this->source = BackupRoundTrip::company('demo');
    AdversarialBackupFixture::apply($this->source);

    $this->owner = $this->source->owner();
    $trace = BackupRoundTrip::restore(BackupRoundTrip::bundle($this->source), $this->owner);
    $this->copy = Company::query()->withoutGlobalScopes()->findOrFail($trace->companyId);
    $this->trace = $trace;

    // Read and act on the copy as its owner would.
    app()->instance('current_company', $this->copy);
    Auth::login($this->owner);
});

afterEach(function () {
    Auth::logout();
    app()->forgetInstance('current_company');
});

it('links a voided entry to the copy\'s own reversal', function () {
    $voided = JournalEntry::query()->whereNotNull('reversed_by_entry_id')->get();

    expect($voided)->not->toBeEmpty();

    foreach ($voided as $entry) {
        $reversal = JournalEntry::query()->findOrFail($entry->reversed_by_entry_id);
        expect($reversal->reverses_entry_id)->toBe($entry->id);
    }
});

it('points each journal entry and audit row at the copy\'s own documents', function () {
    $sourceInvoiceNumbers = Invoice::query()->withoutGlobalScopes()->where('company_id', $this->source->id)->pluck('invoice_no', 'id');

    $invoiceEntries = JournalEntry::query()->where('source_type', Invoice::class)->get();
    expect($invoiceEntries)->not->toBeEmpty();

    foreach ($invoiceEntries as $entry) {
        // Scoped to the copy: a source id would find nothing (or another organization's row).
        expect(Invoice::query()->find($entry->source_id))->not->toBeNull();
    }

    $audited = AccountingAuditLog::query()->where('auditable_type', Invoice::class)->where('auditable_id', '!=', 0)->get();
    expect($audited)->not->toBeEmpty();

    foreach ($audited as $row) {
        expect(Invoice::query()->find($row->auditable_id))->not->toBeNull();
    }

    expect(Invoice::query()->pluck('invoice_no')->sort()->values()->all())
        ->toBe($sourceInvoiceNumbers->sort()->values()->all());
});

it('puts stock back on the copy\'s own FIFO layers when a restored sale is voided', function () {
    $widget = Item::query()->where('name', 'Backup Widget')->firstOrFail();
    $remaining = fn (): float => (float) StockLayer::query()->where('item_id', $widget->id)->sum('qty_remaining');

    expect($remaining())->toBe(4.0);

    $sale = Invoice::query()->whereHas('lines', fn ($q) => $q->where('item_id', $widget->id))->firstOrFail();
    app(InvoicePoster::class)->void($sale);

    expect($remaining())->toBe(10.0);
});

it('reverses a restored stock adjustment\'s stock when it is voided', function () {
    // The demo's opening count of 50 mugs, none sold since. A restore that
    // lost the adjustment's movements voided the entry but not the stock.
    $mug = Item::query()->where('name', 'Branded Mug')->firstOrFail();
    $adjustment = StockAdjustment::query()->whereHas('lines', fn ($q) => $q->where('item_id', $mug->id))->firstOrFail();
    $before = (float) $mug->fresh()->qty_on_hand_cached;

    app(StockAdjustmentPoster::class)->void($adjustment);

    expect($before)->toBe(50.0)
        ->and((float) $mug->fresh()->qty_on_hand_cached)->toBe(0.0);
});

it('keeps each parent created after its child', function () {
    expect(Account::query()->where('code', '6999')->firstOrFail()->id)
        ->toBe(Account::query()->whereNotNull('parent_id')->where('code', '!=', '6999')->firstOrFail()->parent_id)
        ->and(Contact::query()->whereNotNull('parent_id')->firstOrFail()->parent_id)
        ->toBe(Contact::query()->where('display_name', 'Backup Parent Customer')->firstOrFail()->id)
        ->and(ItemCategory::query()->where('name', 'Backup Child Category')->firstOrFail()->parent_id)
        ->toBe(ItemCategory::query()->where('name', 'Backup Parent Category')->firstOrFail()->id)
        ->and(DocumentFolder::query()->where('name', 'Backup Child Folder')->firstOrFail()->parent_folder_id)
        ->toBe(DocumentFolder::query()->where('name', 'Backup Parent Folder')->firstOrFail()->id);
});

it('keeps the inbox document attached to its item, and the item promoted to the copy\'s bill', function () {
    $item = InboxItem::query()->where('original_filename', 'receipt.pdf')->firstOrFail();
    $document = Attachment::query()->findOrFail($item->attachment_id);

    expect($document->attachable_type)->toBe(InboxItem::class)
        ->and($document->attachable_id)->toBe($item->id)
        ->and(Storage::disk($document->disk)->get($document->path))->toBe('inbox receipt bytes')
        ->and(Bill::query()->find($item->promoted_document_id))->not->toBeNull();
});

it('leaves out an attachment whose record was gone before the backup', function () {
    expect(Attachment::query()->where('original_filename', 'orphan.pdf')->exists())->toBeFalse()
        ->and($this->trace->skipped['attachments'] ?? [])->toHaveCount(1);
});

it('gives the copy its own logo files, which outlive the original', function () {
    $logos = Storage::disk(StorageDisks::logos());

    expect($this->copy->logo_path)->not->toBe($this->source->logo_path)
        ->and($this->copy->document_logo_path)->not->toBe($this->source->document_logo_path);

    $this->source->delete();
    app(PurgeCompany::class)->handle(Company::withTrashed()->findOrFail($this->source->id));

    expect($logos->get($this->copy->logo_path))->toBe('logo-bytes')
        ->and($logos->get($this->copy->document_logo_path))->toBe('document-logo-bytes');
});

it('remaps the ids held inside JSON columns', function () {
    $bank = Account::query()->findOrFail(
        array_key_first($this->copy->fresh()->settings['reconciliation']['defaults'])
    );
    $copyLineIds = JournalLine::query()->whereHas('journalEntry')->pluck('id')->all();

    $defaults = $this->copy->reconciliationDefaults($bank->id);
    expect(Account::query()->find($defaults['service_charge_account_id']))->not->toBeNull()
        ->and(Account::query()->find($defaults['interest_account_id']))->not->toBeNull()
        ->and(UserPreference::query()->where('pref_key', 'banking.last_account')->value('value'))->toBe($bank->id);

    $marked = BankReconciliation::query()->whereNotNull('marked_line_ids')->get()->flatMap->marked_line_ids->all();
    $excluded = TaxReturn::query()->where('tax_return_no', 'TR-BACKUP-1')->firstOrFail()->excluded_journal_line_ids;

    expect($marked)->not->toBeEmpty()
        ->and(array_diff($marked, $copyLineIds))->toBe([])
        ->and($excluded)->toHaveCount(2)
        ->and(array_diff($excluded, $copyLineIds))->toBe([]);

    $settings = MemorizedReport::query()->whereNotNull('settings')->latest('id')->firstOrFail()->settings;
    expect(Account::query()->find($settings['accountId'])?->code)->not->toBeNull()
        ->and(Contact::query()->find($settings['contactId']))->not->toBeNull()
        ->and($settings['perPage'])->toBe(50);
});

it('restores budgets and the bank feed', function () {
    $budget = Budget::query()->where('name', 'Backup Budget')->firstOrFail();

    expect($budget->lines()->count())->toBe(1)
        ->and(Account::query()->find($budget->lines()->first()->account_id))->not->toBeNull();

    $matched = BankStatementLine::query()->whereNotNull('matched_journal_line_id')->firstOrFail();
    $suggested = BankStatementLine::query()->whereNotNull('suggested_bill_allocations')->firstOrFail();

    expect(JournalLine::query()->whereHas('journalEntry')->whereKey($matched->matched_journal_line_id)->exists())->toBeTrue()
        ->and($matched->import->profile)->not->toBeNull()
        ->and(Bill::query()->find($suggested->suggested_bill_allocations[0]['bill_id']))->not->toBeNull();
});

it('keeps employee portal passwords and soft-deleted rows', function () {
    $employee = Contact::query()->where('display_name', 'Backup Employee')->firstOrFail();

    expect(Hash::check('portal-secret', (string) $employee->getAuthPassword()))->toBeTrue()
        ->and(InboxItem::onlyTrashed()->where('original_filename', 'discarded.pdf')->exists())->toBeTrue();
});
