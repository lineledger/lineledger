<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Services\Printing\ChequePdfRenderer;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function offsetCheque(int $bankId, int $expenseId, string $no): Cheque
{
    $cheque = Cheque::create([
        'bank_account_id' => $bankId,
        'cheque_no' => $no,
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Acme',
    ]);

    $cheque->lines()->create([
        'account_id' => $expenseId,
        'description' => 'X',
        'amount_cents' => 5000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    return $cheque->fresh('lines');
}

it('persists per-company cheque print offsets', function () {
    $this->company->update(['cheque_offset_x' => 2.5, 'cheque_offset_y' => -3]);

    $fresh = $this->company->fresh();

    expect((float) $fresh->cheque_offset_x)->toBe(2.5)
        ->and((float) $fresh->cheque_offset_y)->toBe(-3.0);
});

it('renders a cheque PDF honouring the per-company print offset', function () {
    $this->company->update(['cheque_offset_x' => 12, 'cheque_offset_y' => 8]);

    $pdf = app(ChequePdfRenderer::class)->render(offsetCheque($this->bank->id, $this->expense->id, '1001'));

    expect($pdf)->toBeString()
        ->and(str_starts_with($pdf, '%PDF'))->toBeTrue();
});

it('renders a cheque PDF with no company offset, falling back to config', function () {
    $pdf = app(ChequePdfRenderer::class)->render(offsetCheque($this->bank->id, $this->expense->id, '1002'));

    expect(str_starts_with($pdf, '%PDF'))->toBeTrue();
});
