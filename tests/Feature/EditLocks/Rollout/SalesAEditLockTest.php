<?php

use App\Actions\Membership\SaveMember;
use App\Actions\Sales\SaveEstimate;
use App\Actions\Sales\SaveSalesOrder;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\EditLockState;
use App\Enums\EstimateStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\SalesOrderStatus;
use App\Exceptions\EditLocks\EditLockLostException;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EditLock;
use App\Models\MembershipLevel;
use App\Models\RecurringDocument;
use App\Services\EditLocks\EditLockManager;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['features_membership' => true]);
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    app()->instance('current_company', $this->company);

    $this->locks = app(EditLockManager::class);
    $this->customer = Contact::query()->create(['display_name' => 'Edit Lock Sales Customer', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->lines = [[
        'item_id' => null,
        'account_id' => $this->income->id,
        'description' => 'Consulting',
        'quantity' => '2',
        'unit_price_cents' => 5000,
        'tax_code_id' => null,
    ]];
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

dataset('sales a edit forms', [
    'member' => ['pages::members.form', 'member', 'notes', fn () => app(SaveMember::class)->handle([
        'contact_id' => $this->customer->id,
        'started_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
        'notes' => 'Original',
    ])],
    'estimate' => ['pages::estimates.form', 'estimate', 'memo', fn () => app(SaveEstimate::class)->handle([
        'contact_id' => $this->customer->id,
        'estimate_no' => null,
        'estimate_date' => '2026-06-01',
        'memo' => 'Original',
        'lines' => $this->lines,
    ])],
    'sales order' => ['pages::sales-orders.form', 'salesOrder', 'memo', fn () => app(SaveSalesOrder::class)->handle([
        'contact_id' => $this->customer->id,
        'order_no' => null,
        'order_date' => '2026-06-01',
        'memo' => 'Original',
        'lines' => $this->lines,
    ])],
]);

it('takes the lock when the record is opened for editing', function (string $component, string $param, string $field, Model $record) {
    $this->actingAs($this->jane);

    $form = Livewire::test($component, ['company' => $this->company, $param => $record])
        ->assertSet('editLockBlocked', false);

    $lock = EditLock::query()->sole();

    expect($form->get('editLockToken'))->toHaveLength(40)
        ->and($lock->user_id)->toBe($this->jane->id)
        ->and($lock->lockable_type)->toBe($record::class)
        ->and($lock->lockable_id)->toBe($record->id);
})->with('sales a edit forms');

it('shows a second member who is editing instead of the form, and refuses a crafted save', function (string $component, string $param, string $field, Model $record) {
    $this->actingAs($this->jane);
    Livewire::test($component, ['company' => $this->company, $param => $record]);

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $record->fresh()])
        ->assertSet('editLockBlocked', true)
        ->assertSee($this->jane->name)
        ->set($field, 'Bob was here')
        ->call('save')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect($record->fresh()->{$field})->toBe('Original');
})->with('sales a edit forms');

it('lets the holder save through the form', function (string $component, string $param, string $field, Model $record) {
    $this->actingAs($this->jane);

    Livewire::test($component, ['company' => $this->company, $param => $record])
        ->set($field, 'Updated by Jane')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($record->fresh()->{$field})->toBe('Updated by Jane')
        ->and(EditLock::query()->sole()->changed_at_ms)->not->toBeNull();
})->with('sales a edit forms');

it('invalidates an open editor on the member\'s dues schedule when the member form syncs it', function () {
    $level = MembershipLevel::factory()->create([
        'default_dues_cents' => 10000,
        'billing_frequency' => RecurrenceFrequency::Annual->value,
        'revenue_account_id' => Account::query()->where('type', AccountType::Income->value)->orderBy('code')->firstOrFail()->id,
    ]);

    $member = app(SaveMember::class)->handle([
        'contact_id' => $this->customer->id,
        'membership_level_id' => $level->id,
        'started_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
        'auto_renew' => true,
    ]);
    $schedule = RecurringDocument::query()->findOrFail($member->recurring_document_id);

    // Bob has the recurring transaction open for editing.
    $bobs = $this->locks->acquire($schedule, $this->bob);

    $this->actingAs($this->jane);
    Livewire::test('pages::members.form', ['company' => $this->company, 'member' => $member])
        ->set('dues', '75.00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($schedule->fresh()->lines->first()->unit_price_cents)->toBe(7500)
        ->and($this->locks->currentVersion($schedule))->not->toBe($bobs->version);

    try {
        $this->locks->verify($schedule, $bobs->token, $bobs->version, $this->bob);
        $this->fail('Bob\'s stale recurring-transaction editor should not verify.');
    } catch (EditLockLostException $e) {
        expect($e->state)->toBe(EditLockState::Changed);
    }
});

it('refuses a show page action while another member is editing', function (string $component, string $param, Closure $build, string $method, Closure $unchanged) {
    $record = $build->call($this);
    $this->locks->acquire($record, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test($component, ['company' => $this->company, $param => $record->fresh()])
        ->assertSeeHtml('data-test="edit-lock-banner"')
        ->assertSee($this->jane->name)
        ->call($method)
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect($unchanged($record->fresh()))->toBeTrue();
})->with([
    'member renew' => ['pages::members.show', 'member', fn () => app(SaveMember::class)->handle([
        'contact_id' => $this->customer->id,
        'started_on' => '2026-01-01',
        'expires_on' => '2026-12-31',
    ]), 'renew', fn ($member) => $member->expires_on->toDateString() === '2026-12-31'],
    'estimate accept' => ['pages::estimates.show', 'estimate', fn () => app(SaveEstimate::class)->handle([
        'contact_id' => $this->customer->id,
        'estimate_no' => null,
        'estimate_date' => '2026-06-01',
        'lines' => $this->lines,
    ]), 'accept', fn ($estimate) => $estimate->status === EstimateStatus::Pending],
    'estimate convert' => ['pages::estimates.show', 'estimate', fn () => app(SaveEstimate::class)->handle([
        'contact_id' => $this->customer->id,
        'estimate_no' => null,
        'estimate_date' => '2026-06-01',
        'lines' => $this->lines,
    ]), 'convert', fn ($estimate) => $estimate->status === EstimateStatus::Pending && $estimate->converted_invoice_id === null],
    'sales order cancel' => ['pages::sales-orders.show', 'salesOrder', fn () => app(SaveSalesOrder::class)->handle([
        'contact_id' => $this->customer->id,
        'order_no' => null,
        'order_date' => '2026-06-01',
        'lines' => $this->lines,
    ]), 'cancelOrder', fn ($order) => $order->status === SalesOrderStatus::Open],
]);

it('accepts an estimate from the show page when nobody is editing, replacing the version', function () {
    $estimate = app(SaveEstimate::class)->handle([
        'contact_id' => $this->customer->id,
        'estimate_no' => null,
        'estimate_date' => '2026-06-01',
        'lines' => $this->lines,
    ]);
    $janes = $this->locks->acquire($estimate, $this->jane);
    $this->locks->release($janes->token, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::estimates.show', ['company' => $this->company, 'estimate' => $estimate->fresh()])
        ->call('accept');

    expect($estimate->fresh()->status)->toBe(EstimateStatus::Accepted)
        ->and($this->locks->currentVersion($estimate))->not->toBe($janes->version);
});

it('guards exactly the show page methods that change the record', function (string $component, array $expected) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    $guarded = collect((new ReflectionClass($class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe($expected);
})->with([
    'member' => ['pages::members.show', ['renew']],
    'estimate' => ['pages::estimates.show', ['accept', 'confirmDelete', 'convert', 'convertToSalesOrder', 'reject']],
    'sales order' => ['pages::sales-orders.show', ['cancelOrder', 'confirmDelete', 'fulfill']],
]);
