<?php

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Services\Audit\SecurityLogRecorder;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inbox email')] class extends Component
{
    public ?Company $company = null;

    public bool $inboundEnabled = false;

    public bool $ocrEnabled = false;

    public function mount(): void
    {
        $this->company = app()->bound('current_company')
            ? app('current_company')
            : Auth::user()?->currentCompany;

        if ($this->company === null) {
            return;
        }

        // Inbound email is an admin-only integration: enabling it mints an ingest
        // token and rotating it breaks the team's forwarding rule. This route is
        // not under the {company} RBAC middleware, so authorize here (and in every
        // mutator) — otherwise any member could read or rotate the token.
        Gate::authorize('update', $this->company);

        $this->inboundEnabled = (bool) $this->company->inbound_email_enabled;
        $this->ocrEnabled = $this->company->inboxOcrEnabled();
    }

    /**
     * The forwarding address documents can be emailed to, or null until inbound
     * email is enabled and a token has been minted.
     */
    public function forwardingAddress(): ?string
    {
        if ($this->company === null || $this->company->inbound_email_token === null) {
            return null;
        }

        $domain = (string) config('inbox.email.domain');

        if ($domain === '') {
            return null;
        }

        return 'inbox+'.$this->company->inbound_email_token.'@'.$domain;
    }

    /**
     * Toggle inbound email. Enabling mints a routing token if none exists.
     */
    public function saveInbound(SecurityLogRecorder $recorder): void
    {
        if ($this->company === null) {
            return;
        }

        Gate::authorize('update', $this->company);

        if ($this->inboundEnabled && $this->company->inbound_email_token === null) {
            $this->company->inbound_email_token = $this->freshToken();
        }

        $this->company->inbound_email_enabled = $this->inboundEnabled;
        $this->company->save();

        $recorder->record(SecurityEvent::InboundEmailSettingChanged, auth()->user(), metadata: [
            'enabled' => $this->inboundEnabled,
        ]);

        Flux::toast(variant: 'success', text: __('Inbound email settings saved.'));
    }

    /**
     * Rotate the routing token — the old forwarding address stops working
     * immediately. Useful if the address has leaked.
     */
    public function rotateToken(SecurityLogRecorder $recorder): void
    {
        if ($this->company === null) {
            return;
        }

        Gate::authorize('update', $this->company);

        $this->company->inbound_email_token = $this->freshToken();
        $this->company->save();

        $recorder->record(SecurityEvent::InboundEmailTokenRotated, auth()->user());

        Flux::toast(variant: 'success', text: __('A new forwarding address has been generated.'));
    }

    public function saveOcr(): void
    {
        if ($this->company === null) {
            return;
        }

        Gate::authorize('update', $this->company);

        $this->company->setInboxState(['ocr_enabled' => $this->ocrEnabled]);

        Flux::toast(variant: 'success', text: __('Receipt reading preference saved.'));
    }

    /**
     * Generate an opaque, collision-checked routing token.
     */
    private function freshToken(): string
    {
        do {
            $token = Str::lower(Str::random(40));
        } while (Company::query()->where('inbound_email_token', $token)->exists());

        return $token;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Inbox email settings') }}</flux:heading>

    <x-pages::settings.layout
        :heading="__('Inbox email')"
        :subheading="__('Forward receipts and bills straight into your inbox by email.')">

        @if ($company === null)
            <flux:callout variant="warning" icon="exclamation-triangle">
                {{ __('Select an organization first.') }}
            </flux:callout>
        @else
            <div class="space-y-10">
                <form wire:submit="saveInbound" class="space-y-4">
                    <flux:switch
                        wire:model.live="inboundEnabled"
                        :label="__('Accept documents by email')"
                        :description="__('When on, anything emailed to your forwarding address by a team member is added to the inbox.')"
                        data-test="inbound-enabled" />

                    @if ($this->forwardingAddress() !== null)
                        <flux:field>
                            <flux:label>{{ __('Forwarding address') }}</flux:label>
                            <flux:input readonly :value="$this->forwardingAddress()" data-test="forwarding-address" copyable />
                            <flux:description>
                                {{ __('Only emails from your active team members are accepted. Anything else is ignored.') }}
                            </flux:description>
                        </flux:field>

                        <div class="flex items-center gap-3">
                            <flux:button type="submit" variant="primary" data-test="save-inbound">{{ __('Save') }}</flux:button>
                            <flux:button type="button" variant="ghost" wire:click="rotateToken"
                                wire:confirm="{{ __('Generate a new address? The current one will stop working immediately.') }}"
                                data-test="rotate-token">
                                {{ __('Generate new address') }}
                            </flux:button>
                        </div>
                    @elseif ($inboundEnabled && (string) config('inbox.email.domain') === '')
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            {{ __('Inbound email is not configured on this server (no inbound domain set).') }}
                        </flux:callout>
                        <flux:button type="submit" variant="primary" data-test="save-inbound">{{ __('Save') }}</flux:button>
                    @else
                        <flux:button type="submit" variant="primary" data-test="save-inbound">{{ __('Save') }}</flux:button>
                    @endif
                </form>

                <flux:separator />

                <form wire:submit="saveOcr" class="space-y-4">
                    <flux:switch
                        wire:model="ocrEnabled"
                        :label="__('Read receipts automatically')"
                        :description="__('Use AI to extract the vendor, total and date from each document so a draft is pre-filled for review.')"
                        data-test="ocr-enabled" />
                    <flux:button type="submit" variant="primary" data-test="save-ocr">{{ __('Save') }}</flux:button>
                </form>
            </div>
        @endif
    </x-pages::settings.layout>
</section>
