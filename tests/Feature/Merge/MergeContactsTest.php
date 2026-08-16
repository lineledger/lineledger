<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Contacts\MergeContacts;
use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\Member;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Run a merge that is expected to be blocked and return the first guard message.
 */
function mergeContactsGuardMessage(Contact $loser, Contact $survivor): string
{
    try {
        app(MergeContacts::class)->handle($loser, $survivor);
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    test()->fail('Expected the merge to be blocked by a guard, but it succeeded.');
}

it('merges a duplicate vendor: documents, GL lines and attachments repoint, roles union, balances recompute', function () {
    $loser = Contact::factory()->vendor()->create([
        'display_name' => 'Acme Supplies Inc.',
        'tax_number' => '123456789RT0001',
        'track_t4a' => true,
    ]);
    $survivor = Contact::factory()->customer()->create(['display_name' => 'Acme Supplies']);

    $bill = Bill::create([
        'contact_id' => $loser->id,
        'bill_type' => BillType::Vendor->value,
        'bill_no' => 'B-MERGE-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2001',
        'cheque_date' => now()->toDateString(),
        'payee_contact_id' => $loser->id,
        'payee_name' => 'Acme Supplies Inc.',
    ]);

    // GL activity with the loser as the AR/AP dimension: AR 5000, AP 7000.
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => now()->toDateString(),
        'memo' => 'Merge seed',
        'lines' => [
            ['account_id' => $this->ar->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'contact_id' => $loser->id],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 5000],
            ['account_id' => $this->expense->id, 'debit_cents' => 7000, 'credit_cents' => 0],
            ['account_id' => $this->ap->id, 'debit_cents' => 0, 'credit_cents' => 7000, 'contact_id' => $loser->id],
        ],
    ]);
    app(JournalPoster::class)->post($entry);

    $attachment = Attachment::create([
        'attachable_type' => (new Contact)->getMorphClass(),
        'attachable_id' => $loser->id,
        'disk' => 'local',
        'path' => 'attachments/test.pdf',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'uploaded_by_id' => $this->user->id,
    ]);

    $result = app(MergeContacts::class)->handle($loser, $survivor);

    expect($result->id)->toBe($survivor->id);

    expect($bill->fresh()->contact_id)->toBe($survivor->id)
        ->and($cheque->fresh()->payee_contact_id)->toBe($survivor->id)
        ->and(DB::table('journal_lines')->where('contact_id', $loser->id)->count())->toBe(0)
        ->and(DB::table('journal_lines')->where('contact_id', $survivor->id)->count())->toBe(2)
        ->and($attachment->fresh()->attachable_id)->toBe($survivor->id);

    $survivor->refresh();
    expect($survivor->is_customer)->toBeTrue()
        ->and($survivor->is_vendor)->toBeTrue()
        ->and($survivor->tax_number)->toBe('123456789RT0001')
        ->and((bool) $survivor->track_t4a)->toBeTrue()
        ->and((int) $survivor->ar_balance_cents)->toBe(5000)
        ->and((int) $survivor->ap_balance_cents)->toBe(7000);

    $trashed = Contact::withTrashed()->find($loser->id);
    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->is_active)->toBeFalse()
        ->and((int) $trashed->ar_balance_cents)->toBe(0)
        ->and((int) $trashed->ap_balance_cents)->toBe(0);
});

it('writes two contact.merged audit rows and leaves the loser\'s old audit rows untouched', function () {
    $loser = Contact::factory()->vendor()->create(['display_name' => 'Dup Vendor']);
    $survivor = Contact::factory()->vendor()->create(['display_name' => 'Real Vendor']);

    // The contact.created row written when the loser was created is immutable
    // history — it must keep pointing at the loser id after the merge.
    $preMergeRow = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('action', AuditAction::ContactCreated)
        ->where('auditable_id', $loser->id)
        ->firstOrFail();

    app(MergeContacts::class)->handle($loser, $survivor);

    expect($preMergeRow->fresh()->auditable_id)->toBe($loser->id);

    $merged = AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('action', AuditAction::ContactMerged)
        ->get();

    expect($merged)->toHaveCount(2)
        ->and($merged->pluck('auditable_id')->all())->toContain($loser->id, $survivor->id);
});

it('blocks the merge when both contacts have employee payroll profiles', function () {
    $loser = Contact::factory()->create(['is_employee' => true]);
    $survivor = Contact::factory()->create(['is_employee' => true]);

    EmployeePayrollProfile::factory()->create(['company_id' => $this->company->id, 'contact_id' => $loser->id]);
    EmployeePayrollProfile::factory()->create(['company_id' => $this->company->id, 'contact_id' => $survivor->id]);

    expect(mergeContactsGuardMessage($loser, $survivor))->toContain('payroll profiles');
});

it('blocks the merge when both contacts appear in the same pay run', function () {
    $loser = Contact::factory()->create(['is_employee' => true]);
    $survivor = Contact::factory()->create(['is_employee' => true]);

    $loserProfile = EmployeePayrollProfile::factory()->create(['company_id' => $this->company->id, 'contact_id' => $loser->id]);
    $survivorProfile = EmployeePayrollProfile::factory()->create(['company_id' => $this->company->id, 'contact_id' => $survivor->id]);

    $schedule = PayrollSchedule::factory()->create(['company_id' => $this->company->id]);
    $run = PayRun::factory()->create([
        'company_id' => $this->company->id,
        'payroll_schedule_id' => $schedule->id,
    ]);

    foreach ([[$loser, $loserProfile], [$survivor, $survivorProfile]] as [$contact, $profile]) {
        $run->lines()->create([
            'contact_id' => $contact->id,
            'employee_payroll_profile_id' => $profile->id,
            'province_of_employment' => 'ON',
            'pay_basis' => PayBasis::Salary->value,
        ]);
    }

    expect(mergeContactsGuardMessage($loser, $survivor))->toContain('same pay run');
});

it('blocks the merge when both contacts have membership records', function () {
    $loser = Contact::factory()->create(['is_member' => true]);
    $survivor = Contact::factory()->create(['is_member' => true]);

    Member::factory()->create(['contact_id' => $loser->id]);
    Member::factory()->create(['contact_id' => $survivor->id]);

    expect(mergeContactsGuardMessage($loser, $survivor))->toContain('membership records');
});

it('blocks merging contacts of different currencies', function () {
    $loser = Contact::factory()->vendor()->create(['currency_code' => 'USD']);
    $survivor = Contact::factory()->vendor()->create();

    expect(mergeContactsGuardMessage($loser, $survivor))->toContain('same currency');
});

it('re-parents the loser\'s sub-customers onto the survivor', function () {
    $loser = Contact::factory()->customer()->create(['display_name' => 'Parent (dup)']);
    $survivor = Contact::factory()->customer()->create(['display_name' => 'Parent']);
    $child = Contact::factory()->customer()->create([
        'display_name' => 'Job site A',
        'parent_id' => $loser->id,
    ]);

    app(MergeContacts::class)->handle($loser, $survivor);

    expect($child->refresh()->parent_id)->toBe($survivor->id);
});

it('blocks merging a customer into one of its own sub-customers', function () {
    $parent = Contact::factory()->customer()->create(['display_name' => 'Parent']);
    $child = Contact::factory()->customer()->create([
        'display_name' => 'Job site A',
        'parent_id' => $parent->id,
    ]);

    expect(mergeContactsGuardMessage($parent, $child))->toContain('sub-customer');

    // Nothing moved: the child still hangs off its original parent.
    expect($child->refresh()->parent_id)->toBe($parent->id);
});
