<?php

use App\Enums\SecurityEvent;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\AccessRevoker;
use Flux\Flux;
use Laravel\Passport\Passport;
use Livewire\Component;

new class extends Component {
    /**
     * Connected OAuth applications (e.g. MCP clients such as Claude) the
     * signed-in user has authorized — one row per client, summarizing that
     * client's active tokens.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $apps = [];

    public function mount(): void
    {
        $this->loadApps();
    }

    public function loadApps(): void
    {
        $user = auth()->user();

        if (! $user) {
            $this->apps = [];

            return;
        }

        // A user's OAuth grants are personal, not company-scoped, so query the
        // Passport token model directly by user_id. Expired tokens are filtered
        // in PHP rather than with a datetime WHERE so the result is identical on
        // MySQL and SQLite.
        $this->apps = Passport::tokenModel()::query()
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->with('client')
            ->get()
            ->filter(fn ($token) => $token->expires_at === null || $token->expires_at->isFuture())
            ->groupBy('client_id')
            ->map(function ($tokens, $clientId): array {
                $latest = $tokens->sortByDesc('created_at')->first();
                $scopes = $tokens->flatMap(fn ($token) => $token->scopes ?? [])->unique()->values();

                return [
                    'client_id' => (string) $clientId,
                    'name' => $latest->client?->name ?: __('Unknown application'),
                    'scopes' => $scopes->reject(fn ($scope) => $scope === '*')->all(),
                    'full_access' => $scopes->isEmpty() || $scopes->contains('*'),
                    'sessions' => $tokens->count(),
                    'connected_at_diff' => $latest->created_at?->diffForHumans(),
                ];
            })
            ->values()
            ->all();
    }

    public function revoke(string $clientId, AccessRevoker $revoker, SecurityLogRecorder $recorder): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        // Capture the client name for the audit trail before its tokens go.
        $name = Passport::clientModel()::query()->find($clientId)?->name;

        $revoker->revokePassportTokensForClient($user, $clientId);

        $recorder->record(SecurityEvent::McpConnectionRevoked, $user, metadata: [
            'client_id' => $clientId,
            'client_name' => $name,
        ]);

        $this->loadApps();

        Flux::toast(variant: 'success', text: __('Application access revoked.'));
    }
}; ?>

<section class="mt-12" wire:cloak>
    <flux:heading>{{ __('Authorized applications') }}</flux:heading>
    <flux:subheading>
        {{ __('Apps you have connected to your account over OAuth — for example an AI assistant (MCP) connector. Revoke one to sign it out immediately.') }}
        <a href="{{ route('docs.api') }}" class="underline" wire:navigate>{{ __('Learn about connections') }}</a>.
    </flux:subheading>

    <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm">
        <div class="border rounded-lg border-border overflow-hidden">
            @forelse ($apps as $app)
                <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-border' : '' }}">
                    <div class="flex items-center gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                            <flux:icon.bolt class="size-5 text-muted-foreground" />
                        </div>
                        <div class="space-y-1">
                            <p class="font-medium tracking-tight">{{ $app['name'] }}</p>
                            <div class="flex flex-wrap items-center gap-1">
                                @if ($app['full_access'])
                                    <flux:badge size="sm" color="amber">{{ __('Full access') }}</flux:badge>
                                @else
                                    @foreach ($app['scopes'] as $scope)
                                        <flux:badge size="sm" color="zinc">{{ $scope }}</flux:badge>
                                    @endforeach
                                @endif
                            </div>
                            <p class="text-muted-foreground text-xs">
                                @if ($app['connected_at_diff'])
                                    {{ __('Connected :time', ['time' => $app['connected_at_diff']]) }}
                                    <span class="opacity-50 mx-1">/</span>
                                @endif
                                {{ trans_choice(':count active session|:count active sessions', $app['sessions'], ['count' => $app['sessions']]) }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <flux:button
                            variant="ghost"
                            size="sm"
                            wire:click="revoke('{{ $app['client_id'] }}')"
                            wire:confirm="{{ __('Revoke this application? It will lose access immediately and must be reconnected to use again.') }}"
                            class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                        >
                            {{ __('Revoke') }}
                        </flux:button>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center">
                    <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-muted">
                        <flux:icon.bolt class="size-7 text-muted-foreground" />
                    </div>
                    <p class="font-medium">{{ __('No connected applications') }}</p>
                    <flux:text class="mt-1">{{ __('When you connect an AI assistant or other app over OAuth, it will appear here.') }}</flux:text>
                </div>
            @endforelse
        </div>
    </div>
</section>
