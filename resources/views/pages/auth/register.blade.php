<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Legal acceptance -->
            @php($legal = app(\App\Support\Legal\LegalDocuments::class))
            <div>
                <flux:field variant="inline">
                    <flux:checkbox name="terms" value="1" :checked="old('terms')" data-test="register-terms" />
                    <flux:label class="!mb-0 text-sm">
                        {{-- Wrapped in a single <span>: flux:label is `inline-flex`, so without it
                             each <a> becomes its own flex item and the whitespace-only text nodes
                             between them are dropped, rendering "theTerms of ServiceandPrivacy". --}}
                        <span>
                            {!! __('I agree to the :terms and :privacy.', [
                                'terms' => '<a href="'.e($legal->url('terms')).'" target="_blank" rel="noopener" class="underline underline-offset-4 hover:text-foreground">'.e(__('Terms of Service')).'</a>',
                                'privacy' => '<a href="'.e($legal->url('privacy')).'" target="_blank" rel="noopener" class="underline underline-offset-4 hover:text-foreground">'.e(__('Privacy Policy')).'</a>',
                            ]) !!}
                        </span>
                    </flux:label>
                </flux:field>
                <flux:error name="terms" />
            </div>

            <x-turnstile action="register" />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-muted-foreground">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
