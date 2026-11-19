<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Mcp\Tools\UpcomingDeadlinesTool;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Deadline;
use App\Models\User;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use Laravel\Mcp\Request;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('lists overdue and upcoming deadlines with their assignee', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);
    $today = $company->currentDateTime();

    $deadline = Deadline::factory()->once($today->addDays(5)->toDateString())->create(['title' => 'BC PST return', 'track_from' => '2020-01-01']);
    app(DeadlineOccurrenceSync::class)->sync($deadline);
    $late = Deadline::factory()->once($today->subDays(3)->toDateString())->create(['title' => 'WCB report', 'track_from' => '2020-01-01']);
    app(DeadlineOccurrenceSync::class)->sync($late);

    $content = (string) (new UpcomingDeadlinesTool)->handle(new Request(['days_ahead' => 14]))->content();

    expect($content)->toContain('BC PST return')
        ->toContain('WCB report')
        ->toContain('OVERDUE')
        ->toContain('unassigned');
});

it('says so when nothing is due', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company);

    expect((string) (new UpcomingDeadlinesTool)->handle(new Request([]))->content())->toContain('no deadlines');
});

it('needs the tax:read ability and the Deadlines permission', function () {
    $company = Company::factory()->create();
    bindMcpTenant($company, ['sales:read']);

    expect((new UpcomingDeadlinesTool)->handle(new Request([]))->isError())->toBeTrue();

    $creator = User::factory()->create();
    $company->memberships()->create(['user_id' => $creator->id, 'role' => CompanyRole::Custom, 'sections' => [Section::Banking->value]]);
    ['key' => $key] = CompanyApiKey::mint($company->refresh(), 'Banking key', $creator->id);
    app()->instance('current_api_key', $key);

    expect((new UpcomingDeadlinesTool)->handle(new Request([]))->isError())->toBeTrue();
});
