<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for the cross-tenant IDOR (C1): the plain controller /
 * closure routes resolve route-model bindings via SubstituteBindings before
 * EnsureCompanyMembership binds `current_company`, so the global CompanyScope
 * is inactive at binding time. Each route now asserts the bound record belongs
 * to the company in the URL. A member of company A must never reach company B's
 * cheques, payment cheques, or attachments by ID.
 */
beforeEach(function () {
    Storage::fake('local');

    // Victim company B with private records.
    $this->victim = User::factory()->create();
    $this->companyB = Company::factory()->create();
    $this->companyB->members()->attach($this->victim, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->companyB);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $vendor = Contact::create(['display_name' => 'Acme Supplies', 'is_vendor' => true]);

    $this->chequeB = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => '5001',
        'cheque_date' => '2026-05-20',
        'payee_name' => 'Jane Doe',
    ]);

    $this->paymentB = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_no' => 'PAY-B-1',
        'payment_date' => '2026-05-20',
        'paid_from_account_id' => $bank->id,
        'reference' => '7777',
        'amount_cents' => 1000,
    ]);

    Storage::disk('local')->put('attachments/secret-b.pdf', 'TOP SECRET FINANCIALS');
    $this->attachmentB = Attachment::create([
        'attachable_type' => $this->chequeB->getMorphClass(),
        'attachable_id' => $this->chequeB->id,
        'disk' => 'local',
        'path' => 'attachments/secret-b.pdf',
        'original_filename' => 'secret-b.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 20,
        'uploaded_by_id' => $this->victim->id,
    ]);

    app()->forgetInstance('current_company');

    // Attacker, member of company A only.
    $this->attacker = User::factory()->create();
    $this->companyA = Company::factory()->create();
    $this->companyA->members()->attach($this->attacker, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->attacker);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('does not let a company A member print company B cheque', function () {
    $this->get(route('cheques.print', ['company' => $this->companyA->slug, 'cheque' => $this->chequeB->id]))
        ->assertNotFound();
});

it('does not let a company A member print company B bill payment cheque', function () {
    $this->get(route('bill-payments.print-cheque', ['company' => $this->companyA->slug, 'payment' => $this->paymentB->id]))
        ->assertNotFound();
});

it('does not let a company A member download company B attachment', function () {
    $response = $this->get(route('attachments.download', ['company' => $this->companyA->slug, 'attachment' => $this->attachmentB->id]));

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('TOP SECRET FINANCIALS');
});
