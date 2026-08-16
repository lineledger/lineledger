<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\BillPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->employee = Contact::create(['display_name' => 'Dana Employee', 'is_employee' => true]);
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makePostedReimbursement(object $test): Bill
{
    $bill = Bill::create([
        'contact_id' => $test->employee->id,
        'bill_type' => BillType::Reimbursement,
        'bill_no' => 'REIM-EDIT-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    $bill->lines()->create([
        'account_id' => $test->expense->id,
        'description' => 'Mileage',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('opens the edit form for a posted reimbursement', function () {
    $bill = makePostedReimbursement($this);

    Livewire::test('pages::reimbursements.form', ['company' => $this->company, 'bill' => $bill])
        ->assertOk()
        ->assertSet('bill_no', 'REIM-EDIT-1');
});

it('reposts a posted reimbursement in place when edited', function () {
    $bill = makePostedReimbursement($this);
    $originalEntryId = $bill->journal_entry_id;

    Livewire::test('pages::reimbursements.form', ['company' => $this->company, 'bill' => $bill])
        ->set('lines.0.unit_price', '250.00')
        ->call('post')
        ->assertHasNoErrors()
        ->assertRedirect(route('reimbursements.show', ['company' => $this->company->slug, 'bill' => $bill->id]));

    $bill->refresh();

    expect($bill->total_cents)->toBe(25000)
        ->and($bill->status)->toBe(BillStatus::Posted)
        // Reposted in place — same journal entry, not voided/recreated.
        ->and($bill->journal_entry_id)->toBe($originalEntryId);

    $entry = $bill->journalEntry()->with('lines')->first();
    expect($entry->totalDebitsCents())->toBe(25000)
        ->and($entry->totalCreditsCents())->toBe(25000);
});

it('deletes a draft reimbursement from the show page', function () {
    $bill = Bill::create([
        'contact_id' => $this->employee->id,
        'bill_type' => BillType::Reimbursement,
        'bill_no' => 'REIM-DRAFT-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'status' => BillStatus::Draft,
    ]);
    $bill->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'Mileage',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    Livewire::test('pages::reimbursements.show', ['company' => $this->company, 'bill' => $bill])
        ->call('deleteDraft')
        ->assertRedirect(route('reimbursements.index', ['company' => $this->company->slug]));

    expect(Bill::query()->whereKey($bill->id)->exists())->toBeFalse()
        ->and($bill->lines()->count())->toBe(0);
});

it('refuses to delete a posted reimbursement and keeps it intact', function () {
    $bill = makePostedReimbursement($this);

    Livewire::test('pages::reimbursements.show', ['company' => $this->company, 'bill' => $bill])
        ->call('deleteDraft')
        ->assertNoRedirect();

    expect(Bill::query()->whereKey($bill->id)->exists())->toBeTrue();
});

it('saves two taxes on a reimbursement line via the multi-select picker', function () {
    $gst = TaxCode::create(['code' => 'RTX1', 'name' => 'Reimb Tax 1 (5%)', 'rate_basis_points' => 500]);
    $pst = TaxCode::create(['code' => 'RTX2', 'name' => 'Reimb Tax 2 (7%)', 'rate_basis_points' => 700]);

    Livewire::test('pages::reimbursements.form', ['company' => $this->company])
        ->set('contact_id', $this->employee->id)
        ->set('lines', [[
            'account_id' => $this->expense->id,
            'description' => 'Mileage',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_code_id' => $gst->id,
            'secondary_tax_code_id' => $pst->id,
            'tax_code_ids' => [$gst->id, $pst->id],
            'subtotal' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Bill::query()->where('bill_type', BillType::Reimbursement->value)->firstOrFail()->lines()->firstOrFail();

    expect($line->tax_code_id)->toBe($gst->id)
        ->and($line->secondary_tax_code_id)->toBe($pst->id)
        ->and($line->line_tax_cents)->toBe(500)        // GST 5%
        ->and($line->secondary_tax_cents)->toBe(700);  // PST 7%
});

it('honors a manual tax override on a reimbursement line', function () {
    $gst = TaxCode::create(['code' => 'RTX3', 'name' => 'Reimb Tax 3 (5%)', 'rate_basis_points' => 500]);

    Livewire::test('pages::reimbursements.form', ['company' => $this->company])
        ->set('contact_id', $this->employee->id)
        ->set('lines', [[
            'account_id' => $this->expense->id,
            'description' => 'Parking',
            'quantity' => '1',
            'unit_price' => '100.00',
            'tax_code_id' => $gst->id,
            'secondary_tax_code_id' => null,
            'tax_code_ids' => [$gst->id],
            'tax_override' => '3.33',   // override the auto-computed 5.00
            'subtotal' => 0,
            'auto_tax' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Bill::query()->where('bill_type', BillType::Reimbursement->value)->firstOrFail()->lines()->firstOrFail();

    expect($line->line_tax_cents)->toBe(333)            // the override wins, not 500
        ->and($line->tax_override_cents)->toBe(333);
});
