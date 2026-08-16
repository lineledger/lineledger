<?php

namespace App\Http\Middleware;

use App\Models\CompanyApiKey;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $this->extractToken($request);

        if ($plaintext === null) {
            throw new AuthenticationException('Missing API key');
        }

        $key = CompanyApiKey::query()
            ->withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $plaintext))
            ->whereNull('revoked_at')
            // Expired keys are rejected with the same generic message as any
            // other invalid key — no oracle distinguishing expired from unknown.
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('company')
            ->first();

        if (! $key || ! $key->company) {
            throw new AuthenticationException('Invalid API key');
        }

        app()->instance('current_company', $key->company);
        app()->instance('current_api_key', $key);

        $key->touchUsage();

        return $next($request);
    }

    protected function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return $bearer;
        }

        $header = $request->header('X-Api-Key');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return null;
    }
}
