<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\BillPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postedBillFor(int $contactId, int $amountCents, string $billNo): Bill
{
    $bill = Bill::create([
        'contact_id' => $contactId,
        'bill_type' => BillType::Vendor,
        'bill_no' => $billNo,
        'bill_date' => now()->subDay()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $bill->lines()->create([
        'account_id' => test()->expense->id,
        'description' => 'X',
        'quantity' => '1',
        'unit_price_cents' => $amountCents,
        'line_subtotal_cents' => $amountCents,
        'line_tax_cents' => 0,
        'line_total_cents' => $amountCents,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('creates one payment per supplier when paying bills across vendors', function () {
    $vendorA = Contact::factory()->vendor()->create();
    $vendorB = Contact::factory()->vendor()->create();

    $a1 = postedBillFor($vendorA->id, 10000, 'A1');
    postedBillFor($vendorA->id, 5000, 'A2');
    $b1 = postedBillFor($vendorB->id, 8000, 'B1');

    $component = Livewire::test('pages::bill-payments.batch', ['company' => $this->company])
        ->set('paid_from_account_id', $this->bank->id);

    // Apply each open bill in full.
    $rows = array_map(function ($r) {
        $r['apply'] = number_format($r['balance'] / 100, 2, '.', '');

        return $r;
    }, $component->get('rows'));

    $component->set('rows', $rows)->call('pay')->assertHasNoErrors();

    // One BillPayment per vendor (two suppliers → two payments).
    expect(BillPayment::count())->toBe(2);

    $paymentA = BillPayment::where('contact_id', $vendorA->id)->firstOrFail();
    $paymentB = BillPayment::where('contact_id', $vendorB->id)->firstOrFail();

    expect($paymentA->amount_cents)->toBe(15000)
        ->and($paymentA->applications)->toHaveCount(2)
        ->and($paymentB->amount_cents)->toBe(8000)
        ->and($a1->fresh()->status)->toBe(BillStatus::Paid)
        ->and($b1->fresh()->status)->toBe(BillStatus::Paid)
        // Bank credited 23000 across both payments.
        ->and($this->bank->fresh()->balance_cents)->toBe(-23000);
});

it('rejects applying more than a bill balance', function () {
    $vendor = Contact::factory()->vendor()->create();
    postedBillFor($vendor->id, 5000, 'C1');

    $component = Livewire::test('pages::bill-payments.batch', ['company' => $this->company])
        ->set('paid_from_account_id', $this->bank->id);

    $rows = $component->get('rows');
    $rows[0]['apply'] = '99.99'; // more than the $50.00 balance

    $component->set('rows', $rows)->call('pay')->assertHasErrors('rows');

    expect(BillPayment::count())->toBe(0);
});
