<?php

use App\Enums\CompanyRole;
use App\Enums\PredefinedRole;
use App\Http\Middleware\EnsureCompanyMembership;
use App\Http\Middleware\ResolvePortalCompany;
use App\Models\Company;
use App\Models\MembershipRole;
use App\Models\ReportGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Pages outside the {company} routes (Settings → Organizations, combined
 * reports, the site-admin portal, docs) bind no organization, so @reaches,
 *
 * @permits and @callable are always false there. 1.5.0-rc.1 wrapped the
 * organization list's Edit buttons and the report groups' Edit pencils in
 *
 * @reaches, and every one of them disappeared.
 */
afterEach(fn () => app()->forgetInstance('current_company'));

it('shows each organization its edit or view button on the organizations page', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $owned = Company::factory()->create(['name' => 'Owned Ltd.']);
    $owned->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    // Converted to the Accountant role, which can't change organization settings.
    $joined = Company::factory()->create(['name' => 'Joined Ltd.']);
    $membership = $joined->memberships()->create(['user_id' => $user->id, 'role' => CompanyRole::Accountant]);
    MembershipRole::query()->create([
        'company_id' => $joined->id,
        'company_member_id' => $membership->id,
        'role_id' => Role::query()->withoutGlobalScopes()->where('company_id', $joined->id)
            ->where('system_key', PredefinedRole::Accountant->value)->value('id'),
    ]);

    expect(app()->bound('current_company'))->toBeFalse();

    $this->actingAs($user)
        ->get(route('companies.index'))
        ->assertOk()
        ->assertSee(route('companies.edit', $owned->slug), false)
        ->assertSee(route('companies.edit', $joined->slug), false)
        ->assertSee('data-test="company-edit-button"', false)
        ->assertSee('data-test="company-view-button"', false);
});

it('shows the edit pencil only on report groups the viewer may edit', function () {
    $creator = User::factory()->create(['email_verified_at' => now()]);
    $colleague = User::factory()->create(['email_verified_at' => now()]);
    $a = Company::factory()->create();
    $b = Company::factory()->create();

    foreach ([$a, $b] as $company) {
        $company->members()->attach($creator, ['role' => CompanyRole::Owner->value]);
        $company->members()->attach($colleague, ['role' => CompanyRole::Admin->value]);
    }

    $group = ReportGroup::create(['user_id' => $creator->id, 'name' => 'Combined', 'currency_code' => 'CAD']);
    $group->companies()->attach([$a->id, $b->id]);

    expect(app()->bound('current_company'))->toBeFalse();

    $this->actingAs($creator)
        ->get(route('report-groups.index'))
        ->assertOk()
        ->assertSee(route('report-groups.edit', $group), false);

    // Shared with a co-member: they can run its reports but not edit it.
    $this->actingAs($colleague)
        ->get(route('report-groups.index'))
        ->assertOk()
        ->assertSee('Combined')
        ->assertDontSee(route('report-groups.edit', $group), false);
});

it('keeps permission conditionals off pages that bind no organization', function () {
    $router = app('router');
    $offenders = [];
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $component = $route->action['livewire_component'] ?? null;

        if (! is_string($component) || ! str_starts_with($component, 'pages::')) {
            continue;
        }

        $middleware = implode(',', $router->resolveMiddleware($route->gatherMiddleware()));

        if (str_contains($middleware, EnsureCompanyMembership::class) || str_contains($middleware, ResolvePortalCompany::class)) {
            continue;
        }

        $segments = explode('.', substr($component, strlen('pages::')));
        $page = array_pop($segments);
        $path = resource_path(implode('/', ['views', 'pages', ...$segments, '⚡'.$page.'.blade.php']));

        expect(is_file($path))->toBeTrue("No source file for {$component} at {$path}");
        $checked++;

        if (preg_match('/@(reaches|permits|callable)\b|CurrentViewer::/', (string) file_get_contents($path))) {
            $offenders[] = "{$component} ({$route->uri()})";
        }
    }

    expect($checked)->toBeGreaterThan(50)
        ->and(array_values(array_unique($offenders)))->toBe([]);
});
