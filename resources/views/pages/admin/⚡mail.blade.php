<?php

use App\Support\MailSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Site Admin — Email')] class extends Component {
    public string $host = '';

    public string $port = '587';

    public string $username = '';

    /** Blank keeps the saved password, so the form never shows it. */
    public string $password = '';

    public string $fromAddress = '';

    public string $fromName = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        $saved = MailSettings::saved();

        $this->host = $saved['host'] ?? '';
        $this->port = (string) ($saved['port'] ?? 587);
        $this->username = $saved['username'] ?? '';
        $this->fromAddress = $saved['from_address'] ?? $this->environmentFromAddress();
        $this->fromName = $saved['from_name'] ?? (string) config('mail.from.name', config('app.name'));
    }

    public function save(): void
    {
        $this->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'fromAddress' => ['required', 'email', 'max:255'],
            'fromName' => ['required', 'string', 'max:255'],
        ]);

        MailSettings::save([
            'host' => trim($this->host),
            'port' => (int) $this->port,
            'username' => $this->username !== '' ? $this->username : null,
            'password' => $this->password !== '' ? $this->password : null,
            'from_address' => $this->fromAddress,
            'from_name' => $this->fromName,
        ]);

        $this->password = '';
        $this->restartWorkers();

        Flux::toast(variant: 'success', text: __('Saved. Send a test email to check it works.'));
    }

    public function sendTest(): void
    {
        $email = (string) auth()->user()?->email;

        if (! MailSettings::configured()) {
            Flux::toast(variant: 'warning', text: __('Email isn\'t set up yet, so a test message would only be written to the log. Save your SMTP settings first.'));

            return;
        }

        try {
            Mail::raw(
                __('This is a test message from LineLedger. If you can read it, outgoing email works.'),
                fn ($message) => $message->to($email)->subject(__('LineLedger test email')),
            );
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', text: __('The test email could not be sent: :error', ['error' => Str::limit($e->getMessage(), 300)]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Test email sent to :email.', ['email' => $email]));
    }

    public function forget(): void
    {
        MailSettings::forget();
        $this->restartWorkers();

        // A fresh request, so the page reflects the environment's settings again.
        $this->redirectRoute('admin.mail', navigate: true);
    }

    /** 'saved' (settings saved here), 'environment' (MAIL_* env vars) or 'unconfigured'. */
    public function status(): string
    {
        return match (true) {
            MailSettings::saved() !== null => 'saved',
            MailSettings::configured() => 'environment',
            default => 'unconfigured',
        };
    }

    public function savedHost(): string
    {
        return MailSettings::saved()['host'] ?? '';
    }

    public function hasSavedPassword(): bool
    {
        return (MailSettings::saved()['password'] ?? null) !== null;
    }

    public function environmentSummary(): string
    {
        return MailSettings::environmentSummary();
    }

    private function restartWorkers(): void
    {
        // Running queue workers keep the mailer they started with.
        Artisan::call('queue:restart');
    }

    private function environmentFromAddress(): string
    {
        $address = (string) config('mail.from.address');

        return $address === 'hello@example.com' ? (string) auth()->user()?->email : $address;
    }
}; ?>

<x-pages::admin.layout
    :heading="__('Email')"
    :subheading="__('The mail server LineLedger sends invitations, password resets, reminders and alerts through.')"
>
    <div class="space-y-6">
        @if ($this->status() === 'saved')
            <flux:callout variant="success" icon="check-circle" data-test="mail-status-saved">
                <flux:callout.text>{{ __('Email is sent through :host, using the settings saved here.', ['host' => $this->savedHost()]) }}</flux:callout.text>
            </flux:callout>
        @elseif ($this->status() === 'environment')
            <flux:callout icon="information-circle" data-test="mail-status-environment">
                <flux:callout.text>{{ __('Email is sent using the server\'s settings (:mailer). Saving settings here overrides them.', ['mailer' => $this->environmentSummary()]) }}</flux:callout.text>
            </flux:callout>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="mail-status-unconfigured">
                <flux:callout.heading>{{ __('Email isn\'t set up yet') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Nothing is delivered: messages are only written to the log. Enter an SMTP server below. Any email provider works, such as Postmark, Mailgun, Amazon SES or your own email host.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                <flux:input wire:model="host" :label="__('SMTP server')" placeholder="smtp.example.com" data-test="mail-host" />
                <flux:input wire:model="port" :label="__('Port')" type="number" min="1" max="65535" data-test="mail-port" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="username" :label="__('Username')" autocomplete="off" data-test="mail-username" />
                <flux:input
                    wire:model="password"
                    :label="__('Password')"
                    type="password"
                    viewable
                    autocomplete="new-password"
                    :placeholder="$this->hasSavedPassword() ? __('Leave blank to keep the saved password') : ''"
                    data-test="mail-password" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="fromAddress" :label="__('Send from address')" type="email" data-test="mail-from-address" />
                <flux:input wire:model="fromName" :label="__('Send from name')" data-test="mail-from-name" />
            </div>

            <flux:text class="text-sm text-muted-foreground">
                {{ __('Port 465 connects with TLS straight away; other ports upgrade to TLS when the server offers it. The send-from address must be one your provider allows.') }}
            </flux:text>

            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary" data-test="mail-save">{{ __('Save') }}</flux:button>
                <flux:button type="button" wire:click="sendTest" data-test="mail-send-test">{{ __('Send a test email to me') }}</flux:button>
                @if ($this->status() === 'saved')
                    <flux:button type="button" variant="ghost" wire:click="forget"
                        wire:confirm="{{ __('Remove the settings saved here? Email goes back to the server\'s settings, if it has any.') }}"
                        data-test="mail-forget">
                        {{ __('Remove saved settings') }}
                    </flux:button>
                @endif
            </div>
        </form>
    </div>
</x-pages::admin.layout>
