<?php

use App\Models\Company;
use App\Models\Grant;
use App\Services\Fundraising\GrantPoster;
use App\Services\Fundraising\RecognizeDeferredContribution;
use App\Support\Money;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Grant')] class extends Component {
    public Company $company;

    public Grant $grant;

    public string $recognizeAmount = '';

    public string $recognizeDate = '';

    public function mount(Company $company, Grant $grant): void
    {
        $this->company = $company;

        abort_unless($company->tracksFundraising(), 403);
        abort_unless($grant->company_id === $company->id, 404);

        $this->grant = $grant->load('funder', 'fund', 'awardJournalEntry', 'recognitions.journalEntry');
        $this->recognizeDate = $company->currentDateTime()->toDateString();
    }

    #[Computed]
    public function canRecognize(): bool
    {
        return $this->grant->status->isPosted()
            && $this->company->usesDeferralMethod()
            && $this->grant->is_restricted
            && $this->grant->deferredBalanceCents() > 0;
    }

    public function postAward(): void
    {
        try {
            app(GrantPoster::class)->postAward($this->grant);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->grant->refresh()->load('awardJournalEntry', 'recognitions.journalEntry');
        Flux::toast(variant: 'success', text: __('Grant award posted.'));
    }

    public function openRecognize(): void
    {
        $this->recognizeAmount = (string) Money::fromCents(app(RecognizeDeferredContribution::class)->straightLineAmountCents($this->grant));
        Flux::modal('recognize-grant')->show();
    }

    public function recognize(): void
    {
        $cents = Money::tryFromString($this->recognizeAmount)?->cents ?? 0;

        if ($cents <= 0) {
            $this->addError('recognizeAmount', __('Enter an amount greater than zero.'));

            return;
        }

        try {
            app(RecognizeDeferredContribution::class)->recognize($this->grant, $cents, $this->recognizeDate ?: $this->company->currentDateTime()->toDateString());
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->grant->refresh()->load('recognitions.journalEntry');
        $this->recognizeAmount = '';
        Flux::modal('recognize-grant')->close();
        Flux::toast(variant: 'success', text: __('Grant revenue recognized.'));
    }

    public function void(): void
    {
        try {
            app(GrantPoster::class)->void($this->grant);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->grant->refresh();
        Flux::toast(variant: 'success', text: __('Grant voided.'));
    }
}; ?>

<section class="mx-auto w-full max-w-3xl">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1" data-test="page-title">{{ $grant->grant_no }}</flux:heading>
                <flux:badge :color="$grant->status->color()" data-test="grant-status">{{ $grant->status->label() }}</flux:badge>
                @if ($grant->is_restricted)
                    <flux:badge color="amber">{{ __('Restricted') }}</flux:badge>
                @endif
            </div>
            <flux:subheading>{{ $grant->name }} · {{ $grant->funder?->display_name ?? __('No funder') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($grant->isDraft())
                <flux:button variant="ghost" icon="pencil" :href="route('grants.edit', ['company' => $company, 'grant' => $grant])" wire:navigate data-test="grant-edit-button">{{ __('Edit') }}</flux:button>
                <flux:button variant="primary" icon="check" wire:click="postAward" data-test="grant-post-button">{{ __('Post award') }}</flux:button>
            @else
                @if ($this->canRecognize)
                    <flux:button variant="primary" icon="arrow-trending-up" wire:click="openRecognize" data-test="grant-recognize-button">{{ __('Recognize revenue') }}</flux:button>
                @endif
                @if ($grant->status->value !== 'void')
                    <flux:button variant="danger" icon="x-mark" wire:click="void" wire:confirm="{{ __('Void this grant? The award and all recognitions will be reversed.') }}" data-test="grant-void-button">{{ __('Void') }}</flux:button>
                @endif
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 rounded-lg border border-border p-6 text-sm md:grid-cols-4">
        <div>
            <div class="text-muted-foreground">{{ __('Award') }}</div>
            <div class="font-mono font-semibold" data-test="grant-award">{{ Money::fromCents($grant->award_amount_cents, $grant->currency_code ?? $company->currency_code) }}</div>
        </div>
        <div>
            <div class="text-muted-foreground">{{ __('Recognized') }}</div>
            <div class="font-mono" data-test="grant-recognized">{{ Money::fromCents($grant->recognized_to_date_cents, $grant->currency_code ?? $company->currency_code) }}</div>
        </div>
        <div>
            <div class="text-muted-foreground">{{ __('Deferred balance') }}</div>
            <div class="font-mono" data-test="grant-deferred-balance">{{ Money::fromCents($grant->deferredBalanceCents(), $grant->currency_code ?? $company->currency_code) }}</div>
        </div>
        <div>
            <div class="text-muted-foreground">{{ __('Period') }}</div>
            <div>{{ $grant->period_start?->format('M j, Y') ?? '—' }} – {{ $grant->period_end?->format('M j, Y') ?? '—' }}</div>
        </div>
    </div>

    @if ($grant->awardJournalEntry)
        <flux:text class="mt-3">
            <a href="{{ route('journal.show', ['company' => $company, 'entry' => $grant->awardJournalEntry]) }}" wire:navigate class="text-primary hover:underline">{{ __('View award journal entry') }} #{{ $grant->awardJournalEntry->id }}</a>
        </flux:text>
    @endif

    <flux:heading size="lg" class="mt-8 mb-3">{{ __('Revenue recognized') }}</flux:heading>
    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Memo') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($grant->recognitions->whereNull('voided_at') as $recognition)
                    <tr data-test="grant-recognition-row">
                        <td class="px-4 py-2">{{ $recognition->recognition_date?->format('M j, Y') }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $recognition->memo ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($recognition->amount_cents, $grant->currency_code ?? $company->currency_code) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-muted-foreground">{{ __('No revenue recognized yet.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="recognize-grant" class="max-w-md">
        <form wire:submit="recognize" class="space-y-6">
            <flux:heading size="lg">{{ __('Recognize grant revenue') }}</flux:heading>
            <flux:text>{{ __('Releases the amount from the deferred liability into grant revenue.') }}</flux:text>
            <flux:input wire:model="recognizeAmount" :label="__('Amount')" data-test="grant-recognize-amount" />
            <flux:input type="date" wire:model="recognizeDate" :label="__('Date')" data-test="grant-recognize-date" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="grant-recognize-confirm">{{ __('Recognize') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
