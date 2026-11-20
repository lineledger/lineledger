<?php

use App\Actions\Accounting\SaveAccount;
use App\Actions\Banking\SaveCheque;
use App\Actions\Banking\SaveDeposit;
use App\Actions\Banking\SaveTransfer;
use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Enums\CompanyRole;
use App\Enums\DepositStatus;
use App\Enums\TransferStatus;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EditLock;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\TransferPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    app()->instance('current_company', $this->company);

    $this->locks = app(EditLockManager::class);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $this->makeCheque = fn (array $overrides = []): Cheque => app(SaveCheque::class)->handle(array_merge([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '5001',
        'cheque_date' => '2026-06-01',
        'payee_name' => 'Edit Lock Payee',
        'memo' => 'Original memo',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Supplies', 'amount_cents' => 10000]],
    ], $overrides));

    $this->makeTransfer = function () {
        $savings = app(SaveAccount::class)->handle([
            'code' => '1011', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
        ]);

        return app(SaveTransfer::class)->handle([
            'from_account_id' => $this->bank->id,
            'to_account_id' => $savings->id,
            'transfer_date' => '2026-06-01',
            'from_amount_cents' => 25000,
            'to_amount_cents' => 25000,
            'memo' => 'Original memo',
        ]);
    };

    // Each form: its component, the mount parameter, and a draft record built
    // through the same Action the form saves with.
    $this->bankingForms = [
        'cheque' => ['pages::cheques.form', fn () => ($this->makeCheque)()],
        'deposit' => ['pages::deposits.form', fn () => app(SaveDeposit::class)->handle([
            'bank_account_id' => $this->bank->id,
            'deposit_date' => '2026-06-01',
            'memo' => 'Original memo',
            'lines' => [['account_id' => $this->income->id, 'description' => 'Owner contribution', 'amount_cents' => 50000]],
        ])],
        'transfer' => ['pages::transfers.form', fn () => ($this->makeTransfer)()],
    ];

    $this->mountBankingForm = function (string $param, $record) {
        [$component] = $this->bankingForms[$param];

        return Livewire::test($component, ['company' => $this->company, $param => $record]);
    };
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('takes the lock when an existing record opens in its edit form', function (string $param) {
    $record = ($this->bankingForms[$param][1])();

    $this->actingAs($this->jane);
    $form = ($this->mountBankingForm)($param, $record)->assertSet('editLockBlocked', false);

    expect($form->get('editLockToken'))->toHaveLength(40)
        ->and(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
})->with(['cheque', 'deposit', 'transfer']);

it('shows a second member who is editing instead of the form, and refuses a crafted save', function (string $param) {
    $record = ($this->bankingForms[$param][1])();

    $this->actingAs($this->jane);
    ($this->mountBankingForm)($param, $record);

    $this->actingAs($this->bob);
    ($this->mountBankingForm)($param, $record)
        ->assertSet('editLockBlocked', true)
        ->assertSee($this->jane->name)
        ->assertDontSee(__('Save draft'))
        ->set('memo', 'Bob was here')
        ->call('saveDraft')
        ->assertDispatched('toast-show');

    expect($record->fresh()->memo)->toBe('Original memo');
})->with(['cheque', 'deposit', 'transfer']);

it('lets the member holding the lock save the draft', function (string $param) {
    $record = ($this->bankingForms[$param][1])();

    $this->actingAs($this->jane);
    ($this->mountBankingForm)($param, $record)
        ->set('memo', 'Updated by Jane')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($record->fresh()->memo)->toBe('Updated by Jane')
        ->and(EditLock::query()->sole()->changed_at_ms)->not->toBeNull();
})->with(['cheque', 'deposit', 'transfer']);

it('skips the payee address write-back while someone is editing the payee, and still saves the cheque', function () {
    $vendor = Contact::query()->create([
        'display_name' => 'Edit Lock Vendor', 'is_vendor' => true, 'billing_line1' => '12 Old Street', 'billing_city' => 'Winnipeg',
    ]);
    $this->locks->acquire($vendor, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->call('selectPayee', $vendor->id)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '100.00')
        ->set('payee_line1', '500 New Avenue')
        ->call('postCheque')
        ->call('confirmAddressWriteBack', true)
        ->assertHasNoErrors()
        ->assertDispatched('toast-show', fn (string $event, array $params) => str_contains($params['slots']['text'] ?? '', $this->jane->name));

    $cheque = Cheque::query()->sole();

    expect($vendor->fresh()->billing_line1)->toBe('12 Old Street')
        ->and($cheque->payee_line1)->toBe('500 New Avenue')
        ->and($cheque->status)->toBe(ChequeStatus::Posted);
});

it('writes the address back to a payee nobody is editing, replacing its version, from an edit form holding the cheque', function () {
    $vendor = Contact::query()->create([
        'display_name' => 'Edit Lock Vendor', 'is_vendor' => true, 'billing_line1' => '12 Old Street', 'billing_city' => 'Winnipeg',
    ]);
    $cheque = ($this->makeCheque)(['payee_contact_id' => $vendor->id, 'payee_name' => $vendor->display_name]);

    $earlier = $this->locks->acquire($vendor, $this->jane);
    $this->locks->release($earlier->token, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSet('editLockBlocked', false)
        ->set('payee_line1', '500 New Avenue')
        ->call('saveDraft')
        ->call('confirmAddressWriteBack', true)
        ->assertHasNoErrors();

    expect($vendor->fresh()->billing_line1)->toBe('500 New Avenue')
        ->and($cheque->fresh()->payee_line1)->toBe('500 New Avenue')
        ->and($this->locks->currentVersion($vendor))->not->toBe($earlier->version);
});

it('refuses posting a draft deposit from its page while another member is editing it', function () {
    $deposit = app(SaveDeposit::class)->handle([
        'bank_account_id' => $this->bank->id,
        'deposit_date' => '2026-06-01',
        'lines' => [['account_id' => $this->income->id, 'amount_cents' => 50000]],
    ]);
    $this->locks->acquire($deposit, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::deposits.show', ['company' => $this->company, 'deposit' => $deposit])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'deposit']))
        ->call('post')
        ->assertDispatched('toast-show');

    expect($deposit->fresh()->status)->toBe(DepositStatus::Draft)
        ->and($deposit->fresh()->journal_entry_id)->toBeNull();
});

it('refuses voiding a cheque or transfer from its page while another member is editing it', function () {
    $cheque = ($this->makeCheque)();
    app(ChequePoster::class)->post($cheque);
    $transfer = ($this->makeTransfer)();
    app(TransferPoster::class)->post($transfer);

    $this->locks->acquire($cheque, $this->jane);
    $this->locks->acquire($transfer, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque->fresh()])
        ->call('void')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    Livewire::test('pages::transfers.show', ['company' => $this->company, 'transfer' => $transfer->fresh()])
        ->call('void')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Posted)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Posted);
});

it('guards exactly the banking show page methods that change the record', function (string $component, array $methods) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    $guarded = collect((new ReflectionClass($class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe($methods);
})->with([
    'cheques' => ['pages::cheques.show', ['confirmDelete', 'void']],
    'deposits' => ['pages::deposits.show', ['confirmDelete', 'post', 'void']],
    'transfers' => ['pages::transfers.show', ['confirmDelete', 'void']],
]);
