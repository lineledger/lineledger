<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user. The user is created
     * without a company; the welcome onboarding flow (driven by the
     * EnsureUserHasCompany middleware) prompts them to pick a jurisdiction
     * and create their first company on next request.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // The first user to register bootstraps the platform and becomes the
        // site admin; thereafter registration can be closed by the site admin.
        // The check is skipped while no users exist so that very first account
        // can always be created.
        $isFirstUser = ! User::query()->exists();

        if (! $isFirstUser && ! SiteSettings::registrationsEnabled()) {
            throw ValidationException::withMessages([
                'email' => __('Registration is currently closed.'),
            ]);
        }

        if (! $isFirstUser) {
            $this->ensureNotThrottled();
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            // A single checkbox covers both required documents; both are
            // recorded below so the agreement is auditable per document.
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => __('You must accept the Terms of Service and Privacy Policy.'),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // The very first registered user bootstraps the platform site admin.
        // `site_admin` is not mass-assignable, so set it explicitly rather than
        // through the create() array (the column defaults to false otherwise).
        if ($isFirstUser) {
            $user->forceFill(['site_admin' => true])->save();
        }

        app(LegalDocuments::class)->record($user, ['terms', 'privacy'], request());

        return $user;
    }

    /**
     * Per-IP throttle on account creation.
     *
     * Fortify ships POST /register with no throttle and exposes no limiter hook
     * for it (see the `registration_throttle` note in config/fortify.php), so
     * the check lives here beside the registrations-closed gate. Skipped for the
     * very first user so a fresh install can always be bootstrapped.
     *
     * Counted on every attempt rather than only on success: the cost being
     * limited is the attempt itself — validation work and, on success, an
     * outbound verification email.
     */
    protected function ensureNotThrottled(): void
    {
        $maxAttempts = (int) config('fortify.registration_throttle.max_attempts', 5);
        $decayMinutes = (int) config('fortify.registration_throttle.decay_minutes', 10);

        if ($maxAttempts <= 0) {
            return;
        }

        $key = 'register|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'email' => __('Too many registration attempts. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, $decayMinutes * 60);
    }
}
