<?php

namespace App\Http\Middleware;

use App\Enums\SensitiveFeature;
use App\Models\User;
use App\Support\Security\SensitiveFeatureGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a step that comes before any organization is chosen when the
 * installation keeps its feature behind two-factor authentication and the
 * signed-in user hasn't turned it on: consenting to an MCP (OAuth) connection.
 * The organization's half of the rule — its owner has two-factor too — is
 * checked when the connection is used for an organization (BindMcpCompany).
 *
 * Nobody signed in passes through, so the step can still send them to log in.
 * Usage: RequireTwoFactorForFeature::class.':api'.
 */
class RequireTwoFactorForFeature
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $refusal = app(SensitiveFeatureGate::class)->actorRefusal(SensitiveFeature::from($feature), $user);

            abort_if($refusal !== null, 403, (string) $refusal);
        }

        return $next($request);
    }
}
