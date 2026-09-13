<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Reporting\GeneratedAt;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    // 21:34 UTC is 14:34 the same afternoon in Vancouver — the stamp must read
    // the local afternoon, not the UTC evening.
    $this->travelTo(Carbon::parse('2026-09-10 21:34:00', 'UTC'));
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('stamps an exported PDF report in the company timezone', function () {
    $html = view('pdf.reports.list-table', [
        'company' => $this->company,
        'title' => 'Open Invoices',
        'period' => 'as of 2026-09-10',
        'headers' => [['label' => 'Invoice'], ['label' => 'Balance', 'num' => true]],
        'rows' => [[['value' => 'INV-1'], ['value' => '100.00', 'num' => true]]],
    ])->render();

    expect($html)->toContain('2026-09-10 14:34')
        ->and($html)->not->toContain('21:34');
});

it('stamps an exported XLSX report in the company timezone', function () {
    // XlsxExporter has no company on hand at the header row; it reads the
    // company bound for the render.
    expect(GeneratedAt::label())->toBe('2026-09-10 14:34');
});

it('prefers the company it is handed over the bound one', function () {
    $eastern = Company::factory()->create(['timezone' => 'America/Toronto']);

    expect(GeneratedAt::label($eastern))->toBe('2026-09-10 17:34');
});

it('falls back to the app timezone when no company is in play', function () {
    app()->forgetInstance('current_company');

    expect(GeneratedAt::label())->toBe(Carbon::now()->format('Y-m-d H:i'));
});

it('converts a stored instant to the bound company timezone for display', function () {
    // Stored as UTC, e.g. a completed_at timestamp — must read the local
    // afternoon, not the UTC evening, same as the "generated" stamp above.
    $storedUtc = Carbon::parse('2026-09-10 21:34:00', 'UTC');

    expect(GeneratedAt::at($storedUtc)->format('Y-m-d H:i'))->toBe('2026-09-10 14:34');
});

it('converts a stored instant using an explicitly handed company over the bound one', function () {
    $eastern = Company::factory()->create(['timezone' => 'America/Toronto']);
    $storedUtc = Carbon::parse('2026-09-10 21:34:00', 'UTC');

    expect(GeneratedAt::at($storedUtc, $eastern)->format('Y-m-d H:i'))->toBe('2026-09-10 17:34');
});

it('converts a stored instant to UTC when no company is in play', function () {
    app()->forgetInstance('current_company');
    $storedUtc = Carbon::parse('2026-09-10 21:34:00', 'UTC');

    expect(GeneratedAt::at($storedUtc)->format('Y-m-d H:i'))->toBe('2026-09-10 21:34');
});
