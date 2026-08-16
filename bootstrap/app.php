<?php

use App\Contracts\ClientSafeException;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\BindMcpCompany;
use App\Http\Middleware\CheckSiteMaintenance;
use App\Http\Middleware\EnsureApiAbility;
use App\Http\Middleware\EnsureCompanyMembership;
use App\Http\Middleware\EnsureLegalAcceptance;
use App\Http\Middleware\EnsurePortalAudience;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireTwoFactorConfirmation;
use App\Http\Middleware\ResolvePortalCompany;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCompanyUrlDefaults;
use App\Http\Middleware\VerifyTurnstile;
use App\Models\Company;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production runs behind a TLS-terminating load balancer / CDN, which
        // forwards the real scheme and client IP in X-Forwarded-* headers.
        // Without trusting the proxy, $request->isSecure() is false (so the HSTS
        // header in SecurityHeaders is never emitted) and $request->ip() is the
        // balancer's IP (so per-IP API rate limiting collapses to one bucket).
        // Trust all proxies because the app server is only reachable through the
        // platform's balancer; narrow `at:` to the balancer CIDR if the host is
        // ever exposed directly.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            SecurityHeaders::class,
            CheckSiteMaintenance::class,
            EnsureUserIsActive::class,
            EnsureLegalAcceptance::class,
            EnsureUserHasCompany::class,
            SetCompanyUrlDefaults::class,
            // Bot challenge on the public auth forms (register / login / reset
            // link). Inert unless Turnstile keys are configured; see
            // config/turnstile.php for which routes it covers.
            VerifyTurnstile::class,
        ]);

        $middleware->alias([
            'auth.api_key' => AuthenticateApiKey::class,
            'api.ability' => EnsureApiAbility::class,
            'portal.company' => ResolvePortalCompany::class,
            'portal.audience' => EnsurePortalAudience::class,
            'mcp.company' => BindMcpCompany::class,
            '2fa.confirm' => RequireTwoFactorConfirmation::class,
        ]);

        // API-key auth (and the ability gate) must run before route-model
        // binding, so the company is bound and the global CompanyScope applies
        // when {model} parameters are resolved — otherwise a key could resolve
        // another tenant's records by id.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            AuthenticateApiKey::class,
        );
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            EnsureApiAbility::class,
        );

        // Same reason on the web side: EnsureCompanyMembership binds
        // `current_company` from the {company} slug, so it must run before
        // route-model binding resolves {invoice}/{bill}/{estimate}/{cheque}/etc.
        // Otherwise SubstituteBindings resolves those ids with the global
        // CompanyScope inactive and a member of one company can load another
        // tenant's record by id (cross-tenant IDOR).
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            EnsureCompanyMembership::class,
        );

        $middleware->encryptCookies(except: [
            'sidebar_groups',
        ]);

        // Stripe verifies webhooks by signature, not a session CSRF token.
        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
            // Inbound-email provider webhook — verified by HMAC signature, not CSRF.
            'inbound-email/*',
            // CSP violation reports — browsers post them with no CSRF token.
            'csp-report',
        ]);

        // Unauthenticated visitors to either external portal go to that portal's
        // own magic-link login, not the staff login screen.
        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->is('pay/*') || $request->is('my-pay/*')) {
                $company = $request->route('company');
                $slug = $company instanceof Company ? $company->slug : $company;
                $loginRoute = $request->is('my-pay/*') ? 'employee-portal.login' : 'portal.login';

                return route($loginRoute, ['company' => $slug]);
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Default-deny error policy for the JSON API: an exception's raw message
        // may leak internal accounting detail (GL account names, company ids,
        // balances, SKUs, lock/period dates), so only messages explicitly marked
        // safe (ClientSafeException) or author-written (validation, HttpException)
        // reach a client. Everything else is logged and returned generically.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage() ?: 'Unauthenticated'], 401);
            }
        });

        // Laravel's framework Handler rewraps ModelNotFoundException into
        // NotFoundHttpException carrying "No query results for model [App\Models\X] {id}",
        // so render the wrapper — otherwise the rewrap leaks the FQCN to clients.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });

        // AlreadyPosted is the only client-safe posting exception that maps to 409.
        $exceptions->render(function (AlreadyPostedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->clientSafeMessage()], 409);
            }
        });

        // Every other ClientSafeException (unbalanced, locked period/reconciliation,
        // filed tax period, insufficient stock, …) maps to 422 with its safe message.
        $exceptions->render(function (ClientSafeException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->clientSafeMessage()], 422);
            }
        });

        // All other HttpExceptions on the JSON API: normalize to a bare {message}
        // envelope so the response shape is identical in debug and production
        // (Laravel's default renderer adds exception/file/line/trace when debug is on).
        // ValidationException stays special-cased below so its {message, errors} shape
        // is preserved.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof ValidationException) {
                return null;
            }

            return response()->json(
                ['message' => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.'],
                $e->getStatusCode(),
            );
        });

        // Catch-all for the JSON API. Validation errors carry author-written,
        // internals-free messages — fall through to Laravel's default JSON
        // rendering. Anything else is unexpected: log the detail and return a
        // generic message so no internal state leaks.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException || $e instanceof HttpExceptionInterface) {
                return null;
            }

            report($e);

            return response()->json(['message' => 'This request could not be completed.'], 500);
        });
    })->create();
