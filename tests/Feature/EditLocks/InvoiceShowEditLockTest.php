<?php

use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Company;
use App\Models\EditLock;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\InvoicePoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->owner = editLockMember($this->company, CompanyRole::Owner);
    $this->invoice = editLockDraftInvoice($this->company);
    app(InvoicePoster::class)->post($this->invoice);
    $this->locks = app(EditLockManager::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('shows who is editing and refuses a void while they are', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice->fresh()])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'invoice']))
        ->assertDontSee(__('Take over editing'))
        ->call('void')
        ->assertDispatched('toast-show');

    expect($this->invoice->fresh()->status)->not->toBe(InvoiceStatus::Void);
});

it('lets the member holding the lock void from the show page, and invalidates their open editor', function () {
    $held = $this->locks->acquire($this->invoice, $this->jane);

    $this->actingAs($this->jane);
    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice->fresh()])
        ->assertDontSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => 'invoice']))
        ->call('void');

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Void)
        ->and($this->locks->currentVersion($this->invoice))->not->toBe($held->version);
});

it('voids normally when nobody is editing, replacing the version', function () {
    $mine = $this->locks->acquire($this->invoice, $this->jane);
    $this->locks->release($mine->token, $this->jane);

    $this->actingAs($this->bob);
    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice->fresh()])
        ->assertDontSee(__('Take over editing'))
        ->call('void');

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Void)
        ->and($this->locks->currentVersion($this->invoice))->not->toBe($mine->version);
});

it('offers an owner the take-over, which hands them the lock and opens the editor', function () {
    $this->locks->acquire($this->invoice, $this->jane);

    $this->actingAs($this->owner);
    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice->fresh()])
        ->assertSee(__('Take over editing'))
        ->call('takeOverEditLock');

    expect(EditLock::query()->sole()->user_id)->toBe($this->owner->id);
});

it('guards exactly the invoice show page methods that change the invoice', function () {
    $component = app('livewire.factory')->resolveComponentClass('pages::invoices.show');

    $guarded = collect((new ReflectionClass($component))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe(['confirmDelete', 'reconcile', 'savePaymentSchedule', 'void']);
});
