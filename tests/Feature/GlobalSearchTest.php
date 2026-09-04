<?php

use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\GlobalSearch;
use App\Support\Contacts\ContactLinkResolver;
use App\Support\GlobalSearchResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->user->forceFill(['current_company_id' => $this->company->id])->save();

    app()->instance('current_company', $this->company);
    URL::defaults(['company' => $this->company->slug]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function ensureContact(): Contact
{
    return Contact::firstOrCreate(
        ['display_name' => '__test_default__'],
        ['is_customer' => true, 'is_vendor' => true],
    );
}

function makeSearchInvoice(string $no, ?int $contactId = null, ?string $memo = null): Invoice
{
    return Invoice::create([
        'contact_id' => $contactId ?? ensureContact()->id,
        'invoice_no' => $no,
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
        'memo' => $memo,
    ]);
}

function makeSearchBill(string $no, BillType $type = BillType::Vendor): Bill
{
    return Bill::create([
        'contact_id' => ensureContact()->id,
        'bill_type' => $type,
        'bill_no' => $no,
        'bill_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
}

it('returns no results for queries shorter than 2 characters', function () {
    makeSearchInvoice('INV-100');

    expect(app(GlobalSearch::class)->search(''))->toBe([])
        ->and(app(GlobalSearch::class)->search('I'))->toBe([])
        ->and(app(GlobalSearch::class)->search(' '))->toBe([]);
});

it('finds invoices by number, memo, and customer name', function () {
    $acme = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $other = Contact::create(['display_name' => 'Other Inc', 'is_customer' => true]);

    makeSearchInvoice('INV-1042', $acme->id, 'Annual retainer');
    makeSearchInvoice('INV-2000', $other->id, 'Unrelated');

    $byNumber = app(GlobalSearch::class)->search('1042');
    $byMemo = app(GlobalSearch::class)->search('retainer');
    $byContact = app(GlobalSearch::class)->search('Acme');

    expect($byNumber['invoices'])->toHaveCount(1)
        ->and($byNumber['invoices']->first()->label)->toBe('INV-1042')
        ->and($byMemo['invoices']->first()->label)->toBe('INV-1042')
        ->and($byContact['invoices']->first()->label)->toBe('INV-1042');
});

it('scopes results to the current company', function () {
    makeSearchInvoice('INV-INSIDE');

    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    makeSearchInvoice('INV-OTHER');

    app()->instance('current_company', $this->company);

    $results = app(GlobalSearch::class)->search('INV-');
    $labels = $results['invoices']->pluck('label')->all();

    expect($labels)->toContain('INV-INSIDE')
        ->and($labels)->not->toContain('INV-OTHER');
});

it('excludes soft-deleted records', function () {
    $invoice = makeSearchInvoice('INV-DELETE-ME');
    $invoice->delete();

    expect(app(GlobalSearch::class)->search('DELETE')['invoices'])->toBeEmpty();
});

it('routes bills to bills.show and reimbursements to reimbursements.show', function () {
    $vendorBill = makeSearchBill('BILL-V1', BillType::Vendor);
    $reimbursement = makeSearchBill('BILL-R1', BillType::Reimbursement);

    $results = app(GlobalSearch::class)->search('BILL-');
    $byNo = $results['bills']->keyBy('label');

    expect($byNo['BILL-V1']->url)->toBe(route('bills.show', ['bill' => $vendorBill->id]))
        ->and($byNo['BILL-R1']->url)->toBe(route('reimbursements.show', ['bill' => $reimbursement->id]));
});

it('routes contacts to ar / ap statements based on role', function () {
    $customer = Contact::create(['display_name' => 'Search Customer', 'is_customer' => true]);
    $vendor = Contact::create(['display_name' => 'Search Vendor', 'is_vendor' => true]);
    $employee = Contact::create(['display_name' => 'Search Employee', 'is_employee' => true]);

    $results = app(GlobalSearch::class)->search('Search');
    $byName = $results['contacts']->keyBy('label');

    expect($byName['Search Customer']->url)
        ->toBe(route('reports.contact-statement', ['contact' => $customer->id, 'kind' => 'ar']))
        ->and($byName['Search Customer']->meta)->toBe('customer')
        ->and($byName['Search Vendor']->url)
        ->toBe(route('reports.contact-statement', ['contact' => $vendor->id, 'kind' => 'ap']))
        ->and($byName['Search Vendor']->meta)->toBe('vendor')
        // Employees open their own editor rather than the bare Employees list.
        ->and($byName['Search Employee']->url)
        ->toBe(route('employees.index', ['edit' => $employee->id]))
        ->and($byName['Search Employee']->meta)->toBe('employee');
});

it('routes other names to the all-time transactions report', function () {
    $other = Contact::factory()->otherName()->create(['display_name' => 'Search Raffle Winner']);

    $result = app(GlobalSearch::class)->search('Search Raffle')['contacts']->first();

    expect($result->url)->toBe(app(ContactLinkResolver::class)->transactionsUrl($other, $this->company))
        ->and($result->url)->toContain('range=all')
        ->and($result->meta)->toBe('other name');
});

it('matches across all supported groups', function () {
    $contact = Contact::create(['display_name' => 'Findme Co', 'is_customer' => true]);
    makeSearchInvoice('FINDME-INV', $contact->id);

    makeSearchBill('FINDME-BILL');

    BillPayment::create([
        'contact_id' => $contact->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'FINDME-PAY',
        'payment_date' => CarbonImmutable::create(2026, 5, 1),
        'paid_from_account_id' => Account::query()->first()->id,
        'amount_cents' => 100,
    ]);

    CustomerReceipt::create([
        'contact_id' => $contact->id,
        'receipt_no' => 'FINDME-REC',
        'receipt_date' => CarbonImmutable::create(2026, 5, 1),
        'deposit_to_account_id' => Account::query()->first()->id,
        'amount_cents' => 100,
    ]);

    Cheque::create([
        'bank_account_id' => Account::query()->first()->id,
        'cheque_no' => 'FINDME-CHQ',
        'cheque_date' => CarbonImmutable::create(2026, 5, 1),
        'payee_name' => 'Findme Payee',
        'amount_cents' => 100,
    ]);

    Deposit::create([
        'bank_account_id' => Account::query()->first()->id,
        'deposit_no' => 'FINDME-DEP',
        'deposit_date' => CarbonImmutable::create(2026, 5, 1),
        'amount_cents' => 100,
    ]);

    JournalEntry::create([
        'entry_no' => 'FINDME-JE',
        'entry_date' => CarbonImmutable::create(2026, 5, 1),
    ]);

    Item::create(['name' => 'Findme Widget', 'sku' => 'FM-001']);

    $results = app(GlobalSearch::class)->search('FINDME');

    expect($results['invoices'])->toHaveCount(1)
        ->and($results['bills'])->toHaveCount(1)
        ->and($results['bill_payments'])->toHaveCount(1)
        ->and($results['receipts'])->toHaveCount(1)
        ->and($results['cheques'])->toHaveCount(1)
        ->and($results['deposits'])->toHaveCount(1)
        ->and($results['journal_entries'])->toHaveCount(1)
        ->and($results['contacts'])->toHaveCount(1)
        ->and($results['items'])->toHaveCount(1);

    expect($results['invoices']->first())->toBeInstanceOf(GlobalSearchResult::class);
});

it('caps each group at the per-group limit', function () {
    foreach (range(1, 8) as $n) {
        makeSearchInvoice('CAP-INV-'.$n);
    }

    $results = app(GlobalSearch::class)->search('CAP-INV');

    expect($results['invoices'])->toHaveCount(5);
});

it('escapes LIKE wildcards so they are treated as literal characters', function () {
    makeSearchInvoice('INV-100');
    makeSearchInvoice('INV-PERCENT-LITERAL%');

    $results = app(GlobalSearch::class)->search('LITERAL%');
    $labels = $results['invoices']->pluck('label')->all();

    expect($labels)->toContain('INV-PERCENT-LITERAL%')
        ->and($labels)->not->toContain('INV-100');
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'Relies on MySQL default LIKE escape character (\\); production runs MySQL.');

it('renders the Livewire component with a trigger when a company is bound', function () {
    $this->actingAs($this->user);

    Livewire::test('global-search')
        ->assertSeeHtml('data-test="global-search-trigger"');
});

it('Livewire component returns results scoped to the current company', function () {
    $this->actingAs($this->user);
    $contact = Contact::create(['display_name' => 'LiveSearch Customer', 'is_customer' => true]);
    makeSearchInvoice('LIVE-INV-1', $contact->id);

    Livewire::test('global-search')
        ->set('query', 'LiveSearch')
        ->assertSeeHtml('LIVE-INV-1')
        ->assertSeeHtml('LiveSearch Customer');
});

it('Livewire component shows the short-query hint for 0 or 1 character', function () {
    $this->actingAs($this->user);

    Livewire::test('global-search')
        ->set('query', 'a')
        ->assertSee(__('Type at least 2 characters to search.'));
});
