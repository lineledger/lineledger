<?php

use App\Enums\DeadlineStatus;
use App\Models\Company;
use App\Models\Deadline;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 8]);
    app()->instance('current_company', $this->company);
    $this->owner = actAsCompanyOwner($this->company);
});

function makeDeadline(Company $company, array $attributes = []): Deadline
{
    $deadline = Deadline::factory()->create($attributes);
    app(DeadlineOccurrenceSync::class)->sync($deadline, $company->currentDateTime());

    return $deadline->refresh();
}

it('renders every view of the deadlines page', function (string $view) {
    makeDeadline($this->company, ['title' => 'BC PST return', 'assigned_user_id' => $this->owner->id]);

    $this->get(route('deadlines.index', ['company' => $this->company, 'view' => $view]))
        ->assertOk()
        ->assertSee(__('Deadlines'));
})->with(['calendar', 'list', 'year', 'all']);

it('shows the sidebar link', function () {
    $this->get(route('dashboard', ['company' => $this->company]))
        ->assertOk()
        ->assertSee('data-test="sidebar-deadlines"', false);
});

it('creates a deadline from the form and generates its occurrences', function () {
    $today = $this->company->currentDateTime();

    Livewire::test('pages::deadlines.form', ['company' => $this->company])
        ->set('title', 'GST/HST return')
        ->set('category', 'sales_tax')
        ->set('kind', 'file_and_pay')
        ->set('frequency', 'quarterly')
        ->set('period_anchor', $today->startOfMonth()->toDateString())
        ->set('due_months_after', 1)
        ->set('due_day_rule', 'last_day')
        ->set('assigned_user_id', $this->owner->id)
        ->assertSee($today->startOfMonth()->addMonthsNoOverflow(3)->subDay()->addDay()->endOfMonth()->translatedFormat('M j, Y'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $deadline = Deadline::query()->sole();

    expect($deadline->title)->toBe('GST/HST return')
        ->and($deadline->created_by_user_id)->toBe($this->owner->id)
        ->and($deadline->occurrences()->count())->toBeGreaterThanOrEqual(4)
        ->and($deadline->occurrences()->first()->assigned_user_id)->toBe($this->owner->id);
});

it('renders the show and edit pages', function () {
    $deadline = makeDeadline($this->company);

    $this->get(route('deadlines.show', ['company' => $this->company, 'deadline' => $deadline]))
        ->assertOk()
        ->assertSee($deadline->title);

    $this->get(route('deadlines.edit', ['company' => $this->company, 'deadline' => $deadline]))
        ->assertOk();
});

it('marks an occurrence done from the dialog', function () {
    $deadline = makeDeadline($this->company);
    $occurrence = $deadline->occurrences()->orderBy('due_date')->first();

    Livewire::test('pages::deadlines.occurrence-dialog', ['company' => $this->company])
        ->call('open', $occurrence->id, 'complete')
        ->assertSet('mode', 'complete')
        ->set('amount', '1,234.56')
        ->set('confirmation_number', 'ABC123')
        ->call('complete')
        ->assertHasNoErrors()
        ->assertDispatched('deadline-occurrence-changed');

    $occurrence->refresh();

    expect($occurrence->status)->toBe(DeadlineStatus::Done)
        ->and($occurrence->completed_by_user_id)->toBe($this->owner->id)
        ->and($occurrence->amount_cents)->toBe(123456)
        ->and($occurrence->confirmation_number)->toBe('ABC123')
        ->and($occurrence->completed_on->toDateString())->toBe($this->company->currentDateTime()->toDateString());
});
