<?php

use App\Enums\CompanyRestoreStatus;
use App\Jobs\RestoreCompanyDataJob;
use App\Models\CompanyRestore;
use App\Services\Restore\BundleInspector;
use App\Services\Restore\Exceptions\BundleValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

// Restore creates a brand-new company, so it's reachable before the user has
// one (the onboarding wizard redirects here). Use the onboarding layout — the
// default app layout renders a company-scoped sidebar (route('dashboard'),
// etc.) that throws when there's no current company.
new #[Layout('layouts.onboarding'), Title('Restore from backup')] class extends Component {
    use WithFileUploads;

    /** Temporary uploaded file (Livewire managed). */
    public $upload = null;

    /** Populated once the bundle is inspected and the CompanyRestore row exists. */
    public ?int $restoreId = null;

    /** State machine: 'upload' → 'preview' → 'status'. */
    public string $mode = 'upload';

    /**
     * Preview struct returned by BundleInspector::inspect().
     *
     * @var array<string, mixed>|null
     */
    public ?array $preview = null;

    /** Error string surfaced when BundleInspector rejects the bundle. */
    public ?string $uploadError = null;

    /**
     * Validate + inspect the uploaded ZIP, then create the CompanyRestore row
     * in `Ready` state. On BundleValidationException we keep the user on the
     * upload screen with a friendly message; the persisted file is deleted so
     * we don't accumulate orphaned bundles.
     */
    public function inspect(BundleInspector $inspector): void
    {
        $this->uploadError = null;

        $this->validate([
            'upload' => ['required', 'file', 'mimes:zip', 'max:102400'],
        ]);

        // Persist the upload — Livewire's temp file gets garbage-collected.
        // store() MOVES the temp file off the livewire-tmp disk, so once we've
        // done this the `$this->upload` handle is stale. We must drop it before
        // returning: re-validating a moved temp file (e.g. a second click on
        // "Inspect bundle" after a rejection) throws UnableToRetrieveMetadata
        // from the `max` rule's getSize() and 500s the request.
        $diskPath = $this->upload->store('restores', 'local');
        $absolute = Storage::disk('local')->path($diskPath);

        try {
            $preview = $inspector->inspect($absolute, auth()->user());
        } catch (BundleValidationException $e) {
            Storage::disk('local')->delete($diskPath);
            $this->reset('upload');
            $this->uploadError = $e->getMessage();

            return;
        }

        $restore = CompanyRestore::create([
            'requested_by_user_id' => auth()->id(),
            'status' => CompanyRestoreStatus::Ready,
            'file_path' => $diskPath,
            'file_size_bytes' => Storage::disk('local')->size($diskPath),
            'sha256' => hash_file('sha256', $absolute),
            'manifest_data' => $preview['manifest'] ?? null,
        ]);

        $this->reset('upload');
        $this->restoreId = $restore->id;
        $this->preview = $preview;
        $this->mode = 'preview';
    }

    /**
     * Confirm the restore: flip the row to Pending and dispatch the queued
     * job (other agent's work — TODO marker below). UI moves to status mode.
     */
    public function confirm(): void
    {
        abort_if($this->restoreId === null, 422);

        $restore = CompanyRestore::findOrFail($this->restoreId);
        abort_unless($restore->requested_by_user_id === auth()->id(), 403);
        abort_unless($restore->status === CompanyRestoreStatus::Ready, 409);

        $restore->update([
            'status' => CompanyRestoreStatus::Pending,
            'started_at' => now(),
        ]);

        RestoreCompanyDataJob::dispatch($restore);

        $this->mode = 'status';
    }

    /**
     * Throw away the in-flight upload + CompanyRestore row and return to the
     * upload form. Used from the preview screen and from the Failed status.
     */
    public function cancel(): void
    {
        if ($this->restoreId !== null) {
            $restore = CompanyRestore::find($this->restoreId);

            if ($restore !== null) {
                if ($restore->file_path !== null && Storage::disk('local')->exists($restore->file_path)) {
                    Storage::disk('local')->delete($restore->file_path);
                }

                $restore->delete();
            }
        }

        $this->reset(['upload', 'restoreId', 'preview', 'uploadError']);
        $this->mode = 'upload';
    }

    /**
     * The in-flight CompanyRestore row. Computed (not stored) so the polling
     * pane always sees the freshest status without us juggling state.
     */
    #[Computed]
    public function restore(): ?CompanyRestore
    {
        return $this->restoreId !== null
            ? CompanyRestore::find($this->restoreId)
            : null;
    }

    /**
     * Bail to the new company's dashboard once the restore completes.
     */
    public function maybeRedirect(): mixed
    {
        $restore = $this->restore;

        if ($restore !== null
            && $restore->status === CompanyRestoreStatus::Completed
            && $restore->company_id !== null) {
            return $this->redirectRoute(
                'dashboard',
                ['company' => $restore->company->slug],
                navigate: true,
            );
        }

        return null;
    }

    /**
     * Flux badge color for a restore status. Mirrors the Phase 1 backup page
     * palette so the two flows feel like one product surface.
     */
    public function statusColor(CompanyRestoreStatus $status): string
    {
        return match ($status) {
            CompanyRestoreStatus::Pending => 'zinc',
            CompanyRestoreStatus::Inspecting => 'zinc',
            CompanyRestoreStatus::Ready => 'blue',
            CompanyRestoreStatus::Running => 'blue',
            CompanyRestoreStatus::Completed => 'emerald',
            CompanyRestoreStatus::Failed => 'red',
        };
    }

    public function statusLabel(CompanyRestoreStatus $status): string
    {
        return match ($status) {
            CompanyRestoreStatus::Pending => __('Pending'),
            CompanyRestoreStatus::Inspecting => __('Inspecting'),
            CompanyRestoreStatus::Ready => __('Ready'),
            CompanyRestoreStatus::Running => __('Running'),
            CompanyRestoreStatus::Completed => __('Completed'),
            CompanyRestoreStatus::Failed => __('Failed'),
        };
    }

    public function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        return Number::fileSize($bytes);
    }
}; ?>

<section class="mx-auto max-w-3xl p-6">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Restore from backup') }}</flux:heading>
        <flux:text>
            {{ __('Upload a backup ZIP exported from another LineLedger instance to create a new company on this one.') }}
        </flux:text>
    </div>

    {{-- ─── Upload mode ──────────────────────────────────────────────────── --}}
    @if ($mode === 'upload')
        <div class="rounded-lg border border-border bg-card p-6">
            @if ($uploadError !== null)
                <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">
                    <flux:callout.heading>{{ __('This bundle cannot be restored') }}</flux:callout.heading>
                    <flux:callout.text>{{ $uploadError }}</flux:callout.text>
                    <flux:callout.text>
                        <button type="button" class="underline" wire:click="cancel" data-test="restore-try-again">
                            {{ __('Try again') }}
                        </button>
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div
                x-data="{ dragging: false }"
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false; $wire.upload('upload', $event.dataTransfer.files[0], () => {}, () => {})"
                x-on:click="$refs.restoreInput.click()"
                :class="dragging ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/40' : 'border-border'"
                class="cursor-pointer rounded-lg border-2 border-dashed p-8 text-center transition"
                data-test="restore-dropzone"
            >
                <input
                    type="file"
                    wire:model="upload"
                    accept=".zip,application/zip"
                    x-ref="restoreInput"
                    class="hidden"
                />
                <flux:icon.arrow-up-tray class="mx-auto mb-2 size-6 text-muted-foreground" />
                <p class="text-sm">{{ __('Drag & drop your backup ZIP here, or click to choose.') }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ __('Up to 100 MB. Only .zip files exported by LineLedger are accepted.') }}
                </p>
            </div>

            <div wire:loading wire:target="upload" class="mt-2 text-sm text-muted-foreground">
                {{ __('Uploading…') }}
            </div>

            @if ($upload !== null)
                <p class="mt-3 text-sm text-emerald-600" data-test="restore-file-selected">
                    {{ __('Selected: :name', ['name' => $upload->getClientOriginalName()]) }}
                </p>
            @endif

            @error('upload')
                <p class="mt-2 text-sm text-rose-600" data-test="restore-upload-error">{{ $message }}</p>
            @enderror

            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button
                    variant="primary"
                    wire:click="inspect"
                    :disabled="$upload === null"
                    data-test="restore-inspect"
                >
                    {{ __('Inspect bundle') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- ─── Preview mode ─────────────────────────────────────────────────── --}}
    @if ($mode === 'preview' && $preview !== null)
        <div class="rounded-lg border border-border bg-card p-6">
            <flux:heading size="lg">{{ __('Bundle ready to restore') }}</flux:heading>
            <flux:text class="mb-4">
                {{ __('Confirm the details below to start the restore. A brand-new company will be created and you will be its Owner.') }}
            </flux:text>

            <dl class="mb-5 space-y-2 text-sm" data-test="restore-preview-summary">
                <div class="flex justify-between border-b border-border pb-2">
                    <dt class="text-muted-foreground">{{ __('Source company') }}</dt>
                    <dd class="font-medium">
                        {{ $preview['company']['name'] ?? '—' }}
                        @if (! empty($preview['company']['slug']))
                            <span class="text-xs text-muted-foreground">/ {{ $preview['company']['slug'] }}</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between border-b border-border pb-2">
                    <dt class="text-muted-foreground">{{ __('Attachments') }}</dt>
                    <dd class="font-medium">
                        {{ trans_choice(':n file|:n files', (int) ($preview['attachment_count'] ?? 0), ['n' => (int) ($preview['attachment_count'] ?? 0)]) }}
                        <span class="opacity-50 mx-1">/</span>
                        {{ $this->formatBytes((int) ($preview['total_bytes'] ?? 0)) }}
                    </dd>
                </div>
                <div class="flex justify-between border-b border-border pb-2">
                    <dt class="text-muted-foreground">{{ __('Bundle app version') }}</dt>
                    <dd class="font-medium">
                        {{ $preview['bundle_app_version'] !== '' ? $preview['bundle_app_version'] : '—' }}
                        <span class="text-xs text-muted-foreground">→ {{ $preview['target_app_version'] }}</span>
                    </dd>
                </div>
            </dl>

            @if (! empty($preview['app_version_mismatch']))
                <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800/60 dark:bg-amber-950/40 dark:text-amber-200" data-test="restore-version-mismatch">
                    <p class="font-medium">{{ __('App version mismatch') }}</p>
                    <p class="text-xs">
                        {{ __('Bundle was created by version :bundle but this instance runs version :target. The restore will proceed; review the results for any behavioural differences.', [
                            'bundle' => $preview['bundle_app_version'] !== '' ? $preview['bundle_app_version'] : __('(unknown)'),
                            'target' => $preview['target_app_version'],
                        ]) }}
                    </p>
                </div>
            @endif

            @php $byGroup = $preview['row_counts_by_group'] ?? []; @endphp
            @if (! empty($byGroup))
                <div class="mb-5">
                    <p class="mb-2 text-sm font-medium">{{ __('Records to restore') }}</p>
                    <div class="overflow-hidden rounded-md border border-border">
                        <table class="min-w-full text-sm" data-test="restore-rows-by-group">
                            <tbody>
                                @foreach ($byGroup as $group => $count)
                                    <tr class="border-t first:border-t-0 border-border">
                                        <td class="px-3 py-2 text-muted-foreground">{{ ucfirst((string) $group) }}</td>
                                        <td class="px-3 py-2 text-right font-medium">{{ number_format((int) $count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @php $userMatch = $preview['user_match_summary'] ?? ['matched' => 0, 'fallback' => 0, 'samples' => []]; @endphp
            <div class="mb-5 rounded-md border border-border p-3 text-sm" data-test="restore-user-match">
                <p class="font-medium">{{ __('User attribution') }}</p>
                <p class="mt-1 text-muted-foreground">
                    {{ __(':matched of :total users matched by email. The remaining :fallback will be attributed to you.', [
                        'matched' => (int) $userMatch['matched'],
                        'total' => (int) $userMatch['matched'] + (int) $userMatch['fallback'],
                        'fallback' => (int) $userMatch['fallback'],
                    ]) }}
                </p>
                @if (! empty($userMatch['samples']))
                    <details class="mt-2">
                        <summary class="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
                            {{ __('View match preview') }}
                        </summary>
                        <ul class="mt-2 space-y-1 text-xs text-muted-foreground">
                            @foreach ($userMatch['samples'] as $sample)
                                <li>
                                    <span class="font-mono">{{ $sample['email'] ?? '—' }}</span>
                                    <span class="opacity-50">→</span>
                                    {{ ($sample['match'] ?? null) === 'email' ? __('matched existing user') : __('attributed to you') }}
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>

            @if (! empty($preview['warnings']))
                <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4" data-test="restore-warnings">
                    <flux:callout.heading>{{ __('Heads up') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="list-disc pl-5">
                            @foreach ($preview['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex flex-wrap gap-2">
                <flux:button variant="primary" wire:click="confirm" data-test="restore-confirm">
                    {{ __('Confirm and restore') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="cancel" data-test="restore-cancel">
                    {{ __('Choose a different file') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- ─── Status mode ──────────────────────────────────────────────────── --}}
    @if ($mode === 'status')
        @php $restore = $this->restore; @endphp
        <div
            class="rounded-lg border border-border bg-card p-6"
            wire:init="maybeRedirect"
            @if ($restore?->isInFlight()) wire:poll.3s="maybeRedirect" @endif
            data-test="restore-status"
        >
            <div class="flex items-center gap-3">
                @if ($restore?->isInFlight())
                    <flux:icon.arrow-path class="size-5 animate-spin text-muted-foreground" />
                @endif
                <flux:heading size="lg">
                    @if ($restore?->isComplete())
                        {{ __('Restore complete') }}
                    @elseif ($restore?->isFailed())
                        {{ __('Restore failed') }}
                    @else
                        {{ __('Restoring…') }}
                    @endif
                </flux:heading>
                @if ($restore)
                    <flux:badge size="sm" :color="$this->statusColor($restore->status)">
                        {{ $this->statusLabel($restore->status) }}
                    </flux:badge>
                @endif
            </div>

            @if ($restore?->isInFlight())
                <flux:text class="mt-3">
                    {{ __('We are unpacking your bundle and rebuilding the company. This page updates automatically — large bundles may take several minutes.') }}
                </flux:text>
            @endif

            @if ($restore?->isComplete())
                <flux:callout variant="success" icon="check-circle" class="mt-4">
                    <flux:callout.heading>{{ __('All done') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Redirecting you to the new company dashboard…') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($restore?->isFailed())
                <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4" data-test="restore-failed">
                    <flux:callout.heading>{{ __('Something went wrong') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ $restore->error_message ?? __('The restore could not be completed.') }}
                    </flux:callout.text>
                </flux:callout>

                <div class="mt-4">
                    <flux:button variant="primary" wire:click="cancel" data-test="restore-start-over">
                        {{ __('Start over') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @endif
</section>
