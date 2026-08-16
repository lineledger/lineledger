<?php

use App\Actions\Portal\RequestEmployeePortalLoginLink;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.employee-portal')] #[Title('Sign in')] class extends Component
{
    public Company $company;

    public string $email = '';

    public string $password = '';

    public bool $sent = false;

    public function mount(Company $company): void
    {
        $this->company = $company;

        if (auth('customer')->check() && auth('customer')->user()?->is_employee) {
            $this->redirectRoute('employee-portal.dashboard', ['company' => $company->slug], navigate: false);
        }
    }

    /**
     * Email + password sign-in for employees who have set a portal password.
     * Enumeration-safe: every failure (unknown email, ineligible contact, no
     * password set, wrong password) yields the same generic error.
     */
    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Throttle by company + email + IP to slow credential stuffing.
        $key = 'employee-portal-password:'.$this->company->id.'|'.mb_strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::increment($key, decaySeconds: 900);

        $contact = Contact::query()
            ->where('company_id', $this->company->id)
            ->employeePortalEligible()
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($this->email))])
            ->first();

        if ($contact === null
            || $contact->portal_password === null
            || ! Hash::check($this->password, $contact->portal_password)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($key);

        Auth::guard('customer')->login($contact);
        session()->regenerate();

        $this->redirectRoute('employee-portal.dashboard', ['company' => $this->company->slug], navigate: false);
    }

    /** The magic-link flow — also serves as the forgot-password path. */
    public function submit(RequestEmployeePortalLoginLink $action): void
    {
        $this->validate(['email' => ['required', 'email']]);

        // Throttle by company + email + IP to prevent magic-link email bombing.
        $key = 'employee-portal-login:'.$this->company->id.'|'.mb_strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::increment($key, decaySeconds: 900);

        $action->handle($this->company, $this->email);

        // Enumeration-safe: always report success whether or not an employee matched.
        $this->sent = true;
    }
}; ?>

<div class="mx-auto flex max-w-md flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('View your pay statements & tax slips') }}</flux:heading>
        <flux:subheading>{{ __('Sign in with your work email and password, or have a secure sign-in link emailed to you.') }}</flux:subheading>
    </div>

    @if ($sent)
        <flux:callout variant="success" icon="envelope" heading="{{ __('Check your email') }}">
            {{ __('If an account exists for that address, we just sent a sign-in link. It expires in 15 minutes.') }}
        </flux:callout>

        <flux:button variant="ghost" size="sm" wire:click="$set('sent', false)" class="self-start">
            {{ __('Use a different email') }}
        </flux:button>
    @else
        <form wire:submit="login" class="flex flex-col gap-6">
            <flux:input
                wire:model="email"
                type="email"
                :label="__('Email address')"
                placeholder="email@example.com"
                required
                autofocus
                autocomplete="email"
                data-test="employee-portal-login-email"
            />

            <flux:input
                wire:model="password"
                type="password"
                :label="__('Password')"
                required
                autocomplete="current-password"
                viewable
                data-test="employee-portal-login-password"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="employee-portal-password-submit">
                {{ __('Sign in') }}
            </flux:button>
        </form>

        <flux:separator :text="__('or')" />

        <div class="flex flex-col gap-3">
            <flux:text size="sm">
                {{ __('Forgot your password, or haven\'t set one yet? We can email you a secure one-time sign-in link instead. Once signed in, you can set a password under "Edit my info".') }}
            </flux:text>

            <flux:button variant="outline" wire:click="submit" class="w-full" data-test="employee-portal-login-submit">
                {{ __('Email me a sign-in link') }}
            </flux:button>
        </div>
    @endif
</div>
