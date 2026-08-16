<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\ChequeStatus;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\DataMigrationRun;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\GeneralLedgerReplayImporter;
use App\Services\Migration\QuickBooksMigrationService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $find = fn (AccountSubtype $s) => Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', $s->value)->orderBy('code')->first();
    $this->ar = $find(AccountSubtype::AccountsReceivable);
    $this->ap = $find(AccountSubtype::AccountsPayable);
    $this->bank = $find(AccountSubtype::Bank);
    $this->income = $find(AccountSubtype::Income);
    $this->expense = $find(AccountSubtype::Expense);
    $this->undeposited = $find(AccountSubtype::UndepositedFunds);

    $this->customer = Contact::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'display_name' => 'Acme Co', 'is_customer' => true, 'is_active' => true]);
    $this->vendor = Contact::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'display_name' => 'Office Supply', 'is_vendor' => true, 'is_active' => true]);

    $this->run = DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'status' => DataMigrationStatus::InProgress,
        'mode' => DataMigrationMode::FullHistory,
        'conversion_date' => CarbonImmutable::create(2026, 7, 31),
        'current_step' => 5,
        'step_results' => [],
        'reconstruct_documents' => true,
        'started_at' => now(),
    ]);

    $this->ctx = new ImportContext(
        company: $this->company,
        run: $this->run,
        conversionDate: CarbonImmutable::create(2026, 7, 31),
        sourceFormat: 'csv',
        reconstructDocuments: true,
    );

    $this->import = fn (string $csv) => app(GeneralLedgerReplayImporter::class)->commit([reconCsvFile($csv)], $this->ctx);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function reconCsvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'recon').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('reconstructs an invoice with its journal entry and account-level line', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2024-01-10,INV-1,Acme Co,Job 12,{$this->ar->name},1000.00,\n"
        .",,,,,,{$this->income->name},,1000.00\n"
    );

    $invoice = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->total_cents)->toBe(100000)
        ->and($invoice->contact_id)->toBe($this->customer->id)
        ->and($invoice->status)->toBe(InvoiceStatus::Posted)
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and($invoice->lines)->toHaveCount(1);

    $this->ar->recomputeBalance();
    expect((int) $this->ar->balance_cents)->toBe(100000);
});

it('reconstructs a receipt even when the deposit account is mistyped (QuickBooks Undeposited Funds = Other Current Asset)', function () {
    $undeposited = Account::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'code' => '1499',
        'name' => 'Undeposited Funds',
        'type' => AccountType::Asset,
        'subtype' => AccountSubtype::CurrentAsset, // mistyped — not undeposited_funds
        'normal_balance' => NormalBalance::Debit,
        'is_active' => true,
    ]);

    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2024-01-01,INV-1,Acme Co,,{$this->ar->name},500.00,\n"
        .",,,,,,{$this->income->name},,500.00\n"
        ."2,Payment,2024-02-01,,Acme Co,,{$undeposited->name},500.00,\n"
        .",,,,,,{$this->ar->name},,500.00\n"
    );

    $receipt = CustomerReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($receipt)->not->toBeNull()
        ->and($receipt->amount_cents)->toBe(50000)
        ->and($receipt->deposit_to_account_id)->toBe($undeposited->id);

    $invoice = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($invoice->amount_paid_cents)->toBe(50000)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

it('reconstructs a receipt and applies it to the invoice oldest-first', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2024-01-10,INV-1,Acme Co,,{$this->ar->name},1000.00,\n"
        .",,,,,,{$this->income->name},,1000.00\n"
        ."2,Payment,2024-02-10,,Acme Co,,{$this->bank->name},600.00,\n"
        .",,,,,,{$this->ar->name},,600.00\n"
    );

    $receipt = CustomerReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($receipt->amount_cents)->toBe(60000)
        ->and($receipt->deposit_to_account_id)->toBe($this->bank->id);

    $invoice = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($invoice->amount_paid_cents)->toBe(60000)
        ->and($invoice->status)->toBe(InvoiceStatus::Partial)
        ->and($invoice->receiptApplications ?? $invoice->lines)->not->toBeNull();
});

it('reconstructs a bill and a bill payment applied to it', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Bill,2024-01-10,B-1,Office Supply,,{$this->expense->name},500.00,\n"
        .",,,,,,{$this->ap->name},,500.00\n"
        ."2,Bill Pmt -Check,2024-02-10,101,Office Supply,,{$this->ap->name},500.00,\n"
        .",,,,,,{$this->bank->name},,500.00\n"
    );

    $bill = Bill::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    $payment = BillPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->first();

    expect($bill->total_cents)->toBe(50000)
        ->and($payment->amount_cents)->toBe(50000)
        ->and($payment->paid_from_account_id)->toBe($this->bank->id)
        ->and($bill->fresh()->amount_paid_cents)->toBe(50000)
        ->and($bill->fresh()->status)->toBe(BillStatus::Paid);
});

it('reconstructs a cheque, a deposit and a credit memo', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Cheque,2024-03-01,201,Telus,,{$this->expense->name},125.83,\n"
        .",,,,,,{$this->bank->name},,125.83\n"
        ."2,Deposit,2024-03-05,,,,{$this->bank->name},4000.00,\n"
        .",,,,,,{$this->income->name},,4000.00\n"
        ."3,Credit Memo,2024-03-10,CM-1,Acme Co,,{$this->income->name},100.00,\n"
        .",,,,,,{$this->ar->name},,100.00\n"
    );

    $cheque = Cheque::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($cheque->amount_cents)->toBe(12583)
        ->and($cheque->payee_name)->toBe('Telus')
        ->and($cheque->lines)->toHaveCount(1);

    $deposit = Deposit::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($deposit->amount_cents)->toBe(400000)
        ->and($deposit->lines)->toHaveCount(1);

    $memo = CreditMemo::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($memo->total_cents)->toBe(10000)
        ->and($memo->contact_id)->toBe($this->customer->id);
});

it('leaves unrecognised types as plain journal entries', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,General Journal,2024-03-15,,,,{$this->bank->name},50.00,\n"
        .",,,,,,{$this->income->name},,50.00\n"
    );

    expect(Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(0)
        ->and(JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->where('source_type', 'qbd_import')->count())->toBe(1);
});

it('reconstructs an invoice using the journal name, creating the customer when unmatched', function () {
    // No contact named like this exists yet — the journal name drives it.
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2024-03-16,INV-9,\"Ziyone, Joan - 26/310\",,{$this->ar->name},100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n"
    );

    $created = Contact::withoutGlobalScopes()->where('company_id', $this->company->id)->where('display_name', 'Ziyone, Joan - 26/310')->first();
    expect($created)->not->toBeNull()
        ->and((bool) $created->is_customer)->toBeTrue();

    $invoice = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->contact_id)->toBe($created->id);
});

it('reconstructs a deposit tying each undeposited-funds line to its customer', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Deposit,2025-11-28,,,,{$this->bank->name},300.00,\n"
        .",,,,\"Ray, Wenda - 26/192\",Deposit,{$this->undeposited->name},,100.00\n"
        .",,,,\"Low, Norman - 26/159\",Deposit,{$this->undeposited->name},,200.00\n"
    );

    $deposit = Deposit::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($deposit->amount_cents)->toBe(30000)
        ->and($deposit->lines)->toHaveCount(2);

    $ray = Contact::withoutGlobalScopes()->where('company_id', $this->company->id)->where('display_name', 'Ray, Wenda - 26/192')->first();
    expect($ray)->not->toBeNull();

    $line = $deposit->lines->firstWhere('amount_cents', 10000);
    expect($line->contact_id)->toBe($ray->id);
});

it('keeps cheques general — payee name only, no contact created', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Cheque,2024-04-01,29705,\"King, Vanessa\",,{$this->expense->name},374.90,\n"
        .",,,,,,{$this->bank->name},,374.90\n"
    );

    $cheque = Cheque::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($cheque)->not->toBeNull()
        ->and($cheque->payee_name)->toBe('King, Vanessa')
        ->and($cheque->payee_contact_id)->toBeNull();

    // No contact was auto-created for the cheque payee (could be a vendor or employee).
    expect(Contact::withoutGlobalScopes()->where('company_id', $this->company->id)->where('display_name', 'King, Vanessa')->exists())->toBeFalse();
});

it('uniquifies duplicate QuickBooks document numbers', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2024-01-01,32/96,Acme Co,,{$this->ar->name},100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n"
        ."2,Invoice,2024-02-01,32/96,Acme Co,,{$this->ar->name},200.00,\n"
        .",,,,,,{$this->income->name},,200.00\n"
    );

    $invoices = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
    expect($invoices)->toHaveCount(2)
        ->and($invoices[0]->invoice_no)->toBe('32/96')
        ->and($invoices[1]->invoice_no)->toBe('32/96-2');
});

it('still posts the journal entry when document reconstruction fails', function () {
    // A pre-existing cheque occupies the number the import will try to reuse, so the
    // cheque reconstruction will hit the unique constraint and fail.
    Cheque::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '999',
        'cheque_date' => '2024-01-01',
        'payee_name' => 'Existing',
        'amount_cents' => 100,
        'status' => ChequeStatus::Posted,
    ]);

    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Cheque,2024-05-01,999,Telus,,{$this->expense->name},200.00,\n"
        .",,,,,,{$this->bank->name},,200.00\n"
    );

    // No new cheque document (reconstruction failed), but the GL entry still posted.
    expect(Cheque::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(1);
    $this->bank->recomputeBalance();
    expect((int) $this->bank->balance_cents)->toBe(-20000); // bank credited $200
});

it('rolls back reconstructed documents along with their journal entries', function () {
    ($this->import)(
        "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2024-01-10,INV-1,Acme Co,,{$this->ar->name},1000.00,\n"
        .",,,,,,{$this->income->name},,1000.00\n"
        ."2,Payment,2024-02-10,,Acme Co,,{$this->bank->name},1000.00,\n"
        .",,,,,,{$this->ar->name},,1000.00\n"
    );

    expect(Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(1);

    app(QuickBooksMigrationService::class)->rollbackFullHistory($this->run->fresh());

    expect(Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(0)
        ->and(CustomerReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(0)
        ->and(JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->whereNotNull('source_external_id')->count())->toBe(0);

    $this->ar->refresh()->recomputeBalance();
    expect((int) $this->ar->balance_cents)->toBe(0);
});
