<?php

use App\Actions\Recurring\SaveRecurringDocument;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\RecurringDocument;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function buildInvoiceSchedule(): RecurringDocument
{
    return app(SaveRecurringDocument::class)->handle([
        'document_type' => 'invoice',
        'contact_id' => test()->customer->id,
        'name' => 'Acme retainer',
        'frequency' => 'monthly',
        'start_date' => test()->company->currentDateTime()->toDateString(),
        'day_of_month' => 1,
        'end_type' => 'never',
        'lines' => [[
            'item_id' => null,
            'account_id' => test()->incomeAccount->id,
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'tax_code_id' => null,
        ]],
    ]);
}

it('renders the recurring index', function () {
    buildInvoiceSchedule();

    Livewire::test('pages::recurring.index', ['company' => $this->company])
        ->assertOk()
        ->assertSee('Acme retainer');
});

it('creates a recurring invoice schedule through the form', function () {
    Livewire::test('pages::recurring.form', ['company' => $this->company])
        ->set('document_type', 'invoice')
        ->set('name', 'New retainer')
        ->call('selectContact', $this->customer->id)
        ->set('frequency', 'monthly')
        ->set('lines.0.account_id', $this->incomeAccount->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '150.00')
        ->call('save')
        ->assertHasNoErrors();

    $schedule = RecurringDocument::query()->where('name', 'New retainer')->firstOrFail();
    expect($schedule->document_type->value)->toBe('invoice')
        ->and($schedule->next_run_date)->not->toBeNull()
        ->and($schedule->lines)->toHaveCount(1)
        ->and($schedule->lines->first()->unit_price_cents)->toBe(15000);
});

it('generates a draft immediately from the show page', function () {
    $schedule = buildInvoiceSchedule();

    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule])
        ->call('generateNow');

    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(1);
    expect($schedule->fresh()->occurrences_generated)->toBe(1);
});

it('pauses and resumes a schedule from the show page', function () {
    $schedule = buildInvoiceSchedule();

    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule])
        ->call('pauseSchedule');
    expect($schedule->fresh()->is_active)->toBeFalse();

    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule->fresh()])
        ->call('resumeSchedule');
    expect($schedule->fresh()->is_active)->toBeTrue();
});

it('shows a manually paused schedule as Paused, not Ended', function () {
    // Regression guard: Pause sets is_active=false but leaves paused_reason null
    // and next_run_date intact, so the schedule is still resumable — the badge
    // must read "Paused", not "Ended" (which requires it to have run its course).
    $schedule = buildInvoiceSchedule();

    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule])
        ->call('pauseSchedule');

    $schedule->refresh();
    expect($schedule->is_active)->toBeFalse()
        ->and($schedule->next_run_date)->not->toBeNull()
        ->and($schedule->hasEnded())->toBeFalse();

    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule])
        ->assertOk()
        ->assertSee('Paused')
        ->assertDontSee('Ended')
        ->assertSee('Resume');
});

it('shows a schedule that has run its course as Ended', function () {
    $schedule = buildInvoiceSchedule();
    $schedule->update(['is_active' => false, 'next_run_date' => null, 'paused_reason' => null]);

    Livewire::test('pages::recurring.show', ['company' => $this->company, 'recurring' => $schedule->fresh()])
        ->assertOk()
        ->assertSee('Ended')
        ->assertDontSee('Paused');
});
