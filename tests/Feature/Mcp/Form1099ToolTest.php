<?php

declare(strict_types=1);

use App\Enums\AccountSubtype;
use App\Mcp\Tools\Form1099Tool;
use App\Models\Account;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use Laravel\Mcp\Request;

it('Form1099: lists flagged vendors with yearly payment totals and the threshold', function () {
    $company = Company::factory()->create(['address_country' => 'US']);

    $vendor = Contact::factory()->create([
        'company_id' => $company->id,
        'display_name' => 'Contractor Co',
        'is_vendor' => true,
        'track_1099' => true,
        'tax_number' => '12-3456789',
    ]);

    $bank = Account::query()
        ->where('company_id', $company->id)
        ->where('subtype', AccountSubtype::Bank->value)
        ->orderBy('code')
        ->first();

    BillPayment::create([
        'company_id' => $company->id,
        'contact_id' => $vendor->id,
        'payment_no' => 'PAY-'.uniqid(),
        'payment_date' => '2025-03-01',
        'paid_from_account_id' => $bank->id,
        'amount_cents' => 70000,
        'status' => 'posted',
    ]);

    bindMcpTenant($company);

    $tool = app(Form1099Tool::class);

    $request = new Request(['year' => 2025]);
    $result = $tool->handle($request);

    expect($result->isError())->toBeFalse();
    expect((string) $result->content())->toContain('Form 1099-NEC');
    expect((string) $result->content())->toContain('Contractor Co');
    expect((string) $result->content())->toContain('700.00');
    expect((string) $result->content())->toContain('600.00');
});

it('Form1099: explains when the company is not US-based', function () {
    $company = Company::factory()->create(['address_country' => 'CA']);

    bindMcpTenant($company);

    $tool = app(Form1099Tool::class);

    $request = new Request(['year' => 2025]);
    $result = $tool->handle($request);

    expect($result->isError())->toBeFalse();
    expect((string) $result->content())->toContain('US-based');
});

it('Form1099: explains when no vendors are flagged for tracking', function () {
    $company = Company::factory()->create(['address_country' => 'US']);

    bindMcpTenant($company);

    $tool = app(Form1099Tool::class);

    $request = new Request(['year' => 2025]);
    $result = $tool->handle($request);

    expect($result->isError())->toBeFalse();
    expect((string) $result->content())->toContain('no Form 1099 totals');
});

it('Form1099: denies access without purchases ability', function () {
    $company = Company::factory()->create(['address_country' => 'US']);

    bindMcpTenant($company, ['receivables:read']);

    $tool = app(Form1099Tool::class);

    $request = new Request(['year' => 2025]);
    $result = $tool->handle($request);

    expect($result->isError())->toBeTrue();
});
