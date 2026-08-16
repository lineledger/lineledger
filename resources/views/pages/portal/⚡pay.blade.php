<?php

use App\Actions\Portal\FlagBrokenStripeConnection;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.portal')] #[Title('Pay')] class extends Component
{
    public Company $company;

    public Contact $customer;

    #[Locked]
    public ?string $clientSecret = null;

    #[Locked]
    public ?string $errorMessage = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->customer = auth('customer')->user();

        if ($this->totalDue <= 0) {
            $this->redirectRoute('portal.dashboard', ['company' => $company->slug], navigate: true);
        }
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function openInvoices(): Collection
    {
        return Invoice::query()
            ->where('contact_id', $this->customer->id)
            ->whereIn('status', [InvoiceStatus::Posted->value, InvoiceStatus::Partial->value])
            ->whereRaw('total_cents - amount_paid_cents - reconciled_cents > 0')
            ->orderBy('due_date')
            ->orderBy('invoice_date')
            ->get();
    }

    #[Computed]
    public function totalDue(): int
    {
        return $this->openInvoices->sum(fn (Invoice $invoice) => $invoice->balanceCents());
    }

    /**
     * Create a PaymentIntent on the company's connected account and hand the
     * client secret to Stripe.js. The amount is computed here from invoice
     * balances — never trusted from the browser.
     */
    public function preparePayment(StripePaymentService $stripe): void
    {
        if (! $this->company->canAcceptCardPayments() || $this->totalDue <= 0) {
            $this->errorMessage = __('Online payment is not available right now.');

            return;
        }

        try {
            $intent = $stripe->createPaymentIntent($this->company, $this->totalDue, [
                'contact_id' => (string) $this->customer->id,
                'invoice_ids' => $this->openInvoices->pluck('id')->implode(','),
            ]);

            $this->clientSecret = $intent->client_secret;
        } catch (\Throwable $e) {
            // A revoked/severed Connect link won't recover on retry. Flag it —
            // which pauses the portal and emails the owner to reconnect — instead
            // of telling each customer to "try again later". The re-render then
            // shows the "unavailable" notice (canAcceptCardPayments() is now false).
            if (StripePaymentService::isConnectionRevoked($e)) {
                app(FlagBrokenStripeConnection::class)->handle($this->company);

                return;
            }

            report($e);
            $this->errorMessage = __('We could not start the payment. Please try again later.');
        }
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Pay your balance') }}</flux:heading>
            <flux:subheading>{{ __('Paying :amount :currency', ['amount' => number_format($this->totalDue / 100, 2), 'currency' => $company->currency_code]) }}</flux:subheading>
        </div>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('portal.dashboard', ['company' => $company->slug])" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    @if (! $company->canAcceptCardPayments())
        <flux:callout variant="secondary" icon="clock" heading="{{ __('Online payments unavailable') }}">
            {{ __('This business isn’t accepting online card payments right now.') }}
        </flux:callout>
    @elseif ($errorMessage)
        <flux:callout variant="danger" icon="exclamation-triangle">{{ $errorMessage }}</flux:callout>
    @else
        <flux:card>
            <div
                wire:init="preparePayment"
                x-data="portalPayment(@js($clientSecret), @js(config('services.stripe.key')), @js($company->stripe_account_id), @js(route('portal.dashboard', ['company' => $company->slug])))"
                x-init="init()"
                wire:ignore
            >
                <template x-if="! ready">
                    <div class="py-8 text-center text-sm text-muted-foreground">{{ __('Loading secure payment form…') }}</div>
                </template>

                <div x-show="ready" class="flex flex-col gap-4">
                    <div id="payment-element"></div>
                    <p x-show="message" x-text="message" class="text-sm text-red-600"></p>
                    <flux:button variant="primary" class="w-full" x-on:click="submit()" ::disabled="submitting">
                        <span x-show="! submitting">{{ __('Pay now') }}</span>
                        <span x-show="submitting">{{ __('Processing…') }}</span>
                    </flux:button>
                </div>
            </div>
        </flux:card>
    @endif

    @php($paymentInstructions = $company->invoiceSettingsOrNew()->payment_instructions)
    @if (filled($paymentInstructions))
        <flux:card>
            <flux:heading size="sm">{{ __('How to pay') }}</flux:heading>
            <div class="mt-2 whitespace-pre-line text-sm text-muted-foreground" data-test="portal-payment-instructions">{{ $paymentInstructions }}</div>
        </flux:card>
    @endif
</div>

@script
<script nonce="{{ Vite::cspNonce() }}">
    Alpine.data('portalPayment', (clientSecret, publishableKey, stripeAccount, returnUrl) => ({
        stripe: null,
        elements: null,
        ready: false,
        submitting: false,
        message: '',

        init() {
            // The client secret arrives after wire:init -> preparePayment resolves.
            this.$wire.$watch('clientSecret', (secret) => secret && this.mountElement(secret));
            if (clientSecret) {
                this.mountElement(clientSecret);
            }
        },

        async mountElement(secret) {
            if (this.stripe) {
                return;
            }
            await this.loadStripeJs();
            this.stripe = Stripe(publishableKey, { stripeAccount });
            this.elements = this.stripe.elements({ clientSecret: secret });
            this.elements.create('payment').mount('#payment-element');
            this.ready = true;
        },

        loadStripeJs() {
            if (window.Stripe) {
                return Promise.resolve();
            }
            return new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = 'https://js.stripe.com/v3/';
                s.onload = resolve;
                s.onerror = reject;
                document.head.appendChild(s);
            });
        },

        async submit() {
            if (this.submitting) {
                return;
            }
            this.submitting = true;
            this.message = '';

            const { error } = await this.stripe.confirmPayment({
                elements: this.elements,
                confirmParams: { return_url: returnUrl },
            });

            if (error) {
                this.message = error.message;
                this.submitting = false;
            }
        },
    }));
</script>
@endscript
