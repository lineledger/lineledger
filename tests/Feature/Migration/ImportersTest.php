<?php

use App\Enums\AccountSubtype;
use App\Enums\DataMigrationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\StockAdjustmentReason;
use App\Models\Account;
use App\Models\Asset;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DataMigrationRun;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\StockAdjustment;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\ChartOfAccountsImporter;
use App\Services\Migration\Importers\CustomersImporter;
use App\Services\Migration\Importers\FixedAssetsImporter;
use App\Services\Migration\Importers\InventoryOpeningBalanceImporter;
use App\Services\Migration\Importers\ItemsImporter;
use App\Services\Migration\Importers\OpenBillsImporter;
use App\Services\Migration\Importers\OpenInvoicesImporter;
use App\Services\Migration\Importers\TrialBalanceImporter;
use App\Services\Migration\Importers\VendorsImporter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->run = DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'status' => DataMigrationStatus::InProgress,
        'conversion_date' => CarbonImmutable::create(2026, 7, 31),
        'current_step' => 1,
        'step_results' => [],
        'open_invoices_use_original_date' => true,
        'open_bills_use_original_date' => true,
        'started_at' => now(),
    ]);

    $this->ctx = new ImportContext(
        company: $this->company,
        run: $this->run,
        conversionDate: CarbonImmutable::create(2026, 7, 31),
        useOriginalDates: true,
    );
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Write rows to a temp CSV and return the path.
 *
 * @param  list<string>  $headers
 * @param  list<array<int, string>>  $rows
 */
function makeCsv(array $headers, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'qbcsv').'.csv';
    $f = fopen($path, 'w');
    fputcsv($f, $headers);
    foreach ($rows as $row) {
        fputcsv($f, $row);
    }
    fclose($f);

    return $path;
}

it('imports customers from CSV', function () {
    $path = makeCsv(
        ['display_name', 'email'],
        [
            ['Acme Co', 'a@acme.test'],
            ['Beta LLC', 'b@beta.test'],
        ],
    );

    $result = app(CustomersImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    expect($result->summary['created'])->toBe(2);
    expect(Contact::where('company_id', $this->company->id)->where('is_customer', true)->count())->toBe(2);
});

it('imports a QuickBooks Customer List export (leading blank column, Invoice to address)', function () {
    $csv = ",Active Status,Customer,Company,First Name,Last Name,Main Phone,Main Email,Invoice to 1,Invoice to 2,Invoice to 3,Tax Country,Business Number\n"
        .",Active,1999,,Jane,Doe,555-1212,jane@x.com,Jane Doe,123 Main St,Toronto ON,Canada,RT1\n"
        .",Inactive,2000,Acme Co,,,,,,,,Canada,\n";

    $path = tempnam(sys_get_temp_dir(), 'qbcust').'.csv';
    file_put_contents($path, $csv);

    $result = app(CustomersImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['created'])->toBe(2);

    $c1 = Contact::withoutGlobalScopes()->where('company_id', $this->company->id)->where('display_name', '1999')->first();
    expect($c1->is_customer)->toBeTrue()
        ->and($c1->first_name)->toBe('Jane')
        ->and($c1->email)->toBe('jane@x.com')
        ->and($c1->billing_line1)->toBe('Jane Doe')
        ->and($c1->billing_line2)->toBe('123 Main St, Toronto ON')
        ->and($c1->billing_country)->toBe('CA')
        ->and($c1->tax_number)->toBe('RT1')
        ->and((bool) $c1->is_active)->toBeTrue();

    $c2 = Contact::withoutGlobalScopes()->where('company_id', $this->company->id)->where('display_name', '2000')->first();
    expect($c2->company_name)->toBe('Acme Co')
        ->and((bool) $c2->is_active)->toBeFalse(); // QuickBooks "Inactive"
});

it('imports a QuickBooks Vendor List export (Vendor column, Bill from address)', function () {
    $csv = ",Active Status,Vendor,Company,Main Phone,Bill from 1,Bill from 2,Bill from 3\n"
        .",Active,1604449 Alberta Ltd.,,403-555,1604449 Alberta Ltd.,202-916 19 AVE SW,CALGARY AB T2T 0H7\n";

    $path = tempnam(sys_get_temp_dir(), 'qbvend').'.csv';
    file_put_contents($path, $csv);

    $result = app(VendorsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();

    $v = Contact::withoutGlobalScopes()->where('company_id', $this->company->id)->where('display_name', '1604449 Alberta Ltd.')->first();
    expect($v->is_vendor)->toBeTrue()
        ->and($v->phone)->toBe('403-555')
        ->and($v->billing_line1)->toBe('1604449 Alberta Ltd.')
        ->and($v->billing_line2)->toBe('202-916 19 AVE SW, CALGARY AB T2T 0H7');
});

it('merges vendor role onto an existing customer with the same display_name', function () {
    Contact::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'display_name' => 'Acme Co',
        'is_customer' => true,
    ]);

    $path = makeCsv(['display_name'], [['Acme Co']]);
    $result = app(VendorsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    expect($result->summary['merged'])->toBe(1);

    $contact = Contact::withoutGlobalScopes()->where('display_name', 'Acme Co')->first();
    expect((bool) $contact->is_customer)->toBeTrue()
        ->and((bool) $contact->is_vendor)->toBeTrue();
});

it('imports new accounts on top of the seeded chart', function () {
    $path = makeCsv(
        ['code', 'name', 'subtype'],
        [
            ['6110', 'Vehicle Expenses', 'expense'],
            ['4200', 'Consulting Revenue', 'income'],
        ],
    );

    $result = app(ChartOfAccountsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    expect($result->summary['created'])->toBe(2);
    expect(Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '6110')->exists())->toBeTrue();
});

it('imports a QuickBooks "Account Listing" export (padding columns, BOM, NUMBER · NAME labels)', function () {
    $bom = "\xEF\xBB\xBF";
    $content = $bom.",,Account,,Type,,Balance Total,,Description,,Accnt. #,,Tax Line\n"
        .",,8801 · Cash - Bank of Montreal,,Bank,,401.12,,,,8801,,<Unassigned>\n"
        .",,8802 · Trade Payable,,Accounts Payable,,0.00,,,,8802,,<Unassigned>\n"
        .",,8803 · Undeposited Funds,,Other Current Asset,,0.00,,,,8803,,<Unassigned>\n"
        .",,Reconciliation Discrepancies,,Expense,,,,Bank rec diffs,,,,<Unassigned>\n";

    $path = tempnam(sys_get_temp_dir(), 'qbcoa').'.csv';
    file_put_contents($path, $content);

    $result = app(ChartOfAccountsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();

    $bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '8801')->first();
    $ap = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '8802')->first();
    $rec = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Reconciliation Discrepancies')->first();

    expect($bank->name)->toBe('Cash - Bank of Montreal')
        ->and($bank->subtype)->toBe(AccountSubtype::Bank)
        ->and($ap->subtype)->toBe(AccountSubtype::AccountsPayable)
        ->and($rec)->not->toBeNull()
        ->and($rec->code)->toBe('Reconciliation Discr') // numberless account → name truncated to the 20-char code column
        ->and($rec->subtype)->toBe(AccountSubtype::Expense);

    // QuickBooks types Undeposited Funds as "Other Current Asset" — recognised by name.
    $undep = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '8803')->first();
    expect($undep->subtype)->toBe(AccountSubtype::UndepositedFunds);
});

it('maps QuickBooks asset type labels to the right subtypes', function () {
    $content = "Account,Type,Accnt. #,Description\n"
        ."9101 · Operating Account,Bank,9101,\n"
        ."9102 · Prepaid Insurance,Other Current Asset,9102,\n"
        ."9103 · Goodwill,Other Asset,9103,\n";

    $path = tempnam(sys_get_temp_dir(), 'qbcoa').'.csv';
    file_put_contents($path, $content);

    $result = app(ChartOfAccountsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();

    $byCode = fn (string $code) => Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)->where('code', $code)->value('subtype');

    expect($byCode('9101'))->toBe(AccountSubtype::Bank)
        ->and($byCode('9102'))->toBe(AccountSubtype::CurrentAsset)
        ->and($byCode('9103'))->toBe(AccountSubtype::OtherAsset);
});

it('rejects accounts with unknown subtypes', function () {
    $path = makeCsv(['code', 'name', 'subtype'], [['9999', 'Bogus', 'wat']]);
    $result = app(ChartOfAccountsImporter::class)->preview($path, $this->ctx);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Unknown subtype');
});

it('imports items including inventory linkage', function () {
    $invAsset = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Inventory->value)->first();
    $cogs = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();
    $income = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $path = makeCsv(
        ['sku', 'name', 'is_inventory', 'income_account_code', 'inventory_asset_account_code', 'cogs_account_code', 'default_price'],
        [
            ['WIDGET-001', 'Widget', 'yes', $income->code, $invAsset->code, $cogs->code, '49.99'],
            ['SVC-1', 'Consulting', 'no', $income->code, '', '', '150.00'],
        ],
    );

    $result = app(ItemsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    expect(Item::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(2);
    expect(Item::withoutGlobalScopes()->where('sku', 'WIDGET-001')->first()->track_inventory)->toBeTrue();
});

it('imports open AR invoices and posts them to AR / OBE only', function () {
    $contact = Contact::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'display_name' => 'Acme Co',
        'is_customer' => true,
    ]);

    $path = makeCsv(
        ['customer_display_name', 'invoice_no', 'invoice_date', 'due_date', 'balance_remaining', 'memo'],
        [
            ['Acme Co', 'INV-100', '2026-06-15', '2026-07-15', '1250.00', 'Carryover'],
            ['Acme Co', 'INV-101', '2026-07-01', '2026-08-01', '500.00', ''],
        ],
    );

    $result = app(OpenInvoicesImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    expect($result->summary['created'])->toBe(2);
    expect($result->summary['total_ar_cents'])->toBe(175000);

    $invoices = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->get();
    expect($invoices)->toHaveCount(2);
    foreach ($invoices as $invoice) {
        expect($invoice->is_opening_balance)->toBeTrue()
            ->and($invoice->status)->toBe(InvoiceStatus::Posted);
    }

    $contact->refresh();
    expect((int) $contact->ar_balance_cents)->toBe(175000);

    $ar = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $obe = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Equity->value)->where('name', 'Opening Balance Equity')->first();
    $ar->recomputeBalance();
    $obe->recomputeBalance();

    expect((int) $ar->balance_cents)->toBe(175000);
    expect((int) $obe->balance_cents)->toBe(175000);
});

it('imports open AP bills and posts to OBE / AP only', function () {
    $contact = Contact::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'display_name' => 'Office Supply Co',
        'is_vendor' => true,
    ]);

    $path = makeCsv(
        ['vendor_display_name', 'bill_no', 'vendor_reference', 'bill_date', 'due_date', 'balance_remaining', 'memo'],
        [
            ['Office Supply Co', 'BILL-1', '', '2026-06-15', '2026-07-15', '425.00', ''],
        ],
    );

    $result = app(OpenBillsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    $bill = Bill::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($bill->is_opening_balance)->toBeTrue();
    expect((int) $bill->total_cents)->toBe(42500);

    $contact->refresh();
    expect((int) $contact->ap_balance_cents)->toBe(42500);
});

it('imports inventory opening balance via a stock adjustment with OpeningBalance reason', function () {
    $invAsset = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Inventory->value)->first();
    $cogs = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();
    $income = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $item = Item::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'name' => 'Widget',
        'sku' => 'WIDGET-001',
        'track_inventory' => true,
        'income_account_id' => $income->id,
        'inventory_asset_account_id' => $invAsset->id,
        'cogs_account_id' => $cogs->id,
        'default_price_cents' => 4999,
        'qty_on_hand_cached' => 0,
        'unit_cost_cents_cached' => 0,
        'is_active' => true,
    ]);

    $path = makeCsv(
        ['sku', 'qty_on_hand', 'unit_cost'],
        [
            ['WIDGET-001', '50', '12.50'],
        ],
    );

    $result = app(InventoryOpeningBalanceImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    $adj = StockAdjustment::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($adj)->not->toBeNull();
    expect($adj->reason)->toBe(StockAdjustmentReason::OpeningBalance);
    expect($adj->journal_entry_id)->not->toBeNull();

    $item->refresh();
    expect((float) $item->qty_on_hand_cached)->toBe(50.0);
});

it('imports fixed assets with cost & accumulated depreciation posting to OBE', function () {
    $assetAcct = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '1500')->first();
    $accumAcct = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '1510')->first();

    $path = makeCsv(
        ['asset_no', 'name', 'asset_account_code', 'accum_depreciation_account_code', 'acquired_date', 'cost', 'accumulated_depreciation_to_date', 'useful_life_months', 'salvage_value', 'category_name', 'depreciation_expense_account_code', 'in_service_date', 'serial_number', 'location', 'description'],
        [
            ['FA-001', 'Truck', $assetAcct->code, $accumAcct->code, '2024-01-15', '45000.00', '18000.00', '60', '5000.00', 'Vehicles', '', '', '', '', ''],
        ],
    );

    $result = app(FixedAssetsImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    expect(Asset::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(1);

    $assetAcct->recomputeBalance();
    $accumAcct->recomputeBalance();

    expect((int) $assetAcct->balance_cents)->toBe(4500000);
    expect((int) $accumAcct->balance_cents)->toBe(-1800000); // contra-asset under fixed_asset subtype, debit-normal; credited = negative.
});

it('imports a balanced trial balance, plugging any imbalance to OBE', function () {
    $bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $cc = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::CreditCard->value)->first();
    $retained = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::RetainedEarnings->value)->first();

    $path = makeCsv(
        ['account_code', 'debit', 'credit'],
        [
            [$bank->code, '12500.00', ''],
            [$cc->code, '', '3400.00'],
            [$retained->code, '', '9100.00'],
        ],
    );

    $result = app(TrialBalanceImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();
    $bank->recomputeBalance();
    expect((int) $bank->balance_cents)->toBe(1250000);
});

it('dates the opening trial-balance entry on the conversion date, not today', function () {
    $bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $path = makeCsv(
        ['account_code', 'debit', 'credit'],
        [[$bank->code, '12500.00', '']],
    );

    $result = app(TrialBalanceImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue();

    $entry = JournalEntry::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->latest('id')
        ->first();

    expect($entry->entry_date->toDateString())->toBe('2026-07-31');
});

it('skips trial-balance rows with zero on both sides without erroring', function () {
    $bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $cc = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::CreditCard->value)->first();

    $path = makeCsv(
        ['account_code', 'debit', 'credit'],
        [
            [$bank->code, '12500.00', ''],
            [$cc->code, '0.00', '0.00'], // zero-balance account — skipped, not an error
        ],
    );

    $result = app(TrialBalanceImporter::class)->commit($path, $this->ctx);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->summary['accepted'])->toBe(1);

    $bank->recomputeBalance();
    expect((int) $bank->balance_cents)->toBe(1250000);
});

it('rejects trial balance rows targeting AR / AP / Inventory accounts', function () {
    $ar = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    $path = makeCsv(
        ['account_code', 'debit', 'credit'],
        [
            [$ar->code, '5000.00', ''],
        ],
    );

    $result = app(TrialBalanceImporter::class)->preview($path, $this->ctx);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('sub-ledger');
});

it('refuses to import open invoices when the customer is missing', function () {
    $path = makeCsv(
        ['customer_display_name', 'invoice_no', 'balance_remaining'],
        [['Ghost LLC', 'INV-X', '100.00']],
    );

    $result = app(OpenInvoicesImporter::class)->preview($path, $this->ctx);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain("Customer 'Ghost LLC' not found");
});
