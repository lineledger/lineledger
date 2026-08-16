<?php

use App\Actions\Portal\RequestPortalLoginLink;
use App\Models\Company;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('Sign in')] class extends Component
{
    public Company $company;

    public string $email = '';

    public bool $sent = false;

    public function mount(Company $company): void
    {
        $this->company = $company;

        if (auth('customer')->check()) {
            $this->redirectRoute('portal.dashboard', ['company' => $company->slug], navigate: false);
        }
    }

    public function submit(RequestPortalLoginLink $action): void
    {
        $this->validate(['email' => ['required', 'email']]);

        // Throttle by company + email + IP to prevent magic-link email bombing.
        $key = 'portal-login:'.$this->company->id.'|'.mb_strtolower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::increment($key, decaySeconds: 900);

        $action->handle($this->company, $this->email);

        // Enumeration-safe: always report success whether or not a customer matched.
        $this->sent = true;
    }
}; ?>

<div class="mx-auto flex max-w-md flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('View & pay your invoices') }}</flux:heading>
        <flux:subheading>{{ __('Enter your email and we will send you a secure sign-in link.') }}</flux:subheading>
    </div>

    @if ($sent)
        <flux:callout variant="success" icon="envelope" heading="{{ __('Check your email') }}">
            {{ __('If an account exists for that address, we just sent a sign-in link. It expires in 15 minutes.') }}
        </flux:callout>

        <flux:button variant="ghost" size="sm" wire:click="$set('sent', false)" class="self-start">
            {{ __('Use a different email') }}
        </flux:button>
    @else
        <form wire:submit="submit" class="flex flex-col gap-6">
            <flux:input
                wire:model="email"
                type="email"
                :label="__('Email address')"
                placeholder="email@example.com"
                required
                autofocus
                autocomplete="email"
                data-test="portal-login-email"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="portal-login-submit">
                {{ __('Send sign-in link') }}
            </flux:button>
        </form>
    @endif
</div>
