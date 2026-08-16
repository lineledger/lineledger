<?php

use App\Services\Proof\ProofArtifactWriter;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.onboarding')]
#[Title('Verification')]
class extends Component {
    /**
     * Load every published manifest written by `php artisan proof:generate`.
     *
     * @return list<array<string, mixed>>
     */
    public function manifests(): array
    {
        $dir = ProofArtifactWriter::directory();

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.json') ?: [];
        sort($files);

        return collect($files)
            ->map(fn (string $file) => json_decode((string) file_get_contents($file), true))
            ->filter(fn ($manifest) => is_array($manifest))
            ->values()
            ->all();
    }
}; ?>

<section class="w-full space-y-8">
    <header class="space-y-3 text-center">
        <flux:heading size="xl">{{ __('Proof it adds up') }}</flux:heading>
        <p class="mx-auto max-w-2xl text-sm text-muted-foreground">
            {{ __('These are live, automated accounting tests run against real seeded data. Each one checks that the trial balance balances, ties to the balance sheet and income statement, and that the immutable audit log verifies end to end. Download the source data and generated reports to check the numbers yourself.') }}
        </p>
    </header>

    @php($manifests = $this->manifests())

    @forelse ($manifests as $manifest)
        <article class="rounded-xl border border-border bg-card p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-semibold">{{ $manifest['title'] }}</h2>
                        @if ($manifest['passed'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300">
                                ✓ {{ __('Passed') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300">
                                ✗ {{ __('Failed') }}
                            </span>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ __('Last run') }}:
                        <time>{{ CarbonImmutable::parse($manifest['generated_at'])->format('M j, Y · g:i A T') }}</time>
                        · {{ $manifest['audit']['detail'] }}
                    </p>
                </div>

                <flux:button
                    :href="route('verification.download', ['test' => $manifest['key']])"
                    icon="arrow-down-tray"
                    variant="primary"
                    size="sm"
                >
                    {{ __('Download source + reports') }}
                </flux:button>
            </div>

            <div class="mt-5 space-y-5">
                @foreach ($manifest['checkpoints'] as $checkpoint)
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">{{ $checkpoint['label'] }}</h3>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($checkpoint['checks'] as $check)
                                <li class="flex items-start gap-2 text-sm">
                                    <span class="{{ $check['passed'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $check['passed'] ? '✓' : '✗' }}
                                    </span>
                                    <span class="text-foreground">
                                        {{ $check['name'] }}
                                        <span class="text-muted-foreground">— {{ $check['detail'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            @if (! empty($manifest['source_files']))
                <details class="mt-5 text-xs text-muted-foreground">
                    <summary class="cursor-pointer select-none">{{ __('Source files & checksums (SHA-256)') }}</summary>
                    <ul class="mt-2 space-y-1 font-mono">
                        @foreach ($manifest['source_files'] as $file)
                            <li class="break-all">{{ $file['name'] }} — {{ $file['sha256'] }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </article>
    @empty
        <div class="rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground">
            {{ __('Verification artifacts have not been generated yet. Run') }}
            <code class="rounded bg-muted px-1.5 py-0.5">php artisan proof:generate</code>.
        </div>
    @endforelse
</section>
