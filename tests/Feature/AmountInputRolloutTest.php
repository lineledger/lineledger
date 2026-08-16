<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Every dollar-amount field across these forms now renders <x-amount-input>
 * (the in-cell calculator) instead of a bare <flux:input>. This guards the
 * rollout: each page must still compile (a leftover literal "<flux:input" means
 * Blade's component compiler choked and the field would silently vanish) and the
 * calculator must be wired (x-data="amountCalculator" + the tape dropdown).
 */
it('renders the amount-input calculator on every converted form', function (string $route, array $dataTests) {
    $html = $this->get(route($route, ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    // Compilation succeeded — no uncompiled Flux tags leaked into the output.
    expect($html)->not->toContain('<flux:input');

    // The calculator + tape are present.
    expect($html)
        ->toContain('x-data="amountCalculator"')
        ->toContain('data-test="calc-tape"');

    // Each converted dollar field is present and bound.
    foreach ($dataTests as $dataTest) {
        expect($html)->toContain('data-test="'.$dataTest.'"');
    }
})->with([
    'journal' => ['journal.create', ['line-debit', 'line-credit']],
    'invoices' => ['invoices.create', ['line-unit-price']],
    'credit memos' => ['credit-memos.create', ['line-unit-price']],
    'purchase orders' => ['purchase-orders.create', ['line-unit-price']],
    'bills' => ['bills.create', ['line-unit-price', 'line-tax-override']],
    'vendor credits' => ['vendor-credits.create', ['line-unit-price']],
    'transfers' => ['transfers.create', ['transfer-amount-input']],
    'reconcile' => ['banking.reconcile', []],
]);

/**
 * The bill tax-override field is the only conversion that forwards a non-default
 * binding (.live.debounce.500ms) plus a tab handler through the attribute bag.
 * Confirm both survived the swap so debounced syncing and tab-to-add-row still work.
 */
it('preserves the debounce binding and tab handler on the bill tax-override field', function () {
    $html = $this->get(route('bills.create', ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('wire:model.live.debounce.500ms="lines.0.tax_override"')
        ->toContain('addRowAndFocus')
        ->toContain('wire:model.live="lines.0.unit_price"');
});

/**
 * The deposit "Other deposits" amount field only renders once a line exists
 * (the table is hidden until then), so it's driven through Livewire rather than
 * a plain GET.
 */
it('renders the amount-input calculator on the deposit other-line amount field', function () {
    $html = Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->call('addOtherLine')
        ->html();

    expect($html)->not->toContain('<flux:input');

    expect($html)
        ->toContain('data-test="other-amount"')
        ->toContain('x-data="amountCalculator"')
        ->toContain('data-test="calc-tape"');
});
