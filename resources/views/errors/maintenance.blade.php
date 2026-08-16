<x-layouts::auth :title="__('Under maintenance')">
    <div class="flex flex-col gap-6 text-center">
        <x-auth-header
            :title="__('We\'ll be right back')"
            :description="__('LineLedger is temporarily down for maintenance. Please check back shortly.')"
        />

        <div class="text-sm text-muted-foreground">
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
