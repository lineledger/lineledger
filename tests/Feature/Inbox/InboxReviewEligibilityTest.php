<?php

use App\Actions\Documents\PromoteInboxItem;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Jobs\Inbox\ProcessInboxItem;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\Inbox\OCR\Contracts\ReceiptIntelligence;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The inbox review page files a document onto the Expense, Bill or Reimbursement
 * form, so each of its pickers has to agree with the form it feeds. A choice only
 * the review page offers either can't be saved later or books to the wrong place.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expenseAccount = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function reviewableInboxItem(User $user, array $attributes = []): InboxItem
{
    $item = InboxItem::create([
        'source' => InboxItemSource::Upload,
        'status' => InboxItemStatus::Pending,
        'original_filename' => 'receipt.pdf',
        'mime' => 'application/pdf',
        'created_by_user_id' => $user->id,
    ]);

    $item->forceFill([
        'status' => InboxItemStatus::NeedsReview->value,
        'suggested_document_type' => 'expense',
        'extracted' => ['vendor' => 'Coffee Shop', 'amount_cents' => 850, 'date' => '2026-06-10'],
        ...$attributes,
    ])->save();

    return $item->refresh();
}

function inboxReviewPage(Company $company, InboxItem $item)
{
    return Livewire::test('pages::inbox.show', ['company' => $company, 'item' => $item]);
}

/*
 * Paid from (#198)
 */

it('offers the same Paid from accounts as the Expense form', function () {
    $card = expensePaidFromAccount('2110', 'Company Visa', AccountSubtype::CreditCard);
    $loan = expensePaidFromAccount('2780', 'Due to Shareholder', AccountSubtype::CurrentLiability, forExpenses: true);
    $vehicle = expensePaidFromAccount('2710', 'Vehicle Loan', AccountSubtype::LongTermLiability);
    $pettyCash = expensePaidFromAccount('1050', 'Petty Cash', AccountSubtype::CurrentAsset, forExpenses: true);
    $closedBank = expensePaidFromAccount('1090', 'Closed Chequing', AccountSubtype::Bank);
    $closedBank->update(['is_active' => false]);
    $prepaid = Account::query()->where('name', 'Prepaid Expenses')->firstOrFail();

    $offered = inboxReviewPage($this->company, reviewableInboxItem($this->user))
        ->instance()->paymentAccountOptions->pluck('id');

    $expenseForm = Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->instance()->paymentAccounts->pluck('id');

    expect($offered->sort()->values()->all())->toBe($expenseForm->sort()->values()->all())
        ->and($offered)->toContain($this->bank->id, $card->id, $loan->id, $pettyCash->id)
        ->not->toContain($vehicle->id)
        ->not->toContain($prepaid->id)
        ->not->toContain($closedBank->id);
});

it('files an expense against a shareholder loan switched on for expenses', function () {
    $loan = expensePaidFromAccount('2780', 'Due to Shareholder', AccountSubtype::CurrentLiability, forExpenses: true);

    inboxReviewPage($this->company, reviewableInboxItem($this->user))
        ->set('documentType', 'expense')
        ->set('paymentAccountId', $loan->id)
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->call('promote')
        ->assertHasNoErrors();

    expect(Expense::firstOrFail()->payment_account_id)->toBe($loan->id);
});

it('refuses a Paid from account the Expense form would refuse', function (AccountSubtype $subtype, bool $forExpenses, bool $system) {
    $account = expensePaidFromAccount('2498', 'Not allowed', $subtype, $forExpenses, $system);

    inboxReviewPage($this->company, reviewableInboxItem($this->user))
        ->set('documentType', 'expense')
        ->set('paymentAccountId', $account->id)
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->call('promote')
        ->assertHasErrors(['paymentAccountId']);

    expect(Expense::count())->toBe(0);
})->with([
    'current asset left switched off' => [AccountSubtype::CurrentAsset, false, false],
    'liability left switched off' => [AccountSubtype::CurrentLiability, false, false],
    'system liability switched on' => [AccountSubtype::CurrentLiability, true, true],
    'accounts payable switched on' => [AccountSubtype::AccountsPayable, true, false],
    'expense account switched on' => [AccountSubtype::Expense, true, false],
]);

it('refuses a deactivated Paid from account', function () {
    $this->bank->update(['is_active' => false]);

    inboxReviewPage($this->company, reviewableInboxItem($this->user))
        ->set('documentType', 'expense')
        ->set('paymentAccountId', $this->bank->id)
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->call('promote')
        ->assertHasErrors(['paymentAccountId']);
});

it('defaults Paid from in the Action with the same rule, not to a prepaid', function () {
    Account::query()
        ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
        ->update(['is_active' => false]);
    $loan = expensePaidFromAccount('2780', 'Due to Shareholder', AccountSubtype::CurrentLiability, forExpenses: true);

    $expense = app(PromoteInboxItem::class)->handle(reviewableInboxItem($this->user), [
        'document_type' => 'expense',
        'account_id' => $this->expenseAccount->id,
    ]);

    expect($expense->payment_account_id)->toBe($loan->id);
});

it('asks for a Paid from account in the Action when none qualifies', function () {
    Account::query()
        ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
        ->update(['is_active' => false]);

    app(PromoteInboxItem::class)->handle(reviewableInboxItem($this->user), [
        'document_type' => 'expense',
        'account_id' => $this->expenseAccount->id,
    ]);
})->throws(RuntimeException::class, 'A payment account is required');

/*
 * Contact
 */

it('lists only vendors for a bill and only employees for a reimbursement', function () {
    $vendor = Contact::factory()->vendor()->create();
    $customer = Contact::factory()->customer()->create();
    $employee = Contact::factory()->create(['is_employee' => true]);

    $page = inboxReviewPage($this->company, reviewableInboxItem($this->user));

    $page->set('documentType', 'bill');
    expect($page->instance()->contactOptions->pluck('id'))
        ->toContain($vendor->id)->not->toContain($customer->id)->not->toContain($employee->id);

    $page->set('documentType', 'reimbursement');
    expect($page->instance()->contactOptions->pluck('id'))
        ->toContain($employee->id)->not->toContain($vendor->id)->not->toContain($customer->id);

    $page->set('documentType', 'expense');
    expect($page->instance()->contactOptions->pluck('id'))
        ->toContain($vendor->id, $customer->id, $employee->id);
});

it('refuses a bill for a contact who is not a vendor', function () {
    $customer = Contact::factory()->customer()->create();

    inboxReviewPage($this->company, reviewableInboxItem($this->user, ['suggested_document_type' => 'bill']))
        ->set('contactId', $customer->id)
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->call('promote')
        ->assertHasErrors(['contactId']);

    expect(Bill::count())->toBe(0);
});

it('refuses a reimbursement for a contact who is not an employee', function () {
    $vendor = Contact::factory()->vendor()->create();

    inboxReviewPage($this->company, reviewableInboxItem($this->user, ['suggested_document_type' => 'reimbursement']))
        ->set('contactId', $vendor->id)
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->call('promote')
        ->assertHasErrors(['contactId']);

    expect(Bill::count())->toBe(0);
});

it('files a bill for a vendor and a reimbursement for an employee', function () {
    $vendor = Contact::factory()->vendor()->create();
    $employee = Contact::factory()->create(['is_employee' => true]);

    inboxReviewPage($this->company, reviewableInboxItem($this->user, ['suggested_document_type' => 'bill']))
        ->set('contactId', $vendor->id)
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->call('promote')
        ->assertHasNoErrors();

    inboxReviewPage($this->company, reviewableInboxItem($this->user, ['suggested_document_type' => 'reimbursement']))
        ->set('contactId', $employee->id)
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->call('promote')
        ->assertHasNoErrors();

    expect(Bill::query()->where('contact_id', $vendor->id)->firstOrFail()->bill_type)->toBe(BillType::Vendor)
        ->and(Bill::query()->where('contact_id', $employee->id)->firstOrFail()->bill_type)->toBe(BillType::Reimbursement);
});

it('drops a suggested contact the opening document type cannot use', function () {
    $customer = Contact::factory()->customer()->create();
    $vendor = Contact::factory()->vendor()->create();

    inboxReviewPage($this->company, reviewableInboxItem($this->user, [
        'suggested_document_type' => 'bill',
        'suggested_contact_id' => $customer->id,
    ]))->assertSet('contactId', null);

    inboxReviewPage($this->company, reviewableInboxItem($this->user, [
        'suggested_document_type' => 'bill',
        'suggested_contact_id' => $vendor->id,
    ]))->assertSet('contactId', $vendor->id);

    inboxReviewPage($this->company, reviewableInboxItem($this->user, [
        'suggested_document_type' => 'expense',
        'suggested_contact_id' => $customer->id,
    ]))->assertSet('contactId', $customer->id);
});

it('clears a contact when switching to a type that cannot use it', function () {
    $customer = Contact::factory()->customer()->create();

    inboxReviewPage($this->company, reviewableInboxItem($this->user, ['suggested_contact_id' => $customer->id]))
        ->assertSet('contactId', $customer->id)
        ->set('documentType', 'bill')
        ->assertSet('contactId', null);
});

it('does not bring a cleared contact back as the payee', function () {
    $customer = Contact::factory()->customer()->create();

    inboxReviewPage($this->company, reviewableInboxItem($this->user, [
        'suggested_document_type' => 'bill',
        'suggested_contact_id' => $customer->id,
    ]))
        ->assertSet('contactId', null)
        ->set('documentType', 'expense')
        ->set('paymentAccountId', $this->bank->id)
        ->set('lines.0.account_id', $this->expenseAccount->id)
        ->call('promote')
        ->assertHasNoErrors();

    expect(Expense::firstOrFail()->payee_contact_id)->toBeNull();
});

it('still uses the suggested contact when the Action is given no contact at all', function () {
    $vendor = Contact::factory()->vendor()->create();

    $bill = app(PromoteInboxItem::class)->handle(
        reviewableInboxItem($this->user, ['suggested_contact_id' => $vendor->id]),
        ['document_type' => 'bill', 'account_id' => $this->expenseAccount->id],
    );

    expect($bill->contact_id)->toBe($vendor->id);
});

it('points to the vendor list when a bill has no vendor to choose', function () {
    inboxReviewPage($this->company, reviewableInboxItem($this->user, ['suggested_document_type' => 'bill']))
        ->assertSee(__('No vendors yet.'))
        ->assertSee(__('Add a vendor'));

    Contact::factory()->vendor()->create();

    inboxReviewPage($this->company, reviewableInboxItem($this->user, ['suggested_document_type' => 'bill']))
        ->assertDontSee(__('No vendors yet.'));
});

it('suggests an active vendor over an earlier customer of the same name', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/receipt.pdf', '%PDF-1.4 fake receipt');
    $this->company->forceFill(['settings' => ['inbox' => ['ocr_enabled' => true]]])->save();

    Contact::factory()->customer()->create(['display_name' => 'Staples Canada']);
    Contact::factory()->vendor()->create(['display_name' => 'Staples Canada Inc.', 'is_active' => false]);
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Staples Canada Ltd.']);

    $item = reviewableInboxItem($this->user);
    $attachment = Attachment::create([
        'attachable_type' => $item->getMorphClass(),
        'attachable_id' => $item->id,
        'disk' => 'local',
        'path' => 'attachments/receipt.pdf',
        'original_filename' => 'receipt.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 21,
        'uploaded_by_id' => $this->user->id,
    ]);
    $item->forceFill(['attachment_id' => $attachment->id])->save();

    (new ProcessInboxItem($item->id))->handle(new class implements ReceiptIntelligence
    {
        public function isEnabled(): bool
        {
            return true;
        }

        public function extract(string $absolutePath, string $mime): ?array
        {
            return ['vendor' => 'Staples Canada', 'amount_cents' => 4250, 'currency' => 'CAD', 'date' => '2026-06-20', 'line_items' => []];
        }

        public function lastError(): ?string
        {
            return null;
        }
    });

    expect($item->refresh()->suggested_contact_id)->toBe($vendor->id);
});

/*
 * Line accounts
 */

it('offers only active line accounts, keeping one already on a line', function () {
    $closed = expensePaidFromAccount('6990', 'Closed Category', AccountSubtype::Expense);
    $closed->update(['is_active' => false]);

    $page = inboxReviewPage($this->company, reviewableInboxItem($this->user));

    expect($page->instance()->accountOptions->pluck('id'))->not->toContain($closed->id);

    $page->set('lines.0.account_id', $closed->id);

    expect($page->instance()->accountOptions->pluck('id'))->toContain($closed->id);
});

it('narrows line accounts to what the destination form takes', function () {
    $income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail();
    $asset = expensePaidFromAccount('1890', 'Equipment Deposit', AccountSubtype::OtherAsset);
    $controls = Account::query()->whereIn('subtype', [
        AccountSubtype::AccountsReceivable->value,
        AccountSubtype::AccountsPayable->value,
        AccountSubtype::UndepositedFunds->value,
    ])->pluck('id');

    expect($controls)->not->toBeEmpty();

    // Start with a line that holds no account, so nothing is kept for being on it.
    $page = inboxReviewPage($this->company, reviewableInboxItem($this->user))->set('lines.0.account_id', null);

    $page->set('documentType', 'bill');
    $bill = $page->instance()->accountOptions->pluck('id');
    expect($bill)->toContain($this->expenseAccount->id, $asset->id)->not->toContain($income->id);

    $page->set('documentType', 'reimbursement');
    $reimbursement = $page->instance()->accountOptions->pluck('id');
    expect($reimbursement)->toContain($this->expenseAccount->id)->not->toContain($asset->id)->not->toContain($income->id);

    $page->set('documentType', 'expense');
    $expense = $page->instance()->accountOptions->pluck('id');
    expect($expense)->toContain($this->expenseAccount->id, $asset->id, $income->id);

    foreach ([$bill, $reimbursement, $expense] as $offered) {
        expect($offered->intersect($controls))->toBeEmpty();
    }
});

it('skips a deactivated account when choosing the fallback category', function () {
    $this->expenseAccount->update(['is_active' => false]);

    $page = inboxReviewPage($this->company, reviewableInboxItem($this->user));

    $fallback = $page->get('lines.0.account_id');

    expect($fallback)->not->toBeNull()->not->toBe($this->expenseAccount->id);

    $expense = app(PromoteInboxItem::class)->handle(reviewableInboxItem($this->user), [
        'document_type' => 'expense',
        'payment_account_id' => $this->bank->id,
    ]);

    expect($expense->lines->first()->account_id)->toBe($fallback);
});
