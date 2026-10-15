<?php

use App\Actions\Recurring\SaveRecurringDocument;
use App\Actions\Sales\SaveCreditMemo;
use App\Actions\Sales\SaveReceipt;
use App\Actions\Sales\SaveSalesReceipt;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\ReceiptStatus;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\EditLock;
use App\Models\SalesReceipt;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

/**
 * Edit locks on the credit memo, customer receipt, sales receipt and recurring
 * schedule pages: the full-page forms take the lock, and the show pages refuse
 * their record-changing actions while someone else is editing.
 */
beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->locks = app(EditLockManager::class);

    $this->customer = Contact::query()->create(['display_name' => 'Sales B Customer', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();

    // Each builder returns a draft record with memo "Original".
    $this->build = fn (string $kind): Model => match ($kind) {
        'credit memo' => app(SaveCreditMemo::class)->handle([
            'contact_id' => $this->customer->id,
            'credit_memo_date' => '2026-06-01',
            'memo' => 'Original',
            'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
        ]),
        'receipt' => app(SaveReceipt::class)->handle([
            'contact_id' => $this->customer->id,
            'receipt_date' => '2026-06-01',
            'deposit_to_account_id' => $this->undeposited->id,
            'amount_cents' => 5000,
            'memo' => 'Original',
        ]),
        'sales receipt' => app(SaveSalesReceipt::class)->handle([
            'contact_id' => $this->customer->id,
            'receipt_date' => '2026-06-01',
            'deposit_to_account_id' => $this->undeposited->id,
            'memo' => 'Original',
            'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
        ]),
        'recurring' => app(SaveRecurringDocument::class)->handle([
            'document_type' => 'invoice',
            'contact_id' => $this->customer->id,
            'name' => 'Sales B retainer',
            'memo' => 'Original',
            'frequency' => 'monthly',
            'start_date' => $this->company->currentDateTime()->toDateString(),
            'day_of_month' => 1,
            'end_type' => 'never',
            'lines' => [[
                'item_id' => null,
                'account_id' => $this->income->id,
                'description' => 'Service',
                'quantity' => '1',
                'unit_price_cents' => 10000,
                'tax_code_id' => null,
            ]],
        ]),
    };
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

dataset('sales b forms', [
    'credit memo' => ['credit memo', 'pages::credit-memos.form', 'credit_memo', 'saveDraft'],
    'receipt' => ['receipt', 'pages::receipts.form', 'receipt', 'save'],
    'sales receipt' => ['sales receipt', 'pages::sales-receipts.form', 'receipt', 'saveDraft'],
    'recurring' => ['recurring', 'pages::recurring.form', 'recurring', 'save'],
]);

it('takes the lock when the record is opened for editing', function (string $kind, string $component, string $param, string $save) {
    $record = ($this->build)($kind);

    $this->actingAs($this->jane);
    $form = Livewire::test($component, ['company' => $this->company, $param => $record])
        ->assertSet('editLockBlocked', false);

    expect($form->get('editLockToken'))->toHaveLength(40);

    $lock = EditLock::query()->sole();
    expect($lock->user_id)->toBe($this->jane->id)
        ->and($lock->lockable_type)->toBe($record::class)
        ->and((int) $lock->lockable_id)->toBe($record->getKey());
})->with('sales b forms');

it('shows a second member who is editing instead of the form, and refuses a crafted save', function (string $kind, string $component, string $param, string $save) {
    $record = ($this->build)($kind);

    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company, $param => $record]);

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $record->fresh()])
        ->assertSet('editLockBlocked', true)
        ->assertSee($this->jane->name)
        ->set('memo', 'Bob was here')
        ->call($save)
        ->assertDispatched('toast-show');

    expect($record->fresh()->memo)->toBe('Original')
        ->and(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
})->with('sales b forms');

it('guards exactly the show page methods that change the record', function (string $component, array $expected) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    $guarded = collect((new ReflectionClass($class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe($expected);
})->with([
    'credit memo' => ['pages::credit-memos.show', ['deleteRefundCheque', 'deleteRefundReceipt', 'submitRefund', 'void']],
    'receipt' => ['pages::receipts.show', ['deleteRefundCheque', 'deleteRefundReceipt', 'submitRefund', 'void']],
    'sales receipt' => ['pages::sales-receipts.show', ['deleteDraft', 'void']],
    'recurring' => ['pages::recurring.show', ['deleteSchedule', 'generateNow', 'pauseSchedule', 'resumeSchedule']],
]);

it('refuses to pause a recurring schedule while someone else is editing it', function () {
    $schedule = ($this->build)('recurring');
    $this->locks->acquire($schedule, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule->fresh()])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'recurring transaction']))
        ->call('pauseSchedule')
        ->assertDispatched('toast-show');

    expect($schedule->fresh()->is_active)->toBeTrue();
});

it('pauses a recurring schedule when nobody is editing it, replacing the version', function () {
    $schedule = ($this->build)('recurring');
    $mine = $this->locks->acquire($schedule, $this->jane);
    $this->locks->release($mine->token, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule->fresh()])
        ->call('pauseSchedule');

    expect($schedule->fresh()->is_active)->toBeFalse()
        ->and($this->locks->currentVersion($schedule))->not->toBe($mine->version);
});

it('refuses to delete a draft sales receipt while someone else is editing it', function () {
    $receipt = ($this->build)('sales receipt');
    $this->locks->acquire($receipt, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::sales-receipts.show', ['company' => $this->company, 'receipt' => $receipt->fresh()])
        ->call('deleteDraft')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect(SalesReceipt::query()->whereKey($receipt->id)->exists())->toBeTrue();
});

it('refuses to void a customer receipt while someone else is editing it', function () {
    $receipt = ($this->build)('receipt');
    app(ReceiptPoster::class)->post($receipt);
    $this->locks->acquire($receipt, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::receipts.show', ['company' => $this->company, 'receipt' => $receipt->fresh()])
        ->call('void')
        ->assertDispatched('toast-show');

    expect(CustomerReceipt::query()->findOrFail($receipt->id)->status)->not->toBe(ReceiptStatus::Void);
});
