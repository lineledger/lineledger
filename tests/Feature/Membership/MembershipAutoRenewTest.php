<?php

use App\Actions\Membership\SaveMember;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Models\RecurringDocument;
use App\Models\User;
use App\Services\Recurring\RecurringDocumentGenerator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['features_membership' => true, 'fiscal_year_start_month' => 1]);
    app()->instance('current_company', $this->company);

    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->first();
    $this->level = MembershipLevel::factory()->create([
        'default_dues_cents' => 10000,
        'billing_frequency' => RecurrenceFrequency::Annual->value,
        'revenue_account_id' => $this->income->id,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function autoRenewMember(array $overrides = []): Member
{
    $contact = Contact::factory()->create();

    return app(SaveMember::class)->handle(array_merge([
        'contact_id' => $contact->id,
        'membership_level_id' => test()->level->id,
        'started_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
        'auto_renew' => true,
    ], $overrides));
}

test('enabling auto-renew creates a linked recurring invoice schedule', function () {
    $member = autoRenewMember();

    expect($member->recurring_document_id)->not->toBeNull();

    $doc = RecurringDocument::find($member->recurring_document_id);
    expect($doc->isInvoice())->toBeTrue();
    expect($doc->is_active)->toBeTrue();
    expect($doc->contact_id)->toBe($member->contact_id);
    expect($doc->frequency)->toBe(RecurrenceFrequency::Annual);
    expect($doc->next_run_date->toDateString())->toBe('2026-12-31');
    expect($doc->lines)->toHaveCount(1);
    expect($doc->lines->first()->account_id)->toBe($this->income->id);
    expect($doc->lines->first()->unit_price_cents)->toBe(10000);
});

test('the recurring schedule generates a dues invoice stamped with the member', function () {
    $member = autoRenewMember(['expires_on' => '2026-06-05']);
    $doc = RecurringDocument::find($member->recurring_document_id);

    $created = app(RecurringDocumentGenerator::class)->generateDue($doc, CarbonImmutable::parse('2026-06-05'));

    expect($created)->toHaveCount(1);
    $invoice = $created->first();
    expect($invoice->member_id)->toBe($member->id);
    expect($invoice->recurring_document_id)->toBe($doc->id);
    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->total_cents)->toBe(10000);
});

test('a per-member dues override flows into the recurring schedule', function () {
    $member = autoRenewMember(['dues_cents' => 7500]);

    $doc = RecurringDocument::find($member->recurring_document_id);
    expect($doc->lines->first()->unit_price_cents)->toBe(7500);
});

test('turning auto-renew off pauses the schedule', function () {
    $member = autoRenewMember();
    $docId = $member->recurring_document_id;

    app(SaveMember::class)->handle([
        'contact_id' => $member->contact_id,
        'membership_level_id' => $this->level->id,
        'started_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
        'auto_renew' => false,
    ], $member->fresh());

    $doc = RecurringDocument::find($docId);
    expect($doc->is_active)->toBeFalse();
    expect($doc->next_run_date)->toBeNull();
    expect($doc->paused_reason)->not->toBeNull();
});

test('re-enabling auto-renew reactivates the same schedule', function () {
    $member = autoRenewMember();
    $docId = $member->recurring_document_id;

    app(SaveMember::class)->handle([
        'contact_id' => $member->contact_id,
        'membership_level_id' => $this->level->id,
        'started_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
        'auto_renew' => false,
    ], $member->fresh());

    $reEnabled = app(SaveMember::class)->handle([
        'contact_id' => $member->contact_id,
        'membership_level_id' => $this->level->id,
        'started_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
        'auto_renew' => true,
    ], $member->fresh());

    expect($reEnabled->recurring_document_id)->toBe($docId);
    $doc = RecurringDocument::find($docId);
    expect($doc->is_active)->toBeTrue();
    expect($doc->next_run_date)->not->toBeNull();
    expect($doc->paused_reason)->toBeNull();
});

test('auto-renew creates no schedule when the level has no revenue account', function () {
    $level = MembershipLevel::factory()->create(['default_dues_cents' => 10000, 'revenue_account_id' => null]);
    $member = autoRenewMember(['membership_level_id' => $level->id]);

    expect($member->recurring_document_id)->toBeNull();
});

test('the member show page renders the next auto-renewal date', function () {
    $member = autoRenewMember(['expires_on' => '2026-12-31']);

    Livewire\Livewire::test('pages::members.show', ['company' => $this->company, 'member' => $member])
        ->assertOk()
        ->assertSee('Dec 31, 2026');
});
