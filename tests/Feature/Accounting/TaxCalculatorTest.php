<?php

use App\Enums\TaxAppliesTo;
use App\Models\Company;
use App\Models\TaxCode;
use App\Services\Posting\TaxCalculator;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->calc = app(TaxCalculator::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('multiplies quantity by unit price correctly', function () {
    $result = $this->calc->line('3', 1500); // 3 * $15.00 = $45.00

    expect($result['subtotal_cents'])->toBe(4500);
    expect($result['tax_cents'])->toBe(0);
    expect($result['total_cents'])->toBe(4500);
});

it('supports fractional quantity', function () {
    $result = $this->calc->line('2.5', 1000); // 2.5 * $10.00 = $25.00

    expect($result['subtotal_cents'])->toBe(2500);
});

it('computes GST 5% correctly', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $result = $this->calc->line('1', 10000, $gst); // $100 + 5% tax

    expect($result['subtotal_cents'])->toBe(10000);
    expect($result['tax_cents'])->toBe(500);
    expect($result['total_cents'])->toBe(10500);
});

it('computes HST 13% correctly', function () {
    $hst = TaxCode::where('code', 'HST-ON')->firstOrFail();
    $result = $this->calc->line('1', 5000, $hst); // $50 + 13% tax

    expect($result['subtotal_cents'])->toBe(5000);
    expect($result['tax_cents'])->toBe(650);
    expect($result['total_cents'])->toBe(5650);
});

it('rounds tax half-up on odd cents', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    // 5% of $0.99 = $0.0495 → rounds to $0.05
    $result = $this->calc->line('1', 99, $gst);

    expect($result['tax_cents'])->toBe(5);
});

it('computes QST at the fractional 9.975% rate', function () {
    $qst = TaxCode::create([
        'code' => 'QST',
        'name' => 'QST (9.975%)',
        'rate_basis_points' => 997.5, // fractional basis points
        'applies_to' => TaxAppliesTo::Both,
        'is_recoverable' => true,
        'is_active' => true,
    ]);

    // 9.975% of $100.00 = $9.975 → rounds half-up to $9.98.
    $result = $this->calc->line('1', 10000, $qst);

    expect($result['tax_cents'])->toBe(998);
    expect($result['total_cents'])->toBe(10998);

    // The fractional rate round-trips through the column, not truncated to 997.
    expect($qst->fresh()->rate_basis_points)->toBe(997.5);
});

it('treats zero-rated as no tax', function () {
    $zr = TaxCode::where('code', 'ZR')->firstOrFail();
    $result = $this->calc->line('1', 10000, $zr);

    expect($result['tax_cents'])->toBe(0);
});

it('applies a percent discount before tax', function () {
    $hst = TaxCode::where('code', 'HST-ON')->firstOrFail();
    // 2 × $100 = $200 gross, less 10% = $180 net, + 13% tax = $203.40
    $result = $this->calc->line('2', 10000, $hst, 0, '10');

    expect($result['discount_cents'])->toBe(2000);
    expect($result['subtotal_cents'])->toBe(18000);
    expect($result['tax_cents'])->toBe(2340);
    expect($result['total_cents'])->toBe(20340);
});

it('applies a fixed-amount discount before tax', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    // $100 gross, less $25 = $75 net, + 5% tax = $78.75
    $result = $this->calc->line('1', 10000, $gst, 2500);

    expect($result['discount_cents'])->toBe(2500);
    expect($result['subtotal_cents'])->toBe(7500);
    expect($result['tax_cents'])->toBe(375);
    expect($result['total_cents'])->toBe(7875);
});

it('clamps a discount to the gross line amount', function () {
    // A discount larger than the line can never invert the subtotal.
    $result = $this->calc->line('1', 5000, null, 9999);

    expect($result['discount_cents'])->toBe(5000);
    expect($result['subtotal_cents'])->toBe(0);
    expect($result['total_cents'])->toBe(0);
});

it('lets a percent discount win over a fixed amount when both are given', function () {
    // 50% of $80 = $40 discount; the $5 fixed amount is ignored.
    $result = $this->calc->line('1', 8000, null, 500, '50');

    expect($result['discount_cents'])->toBe(4000);
    expect($result['subtotal_cents'])->toBe(4000);
});
