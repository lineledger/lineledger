<?php

use App\Actions\Membership\BillMemberDues;
use App\Actions\Membership\SaveMember;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Services\Backup\BackupTableRegistry;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['features_membership' => true, 'fiscal_year_start_month' => 1]);
    app()->instance('current_company', $this->company);

    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function membershipLevel(array $overrides = []): MembershipLevel
{
    return MembershipLevel::factory()->create(array_merge([
        'default_dues_cents' => 10000,
        'revenue_account_id' => test()->income->id,
    ], $overrides));
}

test('SaveMember assigns a member number and flags the contact', function () {
    $contact = Contact::factory()->create(['is_member' => false, 'is_customer' => false]);

    $member = app(SaveMember::class)->handle([
        'contact_id' => $contact->id,
        'membership_level_id' => membershipLevel()->id,
        'started_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
    ]);

    expect($member->member_no)->toBe('MEM-000001');
    expect($contact->fresh()->is_member)->toBeTrue();
    expect($contact->fresh()->is_customer)->toBeTrue();
});

test('a contact can only have one membership per company', function () {
    $contact = Contact::factory()->create();
    Member::factory()->create(['contact_id' => $contact->id]);

    Member::factory()->create(['contact_id' => $contact->id]);
})->throws(QueryException::class);

test('effectiveStatus derives from term dates and cancellation', function () {
    $today = $this->company->currentDateTime()->startOfDay();

    $active = Member::factory()->create(['expires_on' => $today->addMonths(2)->toDateString()]);
    $lapsed = Member::factory()->create(['expires_on' => $today->subDays(10)->toDateString()]);
    $expired = Member::factory()->create(['expires_on' => $today->subDays(90)->toDateString()]);
    $lifetime = Member::factory()->create(['expires_on' => null]);
    $cancelled = Member::factory()->create(['expires_on' => $today->addMonths(2)->toDateString(), 'cancelled_at' => now()]);

    expect($active->effectiveStatus())->toBe(MembershipStatus::Active);
    expect($lapsed->effectiveStatus())->toBe(MembershipStatus::Lapsed);
    expect($expired->effectiveStatus())->toBe(MembershipStatus::Expired);
    expect($lifetime->effectiveStatus())->toBe(MembershipStatus::Active);
    expect($cancelled->effectiveStatus())->toBe(MembershipStatus::Cancelled);
});

test('BillMemberDues creates a draft invoice on the level revenue account', function () {
    $level = membershipLevel(['default_dues_cents' => 12000]);
    $member = Member::factory()->create(['membership_level_id' => $level->id, 'dues_cents' => null]);

    $invoice = app(BillMemberDues::class)->handle($member);

    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->member_id)->toBe($member->id);
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->account_id)->toBe($this->income->id);
    expect($invoice->lines->first()->unit_price_cents)->toBe(12000);
});

test('a per-member dues override beats the level default', function () {
    $level = membershipLevel(['default_dues_cents' => 12000]);
    $member = Member::factory()->create(['membership_level_id' => $level->id, 'dues_cents' => 5000]);

    $invoice = app(BillMemberDues::class)->handle($member);

    expect($invoice->lines->first()->unit_price_cents)->toBe(5000);
});

test('Bill dues now opens the draft invoice in edit mode', function () {
    $level = membershipLevel(['default_dues_cents' => 12000]);
    $member = Member::factory()->create(['membership_level_id' => $level->id, 'dues_cents' => null]);

    $component = Livewire::test('pages::members.show', ['company' => $this->company, 'member' => $member])->call('billDues');

    $invoice = Invoice::query()->where('member_id', $member->id)->latest('id')->firstOrFail();

    $component->assertRedirect(route('invoices.edit', ['company' => $this->company->slug, 'invoice' => $invoice]));
});

test('posting a dues invoice books DR accounts receivable / CR revenue', function () {
    $level = membershipLevel(['default_dues_cents' => 10000]);
    $member = Member::factory()->create(['membership_level_id' => $level->id]);

    $invoice = app(BillMemberDues::class)->handle($member, ['post' => true]);
    $invoice->refresh()->load('journalEntry.lines');

    expect($invoice->status)->toBe(InvoiceStatus::Posted);
    $entry = $invoice->journalEntry;
    expect($entry)->not->toBeNull();

    $debits = $entry->lines->sum('debit_cents');
    $credits = $entry->lines->sum('credit_cents');
    expect($debits)->toBe($credits)->toBe(10000);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    expect($entry->lines->firstWhere('account_id', $ar->id)->debit_cents)->toBe(10000);
    expect($entry->lines->firstWhere('account_id', $this->income->id)->credit_cents)->toBe(10000);
});

test('BillMemberDues throws when the level has no revenue account', function () {
    $level = membershipLevel(['revenue_account_id' => null]);
    $member = Member::factory()->create(['membership_level_id' => $level->id]);

    app(BillMemberDues::class)->handle($member);
})->throws(RuntimeException::class);

test('the members pages are gated on the feature flag', function () {
    Livewire::test('pages::members.index', ['company' => $this->company])->assertOk();

    $off = Company::factory()->create(['features_membership' => false]);
    $off->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $off);

    Livewire::test('pages::members.index', ['company' => $off])->assertStatus(403);
});

test('members is registered for backup', function () {
    expect(array_column(BackupTableRegistry::tables(), 'table'))->toContain('members');
});

test('the member form creates a new contact inline', function () {
    $level = membershipLevel();

    Livewire::test('pages::members.form', ['company' => $this->company])
        ->assertSet('contact_mode', 'new')
        ->set('new_display_name', 'Jane Member')
        ->set('new_company_name', 'Acme Co')
        ->set('new_email', 'jane@example.com')
        ->set('new_phone', '555-0100')
        ->set('new_billing_line1', '1 Main St')
        ->set('new_billing_city', 'Calgary')
        ->set('new_billing_region', 'AB')
        ->set('new_billing_postal_code', 'T2P 1A1')
        ->set('new_billing_country', 'CA')
        ->set('membership_level_id', $level->id)
        ->call('save')
        ->assertHasNoErrors();

    $contact = Contact::query()->where('display_name', 'Jane Member')->first();

    expect($contact)->not->toBeNull();
    expect($contact->is_member)->toBeTrue();
    expect($contact->is_customer)->toBeTrue();
    expect($contact->company_name)->toBe('Acme Co');
    expect($contact->email)->toBe('jane@example.com');
    expect($contact->phone)->toBe('555-0100');
    expect($contact->billing_line1)->toBe('1 Main St');
    expect($contact->billing_city)->toBe('Calgary');
    expect($contact->billing_region)->toBe('AB');
    expect($contact->billing_postal_code)->toBe('T2P 1A1');
    expect($contact->billing_country)->toBe('CA');
    expect(Member::query()->where('contact_id', $contact->id)->exists())->toBeTrue();
});

test('the member form requires a name when creating a new contact', function () {
    Livewire::test('pages::members.form', ['company' => $this->company])
        ->set('contact_mode', 'new')
        ->set('new_display_name', '')
        ->call('save')
        ->assertHasErrors(['new_display_name' => 'required']);
});

test('the member form still links to an existing contact', function () {
    $contact = Contact::factory()->create(['is_member' => false]);
    $level = membershipLevel();

    Livewire::test('pages::members.form', ['company' => $this->company])
        ->set('contact_mode', 'existing')
        ->set('contact_id', $contact->id)
        ->set('membership_level_id', $level->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Member::query()->where('contact_id', $contact->id)->exists())->toBeTrue();
    expect($contact->fresh()->is_member)->toBeTrue();
});

test('the member form blocks a second membership for an existing contact', function () {
    $contact = Contact::factory()->create();
    Member::factory()->create(['contact_id' => $contact->id]);

    Livewire::test('pages::members.form', ['company' => $this->company])
        ->set('contact_mode', 'existing')
        ->set('contact_id', $contact->id)
        ->call('save')
        ->assertHasErrors('contact_id');
});
