<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kill switch for the in-app support desk. A deployment that handles support
 * elsewhere sets SUPPORT_ENABLED=false; this 404s the user-facing ticket pages
 * so the feature is gone rather than merely unlinked.
 *
 * The routes stay registered either way, so route('support.show') keeps
 * resolving for anything still holding a ticket — a reply notification queued
 * before the switch was thrown, say — and the admin portal keeps its own
 * ticket screens, which read historical tickets regardless.
 */
class EnsureSupportEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('support.enabled'), 404);

        return $next($request);
    }
}
