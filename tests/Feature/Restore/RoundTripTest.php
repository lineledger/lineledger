<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\CompanyRestore;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\DocumentFolder;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Membership;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockAdjustment;
use App\Models\TaxCode;
use App\Models\TaxReturn;
use App\Models\User;
use App\Models\VendorCredit;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Backup\CompanyExporter;
use App\Services\Restore\CompanyImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    // ---- Two users for user-remap exercise ----
    $this->alice = User::factory()->create([
        'name' => 'Alice Owner',
        'email' => 'alice@acme.test',
    ]);
    $this->bob = User::factory()->create([
        'name' => 'Bob Bookkeeper',
        'email' => 'bob@acme.test',
    ]);

    // ---- Company A seeded with realistic data ----
    $this->companyA = Company::factory()->create(['name' => 'Acme Inc.']);
    Membership::create([
        'company_id' => $this->companyA->id,
        'user_id' => $this->alice->id,
        'role' => CompanyRole::Owner,
    ]);

    app()->instance('current_company', $this->companyA);

    $this->customer = Contact::factory()->customer()->create(['display_name' => 'Wile E. Coyote']);
    $this->vendor = Contact::factory()->vendor()->create(['display_name' => 'Acme Supplies']);

    $this->bankAccount = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->firstOrFail();
    $this->incomeAccount = Account::query()
        ->where('subtype', AccountSubtype::Income->value)
        ->firstOrFail();
    $this->expenseAccount = Account::query()
        ->where('subtype', AccountSubtype::Expense->value)
        ->firstOrFail();

    // Account-level default tax code — exercises the deferred
    // accounts.default_tax_code_id FK backfill (accounts restore before
    // tax_codes, so the value can't be set on the primary insert).
    $this->taxCode = TaxCode::create([
        'code' => 'RT-TAX',
        'name' => 'Round Trip Tax',
        'rate_basis_points' => 500,
        'applies_to' => TaxAppliesTo::Both,
        'is_active' => true,
    ]);
    $this->expenseAccount->update(['default_tax_code_id' => $this->taxCode->id]);

    $this->item = Item::factory()->create([
        'name' => 'Widget',
        'default_price_cents' => 5000,
    ]);

    // Invoice with 3 lines (Draft so the GL isn't auto-posted).
    $this->invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-1001',
        'invoice_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'status' => InvoiceStatus::Draft,
    ]);
    foreach ([1, 2, 3] as $i) {
        InvoiceLine::create([
            'invoice_id' => $this->invoice->id,
            'item_id' => $this->item->id,
            'account_id' => $this->incomeAccount->id,
            'description' => 'Widget '.$i,
            'quantity' => $i,
            'unit_price_cents' => 1000 * $i,
            'line_subtotal_cents' => 1000 * $i,
            'line_total_cents' => 1000 * $i,
            'line_order' => $i,
        ]);
    }

    // Bill with 2 lines, Draft.
    $this->bill = Bill::create([
        'contact_id' => $this->vendor->id,
        'bill_no' => 'BILL-2001',
        'bill_date' => '2026-05-02',
        'due_date' => '2026-06-01',
    ]);
    foreach ([1, 2] as $i) {
        BillLine::create([
            'bill_id' => $this->bill->id,
            'item_id' => $this->item->id,
            'account_id' => $this->expenseAccount->id,
            'description' => 'Supplies '.$i,
            'quantity' => $i,
            'unit_price_cents' => 750 * $i,
            'line_subtotal_cents' => 750 * $i,
            'line_total_cents' => 750 * $i,
            'line_order' => $i,
        ]);
    }

    // A balanced posted journal entry with two lines — posted_by_user_id is
    // Bob so the user-remap path is exercised end-to-end.
    $this->journalEntry = JournalEntry::create([
        'entry_no' => 'JE-001',
        'entry_date' => CarbonImmutable::create(2026, 5, 3),
        'memo' => 'opening balance',
        'posted_by_user_id' => $this->bob->id,
        'is_posted' => true,
        'posted_at' => CarbonImmutable::create(2026, 5, 3),
    ]);
    $this->journalEntry->lines()->create([
        'account_id' => $this->bankAccount->id,
        'debit_cents' => 100_000,
        'credit_cents' => 0,
        'line_order' => 0,
    ]);
    $this->journalEntry->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'debit_cents' => 0,
        'credit_cents' => 100_000,
        'line_order' => 1,
    ]);

    // A real attachment blob — gets copied through the round-trip.
    Storage::disk('local')->put(
        'attachments/'.$this->companyA->id.'/customer-portrait.png',
        'PORTRAIT-PNG-BYTES',
    );
    $this->attachment = Attachment::create([
        'attachable_type' => (new Contact)->getMorphClass(),
        'attachable_id' => $this->customer->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->companyA->id.'/customer-portrait.png',
        'original_filename' => 'portrait.png',
        'mime_type' => 'image/png',
        'size_bytes' => strlen('PORTRAIT-PNG-BYTES'),
        'uploaded_by_id' => $this->bob->id,
    ]);

    // ---- Document repository: a folder shared with Bob, holding one document. ----
    // Bob is a non-owner member, so his membership IS imported and remapped —
    // exercising both the created_by_member_id and viewer_member_ids remaps.
    $this->bobMembership = Membership::create([
        'company_id' => $this->companyA->id,
        'user_id' => $this->bob->id,
        'role' => CompanyRole::Accountant,
    ]);

    $this->folder = DocumentFolder::create([
        'name' => 'Incorporation',
        'viewer_member_ids' => [$this->bobMembership->id],
        'created_by_user_id' => $this->bob->id,
        'created_by_member_id' => $this->bobMembership->id,
    ]);

    Storage::disk('local')->put(
        'attachments/'.$this->companyA->id.'/articles.pdf',
        'ARTICLES-PDF-BYTES',
    );
    Attachment::create([
        'attachable_type' => (new DocumentFolder)->getMorphClass(),
        'attachable_id' => $this->folder->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->companyA->id.'/articles.pdf',
        'original_filename' => 'articles-of-incorporation.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen('ARTICLES-PDF-BYTES'),
        'uploaded_by_id' => $this->bob->id,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('round-trips a company through export and import', function () {
    // ---- Export ----
    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $this->alice->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);
    app(CompanyExporter::class)->export($backup);
    $backup->refresh();
    expect($backup->status)->toBe(CompanyBackupStatus::Ready);

    $zipAbs = Storage::disk('local')->path($backup->file_path);
    expect(file_exists($zipAbs))->toBeTrue();

    // ---- Move the ZIP into the restore location ----
    $restoreRelative = 'restores/'.basename($zipAbs);
    Storage::disk('local')->put($restoreRelative, file_get_contents($zipAbs));

    $restore = CompanyRestore::create([
        'requested_by_user_id' => $this->alice->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $restoreRelative,
        'file_size_bytes' => Storage::disk('local')->size($restoreRelative),
        'sha256' => hash_file('sha256', Storage::disk('local')->path($restoreRelative)),
    ]);

    // Simulate a fresh runtime — the worker process binds `current_company`
    // itself, and we want the importer's own binding to be the only one.
    app()->forgetInstance('current_company');

    app(CompanyImporter::class)->import($restore);
    $restore->refresh();

    expect($restore->status)->toBe(CompanyRestoreStatus::Completed)
        ->and($restore->company_id)->not->toBeNull()
        ->and($restore->completed_at)->not->toBeNull();

    $companyB = Company::find($restore->company_id);
    expect($companyB)->not->toBeNull()
        ->and($companyB->id)->not->toBe($this->companyA->id)
        ->and($companyB->name)->toBe('Acme Inc.');

    // ---- 1. Row counts per BackupTableRegistry table match A vs B. ----
    //
    // Some child tables (journal_lines, receipt_applications, *_lines) don't
    // carry a `company_id` column themselves — they're scoped through their
    // parent. For those, we count via the corresponding registry parent's id
    // subquery, mirroring the exporter's `parentIds` strategy.
    $childScopes = [
        'journal_lines' => ['journal_entry_id', JournalEntry::class],
        'invoice_lines' => ['invoice_id', Invoice::class],
        'estimate_lines' => ['estimate_id', Estimate::class],
        'sales_order_lines' => ['sales_order_id', SalesOrder::class],
        'credit_memo_lines' => ['credit_memo_id', CreditMemo::class],
        'bill_lines' => ['bill_id', Bill::class],
        'purchase_order_lines' => ['purchase_order_id', PurchaseOrder::class],
        'vendor_credit_lines' => ['vendor_credit_id', VendorCredit::class],
        'cheque_lines' => ['cheque_id', Cheque::class],
        'tax_return_lines' => ['tax_return_id', TaxReturn::class],
        'stock_adjustment_lines' => ['stock_adjustment_id', StockAdjustment::class],
        'receipt_applications' => ['customer_receipt_id', CustomerReceipt::class],
        'bill_payment_applications' => ['bill_payment_id', BillPayment::class],
        'deposit_lines' => ['deposit_id', Deposit::class],
    ];

    foreach (BackupTableRegistry::tables() as $entry) {
        if ($entry['table'] === 'companies') {
            continue;
        }

        $modelClass = $entry['model'];
        $tableName = $entry['table'];

        if (Schema::hasColumn($tableName, 'company_id')) {
            $aCount = $modelClass::query()
                ->withoutGlobalScopes()
                ->where('company_id', $this->companyA->id)
                ->count();
            $bCount = $modelClass::query()
                ->withoutGlobalScopes()
                ->where('company_id', $companyB->id)
                ->count();
        } elseif (isset($childScopes[$tableName])) {
            [$fk, $parentClass] = $childScopes[$tableName];
            $aCount = $modelClass::query()
                ->withoutGlobalScopes()
                ->whereIn($fk, $parentClass::query()->withoutGlobalScopes()->where('company_id', $this->companyA->id)->select('id'))
                ->count();
            $bCount = $modelClass::query()
                ->withoutGlobalScopes()
                ->whereIn($fk, $parentClass::query()->withoutGlobalScopes()->where('company_id', $companyB->id)->select('id'))
                ->count();
        } else {
            continue;
        }

        expect($bCount)->toBe($aCount, "Row count mismatch for table {$tableName}: A={$aCount}, B={$bCount}");
    }

    // ---- 2. GL trial balance: posted debits = posted credits in both companies. ----
    //
    // `journal_lines` is scoped through `journal_entries.company_id`, not via
    // its own column. The denormalised `is_posted` flag lives on the line.
    $glA = (int) (DB::table('journal_lines')
        ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
        ->where('journal_entries.company_id', $this->companyA->id)
        ->where('journal_lines.is_posted', true)
        ->selectRaw('SUM(journal_lines.debit_cents - journal_lines.credit_cents) as net')
        ->value('net') ?? 0);
    $glB = (int) (DB::table('journal_lines')
        ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
        ->where('journal_entries.company_id', $companyB->id)
        ->where('journal_lines.is_posted', true)
        ->selectRaw('SUM(journal_lines.debit_cents - journal_lines.credit_cents) as net')
        ->value('net') ?? 0);

    expect($glA)->toBe(0)
        ->and($glB)->toBe(0);

    // ---- 3. Per-account totals match A → B (proves account FK remap). ----
    $totalsByCodeA = DB::table('journal_lines')
        ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
        ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
        ->where('journal_entries.company_id', $this->companyA->id)
        ->where('journal_lines.is_posted', true)
        ->groupBy('accounts.code')
        ->selectRaw('accounts.code, SUM(journal_lines.debit_cents - journal_lines.credit_cents) as net')
        ->pluck('net', 'accounts.code');

    $totalsByCodeB = DB::table('journal_lines')
        ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
        ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
        ->where('journal_entries.company_id', $companyB->id)
        ->where('journal_lines.is_posted', true)
        ->groupBy('accounts.code')
        ->selectRaw('accounts.code, SUM(journal_lines.debit_cents - journal_lines.credit_cents) as net')
        ->pluck('net', 'accounts.code');

    expect($totalsByCodeB->toArray())->toBe($totalsByCodeA->toArray());

    // ---- 3b. Deferred FK: accounts.default_tax_code_id backfilled post-loop. ----
    // The column is stripped on the primary accounts insert (tax_codes don't
    // exist yet) and patched in by the deferred-FK pass.
    $expenseAccountB = Account::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('code', $this->expenseAccount->code)
        ->firstOrFail();
    $taxCodeB = TaxCode::withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('code', 'RT-TAX')
        ->firstOrFail();

    expect($expenseAccountB->default_tax_code_id)->toBe($taxCodeB->id);

    // ---- 4. Attachment bytes round-trip identically. ----
    $attachmentsB = Attachment::query()
        ->withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->get();

    expect($attachmentsB)->not->toBeEmpty();

    foreach ($attachmentsB as $attB) {
        $aMatch = Attachment::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->companyA->id)
            ->where('original_filename', $attB->original_filename)
            ->first();

        expect($aMatch)->not->toBeNull("Source attachment not found for {$attB->original_filename}");

        $aBytes = Storage::disk($aMatch->disk)->get($aMatch->path);
        $bBytes = Storage::disk($attB->disk)->get($attB->path);

        expect($aBytes)->not->toBeNull()
            ->and($bBytes)->not->toBeNull()
            ->and(hash('sha256', (string) $bBytes))->toBe(
                hash('sha256', (string) $aBytes),
                "Attachment bytes diverge for {$attB->original_filename}"
            );
    }

    // ---- 5. Importing user (Alice) is Owner of Company B. ----
    $ownerMembership = Membership::query()
        ->where('company_id', $companyB->id)
        ->where('user_id', $this->alice->id)
        ->first();

    expect($ownerMembership)->not->toBeNull()
        ->and($ownerMembership->role)->toBe(CompanyRole::Owner);

    // ---- 6. User-id remap: Bob's posted_by_user_id round-trips by email. ----
    $jeAposted = (int) DB::table('journal_entries')
        ->where('company_id', $this->companyA->id)
        ->whereNotNull('posted_by_user_id')
        ->value('posted_by_user_id');
    $jeBposted = (int) DB::table('journal_entries')
        ->where('company_id', $companyB->id)
        ->whereNotNull('posted_by_user_id')
        ->value('posted_by_user_id');

    expect($jeAposted)->toBe($this->bob->id)
        ->and($jeBposted)->toBe($this->bob->id);

    // ---- 7. Company A is untouched. ----
    $freshA = Company::withoutGlobalScopes()->find($this->companyA->id);
    expect($freshA->name)->toBe('Acme Inc.')
        ->and($freshA->slug)->not->toBe($companyB->slug);

    // ---- 7b. Document folder + its document re-link to Company B. ----
    $folderB = DocumentFolder::query()
        ->withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('name', 'Incorporation')
        ->first();

    expect($folderB)->not->toBeNull()
        ->and($folderB->id)->not->toBe($this->folder->id);

    // The repository document's polymorphic attachable_id points at the new folder.
    $docB = Attachment::query()
        ->withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->where('original_filename', 'articles-of-incorporation.pdf')
        ->first();

    expect($docB)->not->toBeNull()
        ->and($docB->attachable_type)->toBe((new DocumentFolder)->getMorphClass())
        ->and($docB->attachable_id)->toBe($folderB->id);

    // Bob's membership and the viewer/creator references remapped to Company B.
    $bobMembershipB = Membership::query()
        ->where('company_id', $companyB->id)
        ->where('user_id', $this->bob->id)
        ->first();

    expect($bobMembershipB)->not->toBeNull()
        ->and($folderB->created_by_member_id)->toBe($bobMembershipB->id)
        ->and($folderB->viewer_member_ids)->toContain($bobMembershipB->id);

    // ---- 8. Audit-log chain verifies on Company B. ----
    // With UNIQUE(company_id, row_hash) the bundle's hashes are copied verbatim,
    // so VerifyAccountingAuditCommand re-derives sha256(previous_hash . hash_input)
    // and finds zero discrepancies. This is the portable-audit-chain property.
    Artisan::call('audit:verify', ['company' => $companyB->id]);
    $verifierOutput = Artisan::output();
    expect(Artisan::call('audit:verify', ['company' => $companyB->id]))
        ->toBe(0, "Audit verifier failed for restored company:\n{$verifierOutput}");
});
