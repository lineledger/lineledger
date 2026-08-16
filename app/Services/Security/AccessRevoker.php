<?php

namespace App\Services\Security;

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\EmployeePayrollProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/**
 * Invalidates a user's active access when they are deprovisioned from a company
 * — removed outright, or downgraded to a lower-privilege role (SOC 2 Common
 * Criteria CC6.2 deprovisioning / CC6.3 role change). Membership and role are
 * already re-checked on every request, so this is defence in depth: it tears
 * down credentials that were minted under the old access level instead of
 * waiting for them to expire.
 */
class AccessRevoker
{
    /**
     * Full deprovisioning: the user has been removed from the company. Drop
     * their sessions (forcing re-authentication, after which the company is
     * simply gone), revoke the company API keys they minted, and revoke their
     * OAuth (Passport) tokens so any active MCP connection must re-authorize.
     */
    public function revokeForRemoval(User $user, Company $company): void
    {
        $this->forgetSessions($user);
        $this->revokeCompanyApiKeys($user, $company);
        $this->revokePassportTokens($user);

        // A removed member must stop receiving employees' leave requests:
        // clear any designated-approver references (requests fall back to the
        // payroll-section members).
        EmployeePayrollProfile::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('approver_user_id', $user->id)
            ->update(['approver_user_id' => null]);
    }

    /**
     * Privilege reduction: the user keeps their membership at a lower role.
     * OAuth tokens are re-authorized against live membership and role on every
     * request, so they are left intact; but we drop their sessions so any
     * cached privilege is rebuilt on next login, and revoke the company API
     * keys they hold, whose abilities may now exceed their reduced role.
     */
    public function revokeForDowngrade(User $user, Company $company): void
    {
        $this->forgetSessions($user);
        $this->revokeCompanyApiKeys($user, $company);
    }

    /**
     * Platform-wide lockout: a site admin has disabled the account. Drop every
     * session, revoke their OAuth (Passport) tokens, and revoke every API key
     * they minted across *all* companies.
     *
     * The API keys matter most here: AuthenticateApiKey resolves a key to a
     * company, never to a user, so no per-request check can notice that the
     * minter was disabled — revoking is the only enforcement on routes/api.php.
     * Re-enabling the account does not bring keys or tokens back; the user signs
     * in again and re-mints them.
     */
    public function revokeForAccountDisabled(User $user): void
    {
        $this->forgetSessions($user);
        $this->revokePassportTokens($user);

        CompanyApiKey::withoutGlobalScopes()
            ->where('created_by_user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    protected function forgetSessions(User $user): void
    {
        $driver = config('session.driver');

        if ($driver === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        } else {
            // We can only bulk-purge a user's active sessions on the database
            // driver. Log (rather than silently skip) so the gap is visible if
            // the driver is ever switched — deprovisioning is a SOC 2 control
            // and this must not fail closed-then-quiet.
            Log::warning('AccessRevoker could not purge sessions on the current session driver', [
                'user_id' => $user->id,
                'session_driver' => $driver,
            ]);
        }

        // Cycle the remember-me token so a persisted "remember me" cookie can't
        // silently re-establish a session after the ones above are dropped.
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    protected function revokeCompanyApiKeys(User $user, Company $company): void
    {
        CompanyApiKey::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('created_by_user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    protected function revokePassportTokens(User $user): void
    {
        // Revoke by user_id directly rather than via $user->tokens(), whose
        // relation joins through the OAuth client/provider and would skip any
        // token we can't (or shouldn't need to) resolve a client for. On
        // deprovisioning we want every one of the user's access tokens gone.
        $tokenIds = Passport::tokenModel()::query()
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->pluck('id');

        $this->revokeTokensById($tokenIds);
    }

    /**
     * Revoke a single authorized application: every active access token the
     * user holds for one OAuth client, plus the refresh tokens paired with
     * them. Backs the self-service "Authorized applications" screen, where a
     * user cuts off one connected app (e.g. an MCP client) without touching
     * their others.
     */
    public function revokePassportTokensForClient(User $user, string $clientId): void
    {
        $tokenIds = Passport::tokenModel()::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->pluck('id');

        $this->revokeTokensById($tokenIds);
    }

    /**
     * @param  Collection<int, string>  $tokenIds
     */
    protected function revokeTokensById($tokenIds): void
    {
        if ($tokenIds->isEmpty()) {
            return;
        }

        Passport::tokenModel()::query()
            ->whereIn('id', $tokenIds)
            ->update(['revoked' => true]);

        // Also revoke the refresh tokens paired with those access tokens;
        // otherwise the client could exchange a still-valid refresh token for a
        // fresh access token. (The MCP route re-checks membership on every
        // request, so this is defence in depth.)
        Passport::refreshTokenModel()::query()
            ->whereIn('access_token_id', $tokenIds)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }
}
