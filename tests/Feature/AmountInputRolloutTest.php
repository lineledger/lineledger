<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InboxItem;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
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
 * Every dollar-amount field across these forms now renders <x-amount-input>
 * (the in-cell calculator) instead of a bare <flux:input>. This guards the
 * rollout: each page must still compile (a leftover literal "<flux:input" means
 * Blade's component compiler choked and the field would silently vanish) and the
 * calculator must be wired (x-data="amountCalculator" + the tape dropdown).
 */
it('renders the amount-input calculator on every converted form', function (string $route, array $dataTests) {
    $html = $this->get(route($route, ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    // Compilation succeeded — no uncompiled Flux tags leaked into the output.
    expect($html)->not->toContain('<flux:input');

    // The calculator + tape are present.
    expect($html)
        ->toContain('x-data="amountCalculator"')
        ->toContain('data-test="calc-tape"');

    // Each converted dollar field is present and bound.
    foreach ($dataTests as $dataTest) {
        expect($html)->toContain('data-test="'.$dataTest.'"');
    }
})->with([
    'journal' => ['journal.create', ['line-debit', 'line-credit']],
    'invoices' => ['invoices.create', ['line-unit-price']],
    'credit memos' => ['credit-memos.create', ['line-unit-price']],
    'purchase orders' => ['purchase-orders.create', ['line-unit-price']],
    'bills' => ['bills.create', ['line-unit-price', 'line-tax-override']],
    'vendor credits' => ['vendor-credits.create', ['line-unit-price']],
    'transfers' => ['transfers.create', ['transfer-amount-input']],
    'reconcile' => ['banking.reconcile', []],
    'recurring journal' => ['recurring-journal.create', ['line-debit', 'line-credit']],
    'sales orders' => ['sales-orders.create', ['line-unit-price']],
    'estimates' => ['estimates.create', ['line-unit-price']],
    'invoice templates' => ['invoice-templates.create', ['line-unit-price']],
    'recurring documents' => ['recurring.create', ['line-unit-price']],
    'reimbursements' => ['reimbursements.create', ['line-unit-price', 'line-tax-override']],
    'cheques' => ['cheques.create', ['line-amount', 'line-tax-override']],
    'expenses' => ['expenses.create', ['line-amount', 'line-tax-override']],
    'receipts' => ['receipts.create', ['receipt-amount-input']],
]);

/**
 * The bill tax-override field is the only conversion that forwards a non-default
 * binding (.live.debounce.500ms) plus a tab handler through the attribute bag.
 * Confirm both survived the swap so debounced syncing and tab-to-add-row still work.
 */
it('preserves the debounce binding and tab handler on the bill tax-override field', function () {
    $html = $this->get(route('bills.create', ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('wire:model.live.debounce.500ms="lines.0.tax_override"')
        ->toContain('addRowAndFocus')
        ->toContain('wire:model.live="lines.0.unit_price"');
});

/**
 * The other tax-override fields forward the same debounced binding through the
 * attribute bag; make sure none of them silently fell back to the default .live.
 */
it('preserves the debounce binding on every tax-override field', function (string $route) {
    $html = $this->get(route($route, ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('wire:model.live.debounce.500ms="lines.0.tax_override"');
})->with(['cheques.create', 'expenses.create', 'reimbursements.create']);

/**
 * The deposit "Other deposits" amount field only renders once a line exists
 * (the table is hidden until then), so it's driven through Livewire rather than
 * a plain GET.
 */
it('renders the amount-input calculator on the deposit other-line amount field', function () {
    $html = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('addOtherLine')
        ->html();

    expect($html)->not->toContain('<flux:input');

    expect($html)
        ->toContain('data-test="other-amount"')
        ->toContain('x-data="amountCalculator"')
        ->toContain('data-test="calc-tape"');
});

/**
 * The budget grid renders one empty account row on create; every month cell
 * carries the calculator with the grid's debounced binding.
 */
it('renders the amount-input calculator on the budget grid cells', function () {
    $html = Livewire::test('pages::budgets.form', ['company' => $this->company])->html();

    expect($html)->not->toContain('<flux:input');

    expect($html)
        ->toContain('wire:model.live.debounce.500ms="rows.0.m1"')
        ->toContain('wire:model.live.debounce.500ms="rows.0.m12"')
        ->toContain('x-data="amountCalculator"')
        ->toContain('data-test="calc-tape"');
});

/**
 * The banking review split modal only renders once a statement line is opened
 * for splitting.
 */
it('renders the amount-input calculator on the banking review split amount', function () {
    $bank = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->where('is_active', true)
        ->orderBy('code')
        ->firstOrFail();
    $import = BankStatementImport::factory()->create(['account_id' => $bank->id]);
    $line = BankStatementLine::factory()->create([
        'bank_statement_import_id' => $import->id,
        'account_id' => $bank->id,
        'txn_date' => '2026-06-10',
        'amount_cents' => 10000,
        'description' => 'TXN 10000',
        'match_status' => 'unmatched',
        'suggested_account_id' => null,
        'created_journal_entry_id' => null,
    ]);

    $html = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('openSplit', $line->id)
        ->html();

    expect($html)->not->toContain('<flux:input');

    expect($html)
        ->toContain('data-test="split-amount"')
        ->toContain('wire:model.live="splits.0.amount"')
        ->toContain('x-data="amountCalculator"');
});

/**
 * The bill-payment apply table only renders once a vendor with open bills is
 * selected.
 */
it('renders the amount-input calculator on the bill-payment apply column', function () {
    $vendor = Contact::create(['display_name' => 'Acme Supplies', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'B-200',
        'bill_date' => '2026-05-14',
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => 15463,
        'line_subtotal_cents' => 15463,
        'line_tax_cents' => 0,
        'line_total_cents' => 15463,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $html = Livewire::test('pages::bill-payments.form', ['company' => $this->company])
        ->set('contactRole', 'vendor')
        ->set('contact_id', $vendor->id)
        ->html();

    expect($html)->not->toContain('<flux:input');

    expect($html)
        ->toContain('data-test="apply-bill-input"')
        ->toContain('wire:model.live="applyTable.0.apply"')
        ->toContain('x-data="amountCalculator"');
});

/**
 * The receipt form's apply table only renders once a customer with open
 * invoices is selected; the amount field is always present.
 */
it('renders the amount-input calculator on the receipt amount and apply column', function () {
    $customer = Contact::create(['display_name' => 'Recent Co', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-1',
        'invoice_date' => '2026-05-20',
        'due_date' => '2026-06-20',
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $html = Livewire::test('pages::receipts.form', ['company' => $this->company])
        ->call('selectContact', $customer->id)
        ->html();

    expect($html)->not->toContain('<flux:input');

    expect($html)
        ->toContain('data-test="receipt-amount-input"')
        ->toContain('wire:model.live="amount"')
        ->toContain('data-test="apply-input"')
        ->toContain('wire:model.live="applyTable.0.apply"')
        ->toContain('x-data="amountCalculator"');
});

/**
 * The inbox review page builds its line grid from the staged item's extracted
 * data, so it needs a needs-review item with an attachment.
 */
it('renders the amount-input calculator on the inbox review tax-override field', function () {
    Storage::fake('local');

    $item = InboxItem::create([
        'source' => InboxItemSource::Upload,
        'status' => InboxItemStatus::NeedsReview,
        'original_filename' => 'receipt.jpg',
        'mime' => 'image/jpeg',
        'created_by_user_id' => $this->user->id,
    ]);
    $path = 'attachments/'.$this->company->id.'/inbox_items/'.$item->id.'/receipt.jpg';
    Storage::disk('local')->put($path, 'fake-bytes');
    $attachment = Attachment::create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'receipt.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 10,
        'uploaded_by_id' => $this->user->id,
    ]);
    $item->forceFill([
        'attachment_id' => $attachment->id,
        'suggested_document_type' => 'bill',
        'extracted' => ['vendor' => 'Some Vendor', 'amount_cents' => 6000, 'currency' => 'CAD', 'date' => '2026-06-20'],
    ])->save();

    $html = Livewire::test('pages::inbox.show', ['company' => $this->company, 'item' => $item->fresh()])->html();

    expect($html)->not->toContain('<flux:input');

    expect($html)
        ->toContain('data-test="inbox-line-tax-override"')
        ->toContain('wire:model.live.debounce.500ms="lines.0.tax_override"')
        ->toContain('x-data="amountCalculator"');
});
