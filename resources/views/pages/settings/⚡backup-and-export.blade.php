<?php

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Jobs\ExportCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyBackup;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Backup & Export')] class extends Component {
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($this->userIsOwner(), 403);
    }

    protected function userIsOwner(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->companyRole($this->company) === CompanyRole::Owner;
    }

    /**
     * Recent backups for this company (auto-scoped via BelongsToCompany).
     *
     * @return \Illuminate\Support\Collection<int, CompanyBackup>
     */
    #[Computed]
    public function backups()
    {
        return CompanyBackup::query()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * True when any backup is still being produced — used to gate wire:poll
     * so we stop hammering the server once everything settles.
     */
    #[Computed]
    public function hasInFlight(): bool
    {
        return $this->backups->contains(
            fn (CompanyBackup $backup) => in_array(
                $backup->status,
                [CompanyBackupStatus::Pending, CompanyBackupStatus::Running],
                true,
            ),
        );
    }

    public function createBackup(): void
    {
        abort_unless($this->userIsOwner(), 403);

        $backup = CompanyBackup::create([
            'status' => CompanyBackupStatus::Pending,
            'requested_by_user_id' => auth()->id(),
            'app_version' => config('version.app'),
            'schema_version' => config('version.schema'),
        ]);

        ExportCompanyDataJob::dispatch($backup);

        unset($this->backups, $this->hasInFlight);

        Flux::toast(variant: 'success', text: __('Backup queued — refresh in a minute.'));
    }

    public function deleteBackup(int $id): void
    {
        abort_unless($this->userIsOwner(), 403);

        $backup = CompanyBackup::query()->where('id', $id)->firstOrFail();

        $disk = Storage::disk($backup->storageDisk());

        if ($backup->file_path && $disk->exists($backup->file_path)) {
            $disk->delete($backup->file_path);
        }

        $backup->delete();

        unset($this->backups, $this->hasInFlight);

        Flux::toast(variant: 'success', text: __('Backup deleted.'));
    }

    /**
     * Generate a 1-hour signed download URL for a Ready backup.
     */
    public function downloadUrl(CompanyBackup $backup): string
    {
        return URL::temporarySignedRoute(
            'settings.backup.download',
            now()->addHour(),
            ['company' => $this->company, 'backup' => $backup->id],
        );
    }

    /**
     * Flux badge colour for a backup status.
     */
    public function statusColor(CompanyBackupStatus $status): string
    {
        return match ($status) {
            CompanyBackupStatus::Pending => 'zinc',
            CompanyBackupStatus::Running => 'blue',
            CompanyBackupStatus::Ready => 'emerald',
            CompanyBackupStatus::Failed => 'red',
            CompanyBackupStatus::Expired => 'amber',
        };
    }

    public function statusLabel(CompanyBackupStatus $status): string
    {
        return match ($status) {
            CompanyBackupStatus::Pending => __('Pending'),
            CompanyBackupStatus::Running => __('Running'),
            CompanyBackupStatus::Ready => __('Ready'),
            CompanyBackupStatus::Failed => __('Failed'),
            CompanyBackupStatus::Expired => __('Expired'),
        };
    }

    public function formatSize(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        return Number::fileSize($bytes);
    }
}; ?>

<section class="w-full" @if ($this->hasInFlight) wire:poll.5s @endif>
    @include('partials.settings-heading')

    <x-pages::settings.layout
        :heading="__('Backup & Export')"
        :subheading="__('Download a full ZIP of this company\'s data — for archival or self-host migration.')"
        contentClass="max-w-4xl"
    >
        <div class="space-y-6 text-sm">
            <flux:text>
                {{ __('Backups include every record scoped to this company: chart of accounts, transactions, attachments, settings, and API keys. Only owners can create or download backups. Each download link is valid for one hour.') }}
            </flux:text>

            <div class="flex justify-start">
                <flux:button
                    variant="primary"
                    size="sm"
                    wire:click="createBackup"
                    data-test="backup-create"
                >
                    {{ __('Create backup') }}
                </flux:button>
            </div>

            <div class="border rounded-lg border-border overflow-hidden">
                @forelse ($this->backups as $backup)
                    <div class="flex items-center justify-between gap-4 p-4 {{ ! $loop->last ? 'border-b border-border' : '' }}">
                        <div class="flex items-start gap-4">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                <flux:icon.archive-box class="size-5 text-muted-foreground" />
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-2.5">
                                    <p class="font-medium tracking-tight">
                                        {{ $backup->created_at?->format('M j, Y g:i A') }}
                                    </p>
                                    <flux:badge size="sm" :color="$this->statusColor($backup->status)">
                                        {{ $this->statusLabel($backup->status) }}
                                    </flux:badge>
                                </div>
                                <p class="text-muted-foreground text-xs">
                                    {{ __('Size: :size', ['size' => $this->formatSize($backup->file_size_bytes)]) }}
                                    <span class="opacity-50 mx-1">/</span>
                                    @if ($backup->expires_at)
                                        {{ __('Expires :time', ['time' => $backup->expires_at->diffForHumans()]) }}
                                    @else
                                        {{ __('Expires —') }}
                                    @endif
                                </p>
                                @if ($backup->status === CompanyBackupStatus::Failed && $backup->error_message)
                                    <p class="text-red-500 dark:text-red-400 text-xs">
                                        {{ $backup->error_message }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($backup->isReady())
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-down-tray"
                                    icon:variant="outline"
                                    :href="$this->downloadUrl($backup)"
                                    data-test="backup-download"
                                >
                                    {{ __('Download') }}
                                </flux:button>
                            @endif
                            <flux:button
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                icon:variant="outline"
                                wire:click="deleteBackup({{ $backup->id }})"
                                wire:confirm="{{ __('Delete this backup? The ZIP will be removed and the download link will stop working.') }}"
                                class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                data-test="backup-delete"
                            />
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-muted">
                            <flux:icon.archive-box class="size-7 text-muted-foreground" />
                        </div>
                        <p class="font-medium">{{ __('No backups yet') }}</p>
                        <flux:text class="mt-1">{{ __('Create a backup to download a ZIP of every record in this company.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    </x-pages::settings.layout>
</section>
