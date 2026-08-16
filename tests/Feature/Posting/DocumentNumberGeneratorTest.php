<?php

use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\DocumentNumberGenerator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->generator = app(DocumentNumberGenerator::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** Create an invoice with the given number; later rows get higher ids. */
function dngMakeInvoice(string $number): Invoice
{
    $customer = Contact::factory()->customer()->create();

    return Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $number,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => InvoiceStatus::Draft,
    ]);
}

function dngNextInvoiceNo(): string
{
    return test()->generator->next(test()->company, Invoice::class, 'invoice_no', 'INV');
}

it('uses the default prefix when no documents exist', function () {
    expect(dngNextInvoiceNo())->toBe('INV-000001');
});

it('continues the machine default sequence', function () {
    dngMakeInvoice('INV-000001');

    expect(dngNextInvoiceNo())->toBe('INV-000002');
});

it('continues a user-adopted custom format', function (string $last, string $expected) {
    dngMakeInvoice($last);

    expect(dngNextInvoiceNo())->toBe($expected);
})->with([
    ['27/001', '27/002'],
    ['2027-099', '2027-100'],
    ['INV-2027-009', 'INV-2027-010'],
    ['1001', '1002'],
    ['27/999', '27/1000'],
]);

it('continues the most recent format when the user switches mid-stream', function () {
    dngMakeInvoice('INV-000005');
    dngMakeInvoice('27/001');

    expect(dngNextInvoiceNo())->toBe('27/002');
});

it('keeps BILL and REIM as separate sequences', function () {
    $vendor = Contact::factory()->vendor()->create();
    Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-000001',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    // A machine-format BILL number must not bleed into the REIM sequence.
    expect($this->generator->next($this->company, Bill::class, 'bill_no', 'REIM'))
        ->toBe('REIM-000001');
});

it('increments the trailing digit run while preserving format', function (string $value, ?string $expected) {
    expect(DocumentNumberGenerator::incrementFormat($value))->toBe($expected);
})->with([
    ['27/001', '27/002'],
    ['INV-2027-009', 'INV-2027-010'],
    ['1001', '1002'],
    ['99', '100'],
    ['A-100', 'A-101'],
    ['VOID', null],
    ['', null],
]);
