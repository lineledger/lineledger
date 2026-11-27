<?php

use App\Enums\AuditAction;
use App\Enums\SecurityEvent;
use App\Models\Account;
use App\Models\Company;
use App\Models\SecurityLog;
use App\Services\Audit\AccountingAuditRecorder;
use Carbon\CarbonImmutable;

/**
 * The audit log's From/To dates are days in the organization's timezone, while
 * recorded_at is stored in UTC. These pin the clock to a moment where the two
 * calendars disagree (04:30 UTC on 27 November is still 26 November in
 * Toronto), which is where a window read as UTC days used to hide the newest
 * entries.
 */
beforeEach(function () {
    // Pin the clock before anything is created: setting up a company writes
    // audit rows of its own, and they must land on a day none of these tests
    // looks at, whatever the real time is when the suite runs.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));

    $this->company = Company::factory()->create(['timezone' => 'America/Toronto']);
    app()->instance('current_company', $this->company);
    $this->owner = actAsCompanyOwner($this->company);
    $this->account = Account::query()->firstOrFail();
});

function auditLogDateWindowRecord(Company $company, Account $account, string $marker): void
{
    app(AccountingAuditRecorder::class)->record($company->id, AuditAction::AccountUpdated, $account, ['marker' => $marker]);
}

it('shows an entry recorded after UTC midnight while it is still the same day locally', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27 04:30:00', 'UTC'));

    auditLogDateWindowRecord($this->company, $this->account, 'late-evening-entry');

    $this->get(route('reports.audit-log', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Thu, Nov 26, 2026 11:30 PM') // shown in the organization's time, not UTC
        ->assertSee('data-test="audit-row"', false);
});

it('filters on the organization\'s calendar days, not UTC days', function () {
    // 03:00 UTC on 27 Nov is 22:00 on 26 Nov in Toronto.
    $this->travelTo(CarbonImmutable::parse('2026-11-27 03:00:00', 'UTC'));
    auditLogDateWindowRecord($this->company, $this->account, 'on-the-26th');

    $url = fn (string $start, string $end): string => route('reports.audit-log', ['company' => $this->company->slug, 'start' => $start, 'end' => $end]);

    $this->get($url('2026-11-26', '2026-11-26'))->assertOk()->assertSee('data-test="audit-row"', false);
    $this->get($url('2026-11-27', '2026-11-27'))->assertOk()->assertDontSee('data-test="audit-row"', false);
});

it('applies the same local window to the security log', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27 04:30:00', 'UTC'));

    SecurityLog::query()->create([
        'recorded_at' => now()->format('Y-m-d H:i:s.u'),
        'company_id' => $this->company->id,
        'event' => SecurityEvent::LoginSucceeded->value,
    ]);

    $this->get(route('reports.audit-log', ['company' => $this->company->slug, 'view' => 'security']))
        ->assertOk()
        ->assertSee('data-test="security-row"', false)
        ->assertSee('Thu, Nov 26, 2026 11:30 PM');
});
