<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('serves a report as an inline PDF honoring the page query string', function () {
    $response = $this->actingAs($this->user)->get(route('reports.print', [
        'company' => $this->company->slug,
        'reportKey' => 'reports.income-statement',
        'range' => 'custom',
        'start' => '2025-03-01',
        'end' => '2025-03-31',
    ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('inline')
        ->toContain('income-statement-2025-03-01-2025-03-31.pdf');

    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('rejects report keys outside the renderable registry', function () {
    $this->actingAs($this->user)->get(route('reports.print', [
        'company' => $this->company->slug,
        'reportKey' => 'reports.nope',
    ]))->assertNotFound();
});

it('rejects reports that have no PDF export', function () {
    // The general ledger is registered as xlsx-only.
    $this->actingAs($this->user)->get(route('reports.print', [
        'company' => $this->company->slug,
        'reportKey' => 'reports.general-ledger',
    ]))->assertNotFound();
});

it('blocks non-members', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('reports.print', [
        'company' => $this->company->slug,
        'reportKey' => 'reports.income-statement',
    ]))->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('reports.print', [
        'company' => $this->company->slug,
        'reportKey' => 'reports.income-statement',
    ]))->assertRedirect();
});
