<x-layouts::auth :title="__('Registration closed')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Registration is closed')"
            :description="__('New sign-ups are not being accepted right now. Please check back later.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-muted-foreground">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
