<?php

use App\Support\Legal\LegalDocuments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.onboarding'), Title('Review our terms')] class extends Component {
    public bool $agree = false;

    /**
     * Whether the user has accepted these documents before (re-acceptance after
     * an update) versus seeing them for the first time. Drives the copy.
     */
    public bool $isUpdate = false;

    public function mount(): void
    {
        $user = Auth::user();

        // If there is nothing outstanding (e.g. accepted in another tab, or the
        // page was reached directly), don't strand the user here.
        if (! $this->legal()->hasOutstanding($user)) {
            $this->redirect(route('home'));

            return;
        }

        $this->isUpdate = $user->legalAcceptances()->exists();
    }

    public function accept(): void
    {
        $this->validate(
            ['agree' => ['accepted']],
            ['agree.accepted' => __('You must accept the Terms of Service and Privacy Policy to continue.')],
        );

        $user = Auth::user();

        $this->legal()->record(
            $user,
            $this->outstanding->pluck('key')->all(),
            request(),
        );

        $this->redirect(route('home'));
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    #[\Livewire\Attributes\Computed]
    public function outstanding(): Collection
    {
        return $this->legal()->outstanding(Auth::user());
    }

    private function legal(): LegalDocuments
    {
        return app(LegalDocuments::class);
    }
}; ?>

<div class="mx-auto w-full max-w-md">
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <flux:heading size="xl">
                {{ $isUpdate ? __('Our terms have been updated') : __('Review our terms') }}
            </flux:heading>
            <flux:subheading>
                {{ $isUpdate
                    ? __('Please review and accept the updated documents to continue.')
                    : __('Please review and accept the following to continue.') }}
            </flux:subheading>
        </div>

        <form wire:submit="accept" class="flex flex-col gap-6">
            <ul class="divide-y divide-border rounded-lg border border-border">
                @foreach ($this->outstanding as $doc)
                    <li class="flex items-center justify-between gap-3 px-4 py-3">
                        <span class="text-sm font-medium text-foreground">{{ $doc['title'] }}</span>
                        <flux:link href="{{ $doc['url'] }}" target="_blank" rel="noopener" class="text-sm">
                            {{ __('Read') }}
                            <flux:icon.arrow-up-right class="ml-0.5 inline size-3.5" />
                        </flux:link>
                    </li>
                @endforeach
            </ul>

            <div>
                <flux:field variant="inline">
                    <flux:checkbox wire:model="agree" data-test="legal-agree" />
                    <flux:label class="!mb-0 text-sm">
                        {{ __('I have read and agree to the documents above.') }}
                    </flux:label>
                </flux:field>
                <flux:error name="agree" />
            </div>

            <flux:button variant="primary" type="submit" class="w-full" data-test="legal-accept-button">
                {{ __('Accept and continue') }}
            </flux:button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <flux:button type="submit" variant="ghost" size="sm" data-test="logout-button">
                {{ __('Log out') }}
            </flux:button>
        </form>
    </div>
</div>
