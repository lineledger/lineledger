<?php

namespace Tests\Support\Backup;

use App\Actions\Inventory\SaveStockAdjustment;
use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CostingMethod;
use App\Enums\StatementLineMatchStatus;
use App\Enums\TaxReturnStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankImportProfile;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DocumentFolder;
use App\Models\InboxItem;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\JournalLine;
use App\Models\MemorizedReport;
use App\Models\TaxAgency;
use App\Models\TaxReturn;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\StockAdjustmentPoster;
use App\Support\Banking\LastBankAccount;
use App\Support\Storage\StorageDisks;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Adds to a seeded organization every shape of data a restore has got wrong,
 * so a round trip of it proves each one comes back right:
 *
 *  - references to rows created *later* (a voided entry's reversal, a parent
 *    account/contact/category/folder created after its child, a journal
 *    entry's source document, a stock movement's adjustment);
 *  - ids held inside JSON (FIFO layers an issue consumed, a reconciliation's
 *    marked lines, a tax return's excluded lines, remembered accounts, a saved
 *    report's filters);
 *  - audit rows about records deleted since (a reposted invoice's old lines);
 *  - files (logos, an invoice attachment, an inbox document, and an attachment
 *    whose record no longer exists);
 *  - the tables backups once left out (budgets, the bank feed);
 *  - soft-deleted rows and a hidden column (the employee portal password).
 *
 * Each call to the methods the seeder doesn't make goes through the same
 * actions and posters the app uses, where one exists.
 */
final class AdversarialBackupFixture
{
    public static function apply(Company $company): void
    {
        $owner = $company->owner() ?? User::query()->firstOrFail();

        Auth::login($owner);
        app()->instance('current_company', $company);

        try {
            $ctx = self::context($company);

            self::inventory($company, $ctx);
            self::voidsAndReposts($ctx);
            self::reparenting($company, $ctx);
            self::files($company, $owner, $ctx);
            self::jsonIds($company, $owner, $ctx);
            self::bankFeedAndBudgets($company, $owner, $ctx);
            self::softDeletesAndHiddenColumns($company, $ctx);
        } finally {
            Auth::logout();
            app()->forgetInstance('current_company');
        }
    }

    /**
     * @return array{revenue: Account, expense: Account, bank: Account, customer: Contact, vendor: Contact, tax_code_id: ?int}
     */
    private static function context(Company $company): array
    {
        return [
            'revenue' => Account::query()->where('type', AccountType::Income)->orderBy('id')->firstOrFail(),
            'expense' => Account::query()->where('type', AccountType::Expense)->orderBy('id')->firstOrFail(),
            'bank' => Account::query()->where('subtype', AccountSubtype::Bank)->orderBy('id')->firstOrFail(),
            'customer' => Contact::query()->where('is_customer', true)->orderBy('id')->firstOrFail(),
            'vendor' => Contact::query()->where('is_vendor', true)->orderBy('id')->firstOrFail(),
            'tax_code_id' => null,
        ];
    }

    /**
     * FIFO stock in two cost layers, then a sale that consumes both: the
     * issue's movement records the layers it drew down (consumed_layers →
     * stock_layers, a table restored after stock_movements).
     *
     * @param  array<string, mixed>  $ctx
     */
    private static function inventory(Company $company, array $ctx): void
    {
        $company->forceFill(['costing_method' => CostingMethod::Fifo])->save();

        $widget = Item::factory()->tracked()->create([
            'company_id' => $company->id,
            'name' => 'Backup Widget',
            'default_price_cents' => 5000,
        ]);

        foreach ([[4, 1000, 40], [6, 1500, 35]] as [$qty, $cost, $daysAgo]) {
            $adjustment = app(SaveStockAdjustment::class)->handle([
                'adjustment_date' => now()->subDays($daysAgo)->toDateString(),
                'reason' => 'opening_balance',
                'notes' => 'Layer at '.$cost,
                'lines' => [['item_id' => $widget->id, 'qty_change' => $qty, 'unit_cost_cents' => $cost]],
            ]);
            app(StockAdjustmentPoster::class)->post($adjustment);
        }

        $sale = app(SaveInvoice::class)->handle([
            'contact_id' => $ctx['customer']->id,
            'invoice_date' => now()->subDays(10)->toDateString(),
            'lines' => [[
                'item_id' => $widget->id,
                'account_id' => $ctx['revenue']->id,
                'description' => 'Widgets',
                'quantity' => 6,
                'unit_price_cents' => 5000,
            ]],
        ]);
        app(InvoicePoster::class)->post($sale);
    }

    /**
     * A voided invoice (its entry points forward at the reversal) and a
     * reposted one (its old lines are deleted; their audit rows remain).
     *
     * @param  array<string, mixed>  $ctx
     */
    private static function voidsAndReposts(array $ctx): void
    {
        $line = fn (int $qty): array => [
            'account_id' => $ctx['revenue']->id,
            'description' => 'Services',
            'quantity' => $qty,
            'unit_price_cents' => 10000,
        ];

        $toVoid = app(SaveInvoice::class)->handle([
            'contact_id' => $ctx['customer']->id,
            'invoice_date' => now()->subDays(9)->toDateString(),
            'lines' => [$line(2)],
        ]);
        app(InvoicePoster::class)->post($toVoid);
        app(InvoicePoster::class)->void($toVoid->fresh());

        $toRepost = app(SaveInvoice::class)->handle([
            'contact_id' => $ctx['customer']->id,
            'invoice_date' => now()->subDays(8)->toDateString(),
            'lines' => [$line(1)],
        ]);
        app(InvoicePoster::class)->post($toRepost);

        $toRepost = app(SaveInvoice::class)->handle([
            'contact_id' => $ctx['customer']->id,
            'invoice_date' => now()->subDays(8)->toDateString(),
            'lines' => [$line(3), $line(1)],
        ], $toRepost->fresh());
        app(InvoicePoster::class)->repost($toRepost->fresh());
    }

    /**
     * Each self-referencing tree with a parent created after its child, so
     * the parent's id is higher and it restores after the child.
     *
     * @param  array<string, mixed>  $ctx
     */
    private static function reparenting(Company $company, array $ctx): void
    {
        $newerParent = $ctx['expense']->replicate(['parent_id', 'balance_cents']);
        $newerParent->forceFill(['name' => 'Backup Parent Expense', 'code' => '6999', 'is_system' => false])->save();
        $ctx['expense']->forceFill(['parent_id' => $newerParent->id])->save();

        $newerContact = Contact::factory()->customer()->create(['company_id' => $company->id, 'display_name' => 'Backup Parent Customer']);
        $ctx['customer']->forceFill(['parent_id' => $newerContact->id])->save();

        $child = ItemCategory::query()->forceCreate(['company_id' => $company->id, 'name' => 'Backup Child Category']);
        $parent = ItemCategory::query()->forceCreate(['company_id' => $company->id, 'name' => 'Backup Parent Category']);
        $child->forceFill(['parent_id' => $parent->id])->save();

        $childFolder = DocumentFolder::factory()->create(['company_id' => $company->id, 'name' => 'Backup Child Folder']);
        $parentFolder = DocumentFolder::factory()->create(['company_id' => $company->id, 'name' => 'Backup Parent Folder']);
        $childFolder->forceFill(['parent_folder_id' => $parentFolder->id])->save();
    }

    /**
     * Logos, an attachment on an invoice, an inbox document (attached to the
     * inbox item, which restores after attachments) promoted to a bill, and an
     * attachment whose record was deleted before the backup.
     *
     * @param  array<string, mixed>  $ctx
     */
    private static function files(Company $company, User $owner, array $ctx): void
    {
        Storage::disk(StorageDisks::logos())->put('company-logos/backup-logo.png', 'logo-bytes');
        Storage::disk(StorageDisks::logos())->put('company-logos/backup-document-logo.png', 'document-logo-bytes');
        $company->forceFill([
            'logo_path' => 'company-logos/backup-logo.png',
            'document_logo_path' => 'company-logos/backup-document-logo.png',
        ])->save();

        $disk = StorageDisks::attachments();
        $invoice = Invoice::query()->orderBy('id')->firstOrFail();

        $attach = function (string $type, int $id, string $name, string $bytes) use ($company, $owner, $disk): Attachment {
            $path = "attachments/{$company->id}/".uniqid().'_'.$name;
            Storage::disk($disk)->put($path, $bytes);

            return Attachment::query()->create([
                'company_id' => $company->id,
                'attachable_type' => $type,
                'attachable_id' => $id,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $name,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($bytes),
                'uploaded_by_id' => $owner->id,
            ]);
        };

        $attach(Invoice::class, $invoice->id, 'signed-invoice.pdf', 'signed invoice bytes');

        $bill = Bill::query()->orderBy('id')->firstOrFail();
        $inbox = InboxItem::query()->create([
            'company_id' => $company->id,
            'source' => 'upload',
            'status' => 'promoted',
            'original_filename' => 'receipt.pdf',
            'mime' => 'application/pdf',
            'suggested_contact_id' => $ctx['vendor']->id,
            'suggested_document_type' => 'bill',
            'promoted_document_type' => 'bill',
            'promoted_document_id' => $bill->id,
            'created_by_user_id' => $owner->id,
        ]);
        $document = $attach(InboxItem::class, $inbox->id, 'receipt.pdf', 'inbox receipt bytes');
        $inbox->forceFill(['attachment_id' => $document->id])->save();

        // The invoice this was attached to is gone; nothing to attach it to.
        $attach(Invoice::class, 9_999_999, 'orphan.pdf', 'orphan bytes');
    }

    /**
     * Ids stored inside JSON columns.
     *
     * @param  array<string, mixed>  $ctx
     */
    private static function jsonIds(Company $company, User $owner, array $ctx): void
    {
        $bankLineIds = JournalLine::query()
            ->whereHas('journalEntry', fn ($q) => $q->where('company_id', $company->id))
            ->where('account_id', $ctx['bank']->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        BankReconciliation::query()->where('account_id', $ctx['bank']->id)->orderBy('id')->firstOrFail()
            ->forceFill(['marked_line_ids' => array_slice($bankLineIds, 0, 3)])->save();

        $revenueLineIds = JournalLine::query()
            ->whereHas('journalEntry', fn ($q) => $q->where('company_id', $company->id))
            ->where('account_id', $ctx['revenue']->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        TaxReturn::query()->forceCreate([
            'company_id' => $company->id,
            'tax_agency_id' => TaxAgency::query()->orderBy('id')->firstOrFail()->id,
            'tax_return_no' => 'TR-BACKUP-1',
            'period_start' => now()->startOfQuarter()->subQuarter()->toDateString(),
            'period_end' => now()->startOfQuarter()->subDay()->toDateString(),
            'status' => TaxReturnStatus::Draft,
            'collected_cents' => 0,
            'paid_cents' => 0,
            'net_cents' => 0,
            'excluded_journal_line_ids' => array_slice($revenueLineIds, 0, 2),
        ]);

        $company->fresh()->setReconciliationDefaults($ctx['bank']->id, [
            'service_charge_account_id' => $ctx['expense']->id,
            'interest_account_id' => $ctx['revenue']->id,
        ]);

        LastBankAccount::remember($company, $ctx['bank']->id);

        MemorizedReport::factory()->create([
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'settings' => ['accountId' => $ctx['expense']->id, 'contactId' => $ctx['customer']->id, 'perPage' => 50],
        ]);
    }

    /**
     * The tables backups used to leave out.
     *
     * @param  array<string, mixed>  $ctx
     */
    private static function bankFeedAndBudgets(Company $company, User $owner, array $ctx): void
    {
        $budget = Budget::query()->forceCreate([
            'company_id' => $company->id,
            'name' => 'Backup Budget',
            'fiscal_year' => (int) now()->year,
        ]);
        BudgetLine::query()->forceCreate([
            'company_id' => $company->id,
            'budget_id' => $budget->id,
            'account_id' => $ctx['expense']->id,
            'line_order' => 0,
            'month_1_cents' => 12_000,
            'month_7_cents' => 34_500,
        ]);

        $profile = BankImportProfile::factory()->create([
            'company_id' => $company->id,
            'account_id' => $ctx['bank']->id,
            'created_by_user_id' => $owner->id,
        ]);

        $import = BankStatementImport::factory()->create([
            'company_id' => $company->id,
            'account_id' => $ctx['bank']->id,
            'bank_import_profile_id' => $profile->id,
            'created_by_user_id' => $owner->id,
        ]);

        $matchedLine = JournalLine::query()
            ->whereHas('journalEntry', fn ($q) => $q->where('company_id', $company->id))
            ->where('account_id', $ctx['bank']->id)
            ->orderBy('id')
            ->firstOrFail();

        BankStatementLine::factory()->create([
            'company_id' => $company->id,
            'bank_statement_import_id' => $import->id,
            'account_id' => $ctx['bank']->id,
            'match_status' => StatementLineMatchStatus::Matched,
            'matched_journal_line_id' => $matchedLine->id,
        ]);

        $bill = Bill::query()->orderBy('id')->firstOrFail();

        BankStatementLine::factory()->create([
            'company_id' => $company->id,
            'bank_statement_import_id' => $import->id,
            'account_id' => $ctx['bank']->id,
            'match_status' => StatementLineMatchStatus::Unmatched,
            'suggested_account_id' => $ctx['expense']->id,
            'suggested_contact_id' => $ctx['vendor']->id,
            'suggested_bill_allocations' => [['bill_id' => $bill->id, 'amount_cents' => 100]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private static function softDeletesAndHiddenColumns(Company $company, array $ctx): void
    {
        InboxItem::query()->create([
            'company_id' => $company->id,
            'source' => 'upload',
            'status' => 'pending',
            'original_filename' => 'discarded.pdf',
            'mime' => 'application/pdf',
        ])->delete();

        $employee = Contact::factory()->create(['company_id' => $company->id, 'display_name' => 'Backup Employee', 'is_employee' => true]);
        DB::table('contacts')->where('id', $employee->id)->update(['portal_password' => Hash::make('portal-secret')]);
    }
}
