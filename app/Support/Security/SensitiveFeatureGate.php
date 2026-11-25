<?php

namespace App\Support\Security;

use App\Enums\SensitiveFeature;
use App\Exceptions\Security\TwoFactorRequiredException;
use App\Http\Middleware\EnforceTwoFactor;
use App\Models\Company;
use App\Models\User;

/**
 * Keeps the features an installation lists in
 * `security.two_factor_required_for` (TWO_FACTOR_REQUIRED_FOR) behind
 * two-factor authentication. A listed feature runs only when
 *  (a) the organization's Owner has confirmed two-factor authentication, and
 *  (b) whoever is acting, when someone is (an interactive request), has too.
 * Background work (scheduled and queued jobs) has no actor, so only (a)
 * applies. With nothing listed, the default, nothing changes.
 *
 * "Confirmed" is the test EnforceTwoFactor uses: Fortify's
 * hasEnabledTwoFactorAuthentication(), which needs two_factor_confirmed_at.
 *
 * Interactive paths call ensure() before they send or save anything, so the
 * person sees why; background paths ask allows() and skip, or fall back to the
 * non-AI path.
 */
final class SensitiveFeatureGate
{
    /**
     * Whether the installation keeps the feature behind two-factor
     * authentication at all.
     */
    public function requires(SensitiveFeature $feature): bool
    {
        return in_array($feature, self::listed(), true);
    }

    public function allows(SensitiveFeature $feature, Company $company, ?User $actor = null): bool
    {
        return $this->refusal($feature, $company, $actor) === null;
    }

    /**
     * @throws TwoFactorRequiredException when the feature may not run
     */
    public function ensure(SensitiveFeature $feature, Company $company, ?User $actor = null): void
    {
        $refusal = $this->refusal($feature, $company, $actor);

        if ($refusal !== null) {
            throw new TwoFactorRequiredException($feature, $refusal);
        }
    }

    /**
     * Why the feature may not run, as the message to show, or null when it may.
     * The actor's own account is named first: that is the part they can fix.
     */
    public function refusal(SensitiveFeature $feature, Company $company, ?User $actor = null): ?string
    {
        if (! $this->requires($feature)) {
            return null;
        }

        if ($actor !== null && ($refusal = $this->actorRefusal($feature, $actor)) !== null) {
            return $refusal;
        }

        if (! self::hasConfirmedTwoFactor($company->owner())) {
            return __(':feature needs the organization’s owner to turn on two-factor authentication under Settings → Security.', [
                'feature' => $feature->label(),
            ]);
        }

        return null;
    }

    /**
     * Why the actor may not use the feature, whatever the organization, or
     * null when they may: for a step that comes before an organization is
     * chosen (consenting to an MCP connection), where only (b) can be asked.
     */
    public function actorRefusal(SensitiveFeature $feature, User $actor): ?string
    {
        if (! $this->requires($feature) || self::hasConfirmedTwoFactor($actor)) {
            return null;
        }

        return __(':feature needs two-factor authentication on your account. Turn it on under Settings → Security, then try again.', [
            'feature' => $feature->label(),
        ]);
    }

    /**
     * Two-factor authentication set up and confirmed — the test
     * {@see EnforceTwoFactor} makes.
     */
    public static function hasConfirmedTwoFactor(?User $user): bool
    {
        return $user !== null && $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * The listed features. Unknown names are ignored, so a typo turns nothing
     * on rather than failing every request.
     *
     * @return list<SensitiveFeature>
     */
    private static function listed(): array
    {
        $configured = config('security.two_factor_required_for', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        $features = [];

        foreach ((array) $configured as $value) {
            $feature = $value instanceof SensitiveFeature ? $value : SensitiveFeature::tryFrom(trim((string) $value));

            if ($feature !== null) {
                $features[] = $feature;
            }
        }

        return $features;
    }
}
