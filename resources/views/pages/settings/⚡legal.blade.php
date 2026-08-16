<?php

use App\Support\Legal\LegalDocuments;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Legal')] class extends Component {
    /**
     * Each registered document enriched with this user's acceptance state:
     * whether they've agreed, the date/version they agreed to, and whether the
     * current version is newer than what they accepted (re-acceptance pending).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function documents(): array
    {
        $legal = app(LegalDocuments::class);
        $user = Auth::user();

        return $legal->all()->map(function (array $doc) use ($legal, $user): array {
            $latest = $doc['requires_acceptance'] ? $legal->latestAcceptance($user, $doc['key']) : null;

            return [
                ...$doc,
                'accepted_at' => $latest?->accepted_at,
                'accepted_version' => $latest?->version,
                'stale' => $doc['requires_acceptance']
                    && $latest !== null
                    && $latest->version !== $doc['version'],
            ];
        })->values()->all();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Legal settings') }}</flux:heading>

    <x-pages::settings.layout
        :heading="__('Legal')"
        :subheading="__('Review our legal documents and see what you have agreed to')"
        contentClass="max-w-2xl"
    >
        <div class="my-6 divide-y divide-border rounded-lg border border-border">
            @foreach ($this->documents as $doc)
                <div class="flex items-start justify-between gap-4 px-4 py-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <flux:link href="{{ $doc['url'] }}" target="_blank" rel="noopener" class="font-medium">
                                {{ $doc['title'] }}
                                <flux:icon.arrow-up-right class="ml-0.5 inline size-3.5" />
                            </flux:link>

                            @if ($doc['stale'])
                                <flux:badge size="sm" color="amber">{{ __('Updated — review again') }}</flux:badge>
                            @endif
                        </div>

                        @if ($doc['requires_acceptance'])
                            <flux:text size="sm" class="mt-1">
                                @if ($doc['accepted_at'])
                                    {{ __('You agreed on :date.', ['date' => $doc['accepted_at']->isoFormat('LL')]) }}
                                @else
                                    {{ __('Not yet accepted.') }}
                                @endif
                            </flux:text>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
