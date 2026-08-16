<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\EnsureUserIsNotDisabled;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\Concerns\RedirectsToCurrentCompany;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Http\Responses\TwoFactorLoginResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

class FortifyServiceProvider extends ServiceProvider
{
    use RedirectsToCurrentCompany;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);

        // Swap Fortify's login-pipeline 2FA gate for one that honours
        // "remember this device" — the default pipeline resolves the action by
        // class name through the container, so this binding is picked up.
        $this->app->bind(FortifyRedirectIfTwoFactorAuthenticatable::class, RedirectIfTwoFactorAuthenticatable::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureGuestRedirect();
    }

    /**
     * Tell the `guest` middleware where to send an already-authenticated user
     * who hits a guest-only page (login, register, password reset, …).
     *
     * The framework default resolves the named `dashboard` route, but that URI
     * is `{company}/dashboard`: generating it throws UrlGenerationException
     * whenever the user has no current company (e.g. mid-onboarding). Routing
     * through the same company-aware path the login/register responses use
     * lands them on their dashboard, or on the welcome wizard when they have
     * no company yet.
     */
    private function configureGuestRedirect(): void
    {
        RedirectIfAuthenticated::redirectUsing(function (Request $request): string {
            return $this->redirectPathForCurrentCompany($request, Fortify::redirects('login'));
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        $this->configureLoginPipeline();
    }

    /**
     * Fortify's default login pipeline, plus the disabled-account check.
     *
     * Restated in full because Fortify offers no "insert a step" hook. Classes
     * are listed by name so the container binding in register() still swaps in
     * the app's own RedirectIfTwoFactorAuthenticatable. Keep this list in sync
     * with AuthenticatedSessionController::loginPipeline() on Fortify upgrades.
     */
    private function configureLoginPipeline(): void
    {
        Fortify::authenticateThrough(fn (Request $request): array => array_filter([
            config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            Features::enabled(Features::twoFactorAuthentication()) ? FortifyRedirectIfTwoFactorAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            EnsureUserIsNotDisabled::class,
            PrepareAuthenticatedSession::class,
        ]));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => SiteSettings::registrationsEnabled() || ! User::query()->exists()
            ? view('pages::auth.register')
            : view('pages::auth.registration-closed'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Step-up 2FA confirmation on sensitive settings pages: the user is
        // already authenticated, so key by their id (the login throttle keys by
        // the pre-auth session, which is absent here).
        RateLimiter::for('two-factor-confirm', function (Request $request) {
            return Limit::perMinute(5)->by((string) optional($request->user())->getAuthIdentifier());
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
