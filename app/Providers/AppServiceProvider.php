<?php

namespace App\Providers;

use App\Listeners\Security\NotifyNewDeviceLogin;
use App\Listeners\Security\SecurityLogListener;
use App\Livewire\Hooks\BindCurrentCompanyHook;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Observers\Audit\AuditableObserver;
use App\Observers\CompanyObserver;
use App\Services\Currency\ExchangeRateProvider;
use App\Services\Currency\Providers\FrankfurterProvider;
use App\Services\Currency\Providers\NullExchangeRateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Passport;
use Livewire\ComponentHookRegistry;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Must register the Livewire component hook before Livewire's service
        // provider boots — which calls ComponentHookRegistry::boot() to wire
        // event listeners for the hooks registered up to that point.
        // Registering it in our boot() would be too late: Livewire's boot()
        // typically runs first (auto-discovered packages boot before app
        // providers), so the registry would already be wired without our hook.
        ComponentHookRegistry::register(BindCurrentCompanyHook::class);

        $this->app->bind(ExchangeRateProvider::class, function (): ExchangeRateProvider {
            return match (config('services.exchange_rates.driver')) {
                'null' => new NullExchangeRateProvider,
                default => new FrankfurterProvider(config('services.exchange_rates.base_url')),
            };
        });

        $this->app->singleton(StripeClient::class, function (): StripeClient {
            return new StripeClient((string) config('services.stripe.secret'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureRouteBindings();
        $this->configurePassport();

        Company::observe(CompanyObserver::class);

        foreach (array_keys(AuditableObserver::ACTIONS) as $modelClass) {
            $modelClass::observe(AuditableObserver::class);
        }

        Event::subscribe(SecurityLogListener::class);
        Event::listen(Login::class, NotifyNewDeviceLogin::class);

        // Platform-level (cross-tenant) authorization for the site admin portal.
        // Used for menu visibility and Blade @can; route access is additionally
        // enforced (with a 2FA nudge) by the EnsureSiteAdmin middleware.
        Gate::define('access-site-admin', fn (User $user): bool => $user->site_admin);
    }

    /**
     * Resolve the journal entry {entry} route parameter by either its numeric id
     * (how the app links internally) or its human-facing entry number (what users
     * see in reports/exports, e.g. "JE-007748"). The lookup stays tenant-scoped:
     * the CompanyScope adds `company_id = current` as a top-level AND, and the
     * id/entry_no alternatives are grouped so they cannot escape it.
     */
    protected function configureRouteBindings(): void
    {
        Route::bind('entry', function (string $value): JournalEntry {
            return JournalEntry::query()
                ->where(function ($query) use ($value): void {
                    $query->where('entry_no', $value);

                    if (is_numeric($value)) {
                        $query->orWhere('id', $value);
                    }
                })
                ->firstOrFail();
        });
    }

    /**
     * Configure Laravel Passport for the OAuth2 flow used by the Business Q&A
     * MCP server. The consent screen is the view shipped by laravel/mcp; token
     * lifetimes are bounded so a stale MCP connection eventually requires
     * re-authorization rather than living forever.
     */
    protected function configurePassport(): void
    {
        Passport::authorizationView('mcp.authorize');

        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // Registered here (not in routes/ai.php) because Mcp::oauthRoutes()
        // requires Passport's routes to already be registered. App providers boot
        // after package providers, so by now Passport's routes exist. This also
        // keeps the routes in a context captured by `route:cache`.
        Mcp::oauthRoutes();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Generate every URL (links, assets, redirects) over HTTPS in production
        // so a misconfigured proxy header can't downgrade a link to http. Paired
        // with the HSTS header from SecurityHeaders middleware. Keyed off the
        // APP_URL scheme so a self-hosted install served over plain HTTP (LAN,
        // behind an offloading proxy on localhost) isn't redirected to an HTTPS
        // endpoint that doesn't exist.
        if (app()->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Configure rate limiters for the JSON API.
     *
     * Throttles the /api/v1 group (applied before key authentication) to cap
     * brute-force of the API-key lookup per IP and abusive document creation
     * per key. The IP limit catches attackers rotating guessed keys; the
     * per-key limit catches a single compromised/abusive key.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $token = $request->bearerToken() ?: $request->header('X-Api-Key');

            return [
                Limit::perMinute(120)->by('api-ip:'.$request->ip()),
                Limit::perMinute(60)->by('api-key:'.($token ?: $request->ip())),
            ];
        });

        // CSP violation reports are unauthenticated; cap per IP so a bad policy
        // rollout (or a hostile flood) can't fill the log. 10/min surfaces a real
        // rollout within seconds while bounding abuse.
        RateLimiter::for('csp-report', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
    }
}
