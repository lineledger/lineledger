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

it('keeps QuickBooks sub-accounts under their parents when the listing has no numbers', function () {
    $result = app(ChartOfAccountsImporter::class)->commit(quickBooksSubAccountListingCsv(asFile: true), $this->ctx);

    expect($result->errors)->toBe([])
        ->and($result->summary['created'])->toBe(5);

    $account = fn (string $code) => Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();

    $topLevel = $account('Domian Name Reg/Rene');
    $passThrough = $account('Pass Through Expense');
    $hosting = $account('Hosting');
    $nested = $account('Domian Name Reg/Re-2'); // the name's code was taken by the top-level account
    $email = $account('Email Hosting');

    expect($topLevel->parent_id)->toBeNull()
        ->and($passThrough->parent_id)->toBeNull()
        ->and($hosting->parent_id)->toBe($passThrough->id)
        ->and($nested->name)->toBe('Domian Name Reg/Renew')
        ->and($nested->parent_id)->toBe($hosting->id)
        ->and($email->parent_id)->toBe($hosting->id);
});

it('previews each QuickBooks sub-account with its parent and creates nothing', function () {
    $result = app(ChartOfAccountsImporter::class)->preview(quickBooksSubAccountListingCsv(asFile: true), $this->ctx);

    expect($result->errors)->toBe([]);

    $rows = collect($result->previewRows);

    // The migration wizard builds its table header from the first row's keys.
    $rows->each(fn (array $row) => expect(array_keys($row))->toBe(['row', 'code', 'name', 'parent', 'action']));

    expect($rows->pluck('parent', 'code')->all())->toBe([
        'Domian Name Reg/Rene' => null,
        'Pass Through Expense' => null,
        'Hosting' => 'Pass Through Expense',
        'Domian Name Reg/Re-2' => 'Pass Through Expense:Hosting',
        'Email Hosting' => 'Pass Through Expense:Hosting',
    ]);

    expect(Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Hosting')->exists())->toBeFalse();
});

it('skips every account when the same numberless listing is imported again', function () {
    app(ChartOfAccountsImporter::class)->commit(quickBooksSubAccountListingCsv(asFile: true), $this->ctx);
    $again = app(ChartOfAccountsImporter::class)->commit(quickBooksSubAccountListingCsv(asFile: true), $this->ctx);

    expect($again->errors)->toBe([])
        ->and($again->summary['created'])->toBe(0)
        ->and($again->summary['skipped_existing'])->toBe(5)
        ->and(Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Domian Name Reg/Renew')->count())->toBe(2);
});

it('finds numberless accounts by path when a later listing adds one with the same name above them', function () {
    app(ChartOfAccountsImporter::class)->commit(quickBooksSubAccountListingCsv(asFile: true), $this->ctx);

    $byCode = fn (string $code) => Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    $hostingDomain = $byCode('Domian Name Reg/Re-2');

    // A newer export: a third "Domian Name Reg/Renew" now sorts above the Hosting one.
    $path = makeCsv(['Account', 'Type', 'Accnt. #', 'Description'], [
        ['Domian Name Reg/Renew', 'Expense', '', ''],
        ['Pass Through Expense', 'Expense', '', ''],
        ['Pass Through Expense:Domian Name Reg/Renew', 'Expense', '', ''],
        ['Pass Through Expense:Hosting', 'Expense', '', ''],
        ['Pass Through Expense:Hosting:Domian Name Reg/Renew', 'Expense', '', ''],
        ['Pass Through Expense:Hosting:Email Hosting', 'Expense', '', ''],
    ]);

    $result = app(ChartOfAccountsImporter::class)->commit($path, $this->ctx);

    expect($result->errors)->toBe([])
        ->and($result->summary['created'])->toBe(1)
        ->and($result->summary['skipped_existing'])->toBe(5)
        ->and(collect($result->previewRows)->firstWhere('row', 6)['code'])->toBe('Domian Name Reg/Re-2');

    $new = $byCode('Domian Name Reg/Re-3');

    expect($new->parent_id)->toBe($byCode('Pass Through Expense')->id)
        ->and($hostingDomain->fresh()->parent_id)->toBe($byCode('Hosting')->id);
});

it('does not merge a numberless QuickBooks account into a seeded account with the same name', function () {
    $seeded = Account::withoutGlobalScopes()->where('company_id', $this->company->id)
        ->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $path = makeCsv(['Account', 'Type', 'Accnt. #', 'Description'], [[$seeded->name, 'Expense', '', '']]);

    $result = app(ChartOfAccountsImporter::class)->commit($path, $this->ctx);

    expect($result->errors)->toBe([])
        ->and($result->summary['created'])->toBe(1)
        ->and(Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', $seeded->name)->count())->toBe(2);
});

it('links numbered QuickBooks sub-accounts by path, and to a parent already in the chart', function () {
    $seeded = Account::withoutGlobalScopes()->where('company_id', $this->company->id)
        ->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $path = makeCsv(['Account', 'Type', 'Accnt. #', 'Description'], [
        ['9600 · Pass Through Expense', 'Expense', '9600', ''],
        ['9600 · Pass Through Expense:9610 · Hosting', 'Expense', '9610', ''],
        ['9600 · Pass Through Expense:9610 · Hosting:9611 · Domain Names', 'Expense', '9611', ''],
        ['9700 · Domain Names', 'Expense', '9700', ''],
        ["{$seeded->code} · {$seeded->name}:9801 · Seeded Child", 'Expense', '9801', ''],
    ]);

    $result = app(ChartOfAccountsImporter::class)->commit($path, $this->ctx);

    expect($result->errors)->toBe([]);

    $parentOf = fn (string $code) => Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)->where('code', $code)->value('parent_id');
    $idOf = fn (string $code) => Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)->where('code', $code)->value('id');

    expect($parentOf('9600'))->toBeNull()
        ->and($parentOf('9610'))->toBe($idOf('9600'))
        ->and($parentOf('9611'))->toBe($idOf('9610'))
        ->and($parentOf('9700'))->toBeNull()
        ->and($parentOf('9801'))->toBe($seeded->id);
});

it('rejects a parent of a different account type', function () {
    $path = makeCsv(['code', 'name', 'subtype', 'parent_code'], [
        ['9500', 'Some Income', 'income', ''],
        ['9510', 'Some Expense', 'expense', '9500'],
    ]);

    $result = app(ChartOfAccountsImporter::class)->preview($path, $this->ctx);

    expect($result->errors)->toBe([['row' => 3, 'message' => 'The parent account must be the same account type.']]);
});

it('rejects a parent that is neither in the file nor in the chart', function () {
    $quickBooks = makeCsv(['Account', 'Type', 'Accnt. #', 'Description'], [
        ['Ghost Parent:Orphan', 'Expense', '', ''],
    ]);
    $template = makeCsv(['code', 'name', 'subtype', 'parent_code'], [
        ['9520', 'Orphan', 'expense', '7777-missing'],
    ]);

    expect(app(ChartOfAccountsImporter::class)->preview($quickBooks, $this->ctx)->errors)
        ->toBe([['row' => 2, 'message' => "Parent account 'Ghost Parent' is not in this file or the chart of accounts."]])
        ->and(app(ChartOfAccountsImporter::class)->preview($template, $this->ctx)->errors)
        ->toBe([['row' => 2, 'message' => "Parent account '7777-missing' is not in this file or the chart of accounts."]]);
});

it('rejects a parent listed below its child, or one that has an error of its own', function () {
    $below = makeCsv(['code', 'name', 'subtype', 'parent_code'], [
        ['9531', 'Child', 'expense', '9530'],
        ['9530', 'Parent', 'expense', ''],
    ]);
    $broken = makeCsv(['code', 'name', 'subtype', 'parent_code'], [
        ['9540', 'Parent', 'not_a_subtype', ''],
        ['9541', 'Child', 'expense', '9540'],
    ]);
    $self = makeCsv(['code', 'name', 'subtype', 'parent_code'], [
        ['9550', 'Loop', 'expense', '9550'],
    ]);

    $importer = app(ChartOfAccountsImporter::class);

    expect($importer->preview($below, $this->ctx)->errors)
        ->toBe([['row' => 2, 'message' => "Parent account '9530' must be listed above this row."]])
        ->and(collect($importer->preview($broken, $this->ctx)->errors)->last())
        ->toBe(['row' => 3, 'message' => "Parent account '9540' has an error of its own, so this row cannot go under it."])
        ->and($importer->preview($self, $this->ctx)->errors)
        ->toBe([['row' => 2, 'message' => 'An account cannot be its own parent.']]);
});

it('gives the transaction-history replay a type hint for each sub-account path', function () {
    $hints = app(ChartOfAccountsImporter::class)->typeHints(quickBooksSubAccountListingCsv(asFile: true));

    expect($hints['byName'])
        ->toHaveKey('pass through expense:hosting:domian name reg/renew')
        ->toHaveKey('domian name reg/renew');
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
