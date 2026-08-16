<?php

use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Assets\DepreciationSchedule;
use App\Services\AttachmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Asset')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public Asset $asset;

    public array $newAttachments = [];

    public function mount(Company $company, Asset $asset): void
    {
        $this->company = $company;
        $this->asset = $asset->load([
            'category',
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
        ]);
    }

    public function archive(): void
    {
        $this->asset->update(['is_active' => false]);
        Flux::toast(variant: 'success', text: __('Asset archived.'));
    }

    public function restore(): void
    {
        $this->asset->update(['is_active' => true]);
        Flux::toast(variant: 'success', text: __('Asset restored.'));
    }

    public function delete(): void
    {
        $this->asset->delete();
        Flux::toast(variant: 'success', text: __('Asset deleted.'));
        $this->redirectRoute('assets.index', ['company' => $this->company->slug], navigate: true);
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->asset, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        $service->remove(Attachment::findOrFail($id), $this->asset);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    #[Computed]
    public function attachments()
    {
        return $this->asset->attachments()->get();
    }

    /**
     * The straight-line schedule merged with what the generator has produced:
     * one pass over DepreciationSchedule::for() against the asset's pivot rows
     * (with their journal entries) keyed by period. Returns the per-month rows
     * plus the accumulated total of months whose entry is posted and not voided.
     *
     * @return array{rows: list<array{period: \Carbon\CarbonImmutable, amount_cents: int, cumulative_cents: int, status: string, journal_entry_id: ?int}>, accumulated_cents: int}
     */
    #[Computed]
    public function depreciationData(): array
    {
        $schedule = DepreciationSchedule::for($this->asset);

        $entries = $this->asset->depreciationEntries()
            ->with('journalEntry')
            ->get()
            ->keyBy(fn ($entry) => $entry->period->format('Y-m-d'));

        $accumulated = (int) $entries
            ->filter(fn ($entry) => $entry->journalEntry !== null
                && $entry->journalEntry->isPosted()
                && ! $entry->journalEntry->isVoided())
            ->sum('amount_cents');

        $rows = array_map(function (array $row) use ($entries): array {
            $journalEntry = $entries->get($row['period']->format('Y-m-d'))?->journalEntry;

            $status = match (true) {
                $journalEntry?->isVoided() === true => 'voided',
                $journalEntry?->isPosted() === true => 'posted',
                $journalEntry !== null => 'draft',
                $this->company->isLockedFor($row['period']->endOfMonth()->startOfDay()) => 'locked',
                default => 'pending',
            };

            return $row + ['status' => $status, 'journal_entry_id' => $journalEntry?->id];
        }, $schedule);

        return ['rows' => $rows, 'accumulated_cents' => $accumulated];
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $asset->name }} <span class="text-muted-foreground font-mono text-base">{{ $asset->asset_no }}</span></flux:heading>
            <flux:subheading>
                {{ optional($asset->category)->name ?? __('Uncategorized') }} &middot;
                {{ __('Acquired') }} {{ $asset->acquired_date->toDateString() }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($asset->status->value)
                    @case('in-service') <flux:badge color="green">{{ $asset->status->label() }}</flux:badge> @break
                    @case('disposed') <flux:badge color="zinc">{{ $asset->status->label() }}</flux:badge> @break
                    @case('sold') <flux:badge color="blue">{{ $asset->status->label() }}</flux:badge> @break
                    @case('lost') <flux:badge color="red">{{ $asset->status->label() }}</flux:badge> @break
                @endswitch
                @if (! $asset->is_active)
                    <flux:badge color="zinc">{{ __('Inactive') }}</flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="primary" class="!hidden lg:!inline-flex" :href="route('assets.edit', ['company' => $company->slug, 'asset' => $asset->id])" wire:navigate data-test="edit-asset-button">
                {{ __('Edit') }}
            </flux:button>

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="asset-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    <flux:menu.item class="lg:hidden" icon="pencil" :href="route('assets.edit', ['company' => $company->slug, 'asset' => $asset->id])" wire:navigate data-test="edit-asset-menu-item">
                        {{ __('Edit') }}
                    </flux:menu.item>
                    @if ($asset->is_active)
                        <flux:menu.item icon="archive-box" wire:click="archive" wire:confirm="{{ __('Archive this asset?') }}" data-test="archive-asset-button">{{ __('Archive') }}</flux:menu.item>
                    @else
                        <flux:menu.item icon="arrow-uturn-left" wire:click="restore" data-test="restore-asset-button">{{ __('Restore') }}</flux:menu.item>
                    @endif
                    <flux:menu.separator />
                    <flux:menu.item icon="trash" variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this asset? This cannot be undone via the UI.') }}" data-test="delete-asset-button">{{ __('Delete') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
        <div class="rounded-lg border border-border p-4">
            <flux:heading size="sm" class="mb-3">{{ __('Identification') }}</flux:heading>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">{{ __('Asset #') }}</dt>
                <dd class="font-mono">{{ $asset->asset_no }}</dd>
                <dt class="text-muted-foreground">{{ __('Name') }}</dt>
                <dd>{{ $asset->name }}</dd>
                <dt class="text-muted-foreground">{{ __('Category') }}</dt>
                <dd>{{ optional($asset->category)->name ?? '—' }}</dd>
                @if ($asset->description)
                    <dt class="text-muted-foreground">{{ __('Description') }}</dt>
                    <dd>{{ $asset->description }}</dd>
                @endif
            </dl>
        </div>

        <div class="rounded-lg border border-border p-4">
            <flux:heading size="sm" class="mb-3">{{ __('Acquisition') }}</flux:heading>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">{{ __('Acquired') }}</dt>
                <dd>{{ $asset->acquired_date->toDateString() }}</dd>
                @if ($asset->in_service_date)
                    <dt class="text-muted-foreground">{{ __('In service') }}</dt>
                    <dd>{{ $asset->in_service_date->toDateString() }}</dd>
                @endif
                <dt class="text-muted-foreground">{{ __('Cost') }}</dt>
                <dd class="font-mono">{{ number_format($asset->cost_cents / 100, 2) }}</dd>
                <dt class="text-muted-foreground">{{ __('Salvage value') }}</dt>
                <dd class="font-mono">{{ number_format($asset->salvage_value_cents / 100, 2) }}</dd>
                @if ($asset->useful_life_months)
                    <dt class="text-muted-foreground">{{ __('Useful life (months)') }}</dt>
                    <dd>{{ $asset->useful_life_months }}</dd>
                @endif
            </dl>
            @if ($asset->source_type && $asset->source_id)
                <div class="mt-3 text-sm" data-test="asset-source-link">
                    {{ __('Source') }}:
                    @if ($asset->source_type === Bill::class)
                        <a href="{{ route('bills.show', ['company' => $company->slug, 'bill' => $asset->source_id]) }}" wire:navigate class="underline">{{ __('Bill') }} #{{ $asset->source_id }}</a>
                    @elseif ($asset->source_type === Cheque::class)
                        <a href="{{ route('cheques.show', ['company' => $company->slug, 'cheque' => $asset->source_id]) }}" wire:navigate class="underline">{{ $company->jurisdiction->cheque('singular') }} #{{ $asset->source_id }}</a>
                    @elseif ($asset->source_type === JournalEntry::class)
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $asset->source_id]) }}" wire:navigate class="underline">{{ __('Journal entry') }} #{{ $asset->source_id }}</a>
                    @else
                        <span class="font-mono text-xs">{{ $asset->source_type }}#{{ $asset->source_id }}</span>
                    @endif
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-border p-4">
            <flux:heading size="sm" class="mb-3">{{ __('GL accounts') }}</flux:heading>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">{{ __('Asset') }}</dt>
                <dd>{{ optional($asset->assetAccount)->code }} — {{ optional($asset->assetAccount)->name }}</dd>
                <dt class="text-muted-foreground">{{ __('Accumulated depreciation') }}</dt>
                <dd>{{ optional($asset->accumulatedDepreciationAccount)->code }} {{ optional($asset->accumulatedDepreciationAccount)->name ? '— '.$asset->accumulatedDepreciationAccount->name : '—' }}</dd>
                <dt class="text-muted-foreground">{{ __('Depreciation expense') }}</dt>
                <dd>{{ optional($asset->depreciationExpenseAccount)->code }} {{ optional($asset->depreciationExpenseAccount)->name ? '— '.$asset->depreciationExpenseAccount->name : '—' }}</dd>
            </dl>
        </div>

        <div class="rounded-lg border border-border p-4">
            <flux:heading size="sm" class="mb-3">{{ __('Details') }}</flux:heading>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-muted-foreground">{{ __('Serial #') }}</dt>
                <dd class="font-mono">{{ $asset->serial_number ?? '—' }}</dd>
                <dt class="text-muted-foreground">{{ __('Location') }}</dt>
                <dd>{{ $asset->location ?? '—' }}</dd>
            </dl>
        </div>

        @if ($asset->useful_life_months)
            @php $dep = $this->depreciationData; @endphp
            <div class="rounded-lg border border-border p-4 md:col-span-2" data-test="asset-depreciation-card">
                <div class="mb-3 flex items-center justify-between">
                    <flux:heading size="sm">{{ __('Depreciation') }}</flux:heading>
                    @if ($asset->auto_depreciate)
                        <flux:badge color="green">{{ __('Auto-depreciation on') }}</flux:badge>
                    @else
                        <flux:badge color="zinc">{{ __('Auto-depreciation off') }}</flux:badge>
                    @endif
                </div>
                <dl class="mb-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">{{ __('Accumulated (as generated)') }}</dt>
                    <dd class="font-mono">{{ number_format($dep['accumulated_cents'] / 100, 2) }}</dd>
                    <dt class="text-muted-foreground">{{ __('Net book value') }}</dt>
                    <dd class="font-mono">{{ number_format(($asset->cost_cents - $dep['accumulated_cents']) / 100, 2) }}</dd>
                </dl>
                @if ($dep['rows'] !== [])
                    <div class="overflow-x-auto rounded-lg border border-border">
                        <table class="w-full text-sm" data-test="asset-depreciation-schedule">
                            <thead class="bg-muted">
                                <tr>
                                    <th class="px-4 py-2 text-left">{{ __('Month') }}</th>
                                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Journal entry') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($dep['rows'] as $row)
                                    <tr wire:key="dep-{{ $row['period']->format('Y-m') }}" data-test="asset-depreciation-row">
                                        <td class="px-4 py-2 whitespace-nowrap">{{ $row['period']->format('F Y') }}</td>
                                        <td class="px-4 py-2 text-right font-mono">{{ number_format($row['amount_cents'] / 100, 2) }}</td>
                                        <td class="px-4 py-2">
                                            @switch($row['status'])
                                                @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                                                @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                                                @case('voided') <flux:badge color="red">{{ __('Voided') }}</flux:badge> @break
                                                @case('locked') <flux:badge color="zinc">{{ __('Locked — record manually') }}</flux:badge> @break
                                                @default <flux:badge color="zinc" variant="outline">{{ __('Pending') }}</flux:badge>
                                            @endswitch
                                        </td>
                                        <td class="px-4 py-2">
                                            @if ($row['journal_entry_id'])
                                                <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $row['journal_entry_id']]) }}" wire:navigate class="underline">{{ __('View entry') }}</a>
                                            @else
                                                <span class="text-muted-foreground">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        @if ($asset->status->isRetired())
            <div class="rounded-lg border border-border p-4 md:col-span-2" data-test="asset-disposal-section">
                <flux:heading size="sm" class="mb-3">{{ __('Disposal') }}</flux:heading>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-muted-foreground">{{ __('Disposed at') }}</dt>
                    <dd>{{ optional($asset->disposed_at)->toDateString() ?? '—' }}</dd>
                    @if ($asset->disposal_notes)
                        <dt class="text-muted-foreground">{{ __('Notes') }}</dt>
                        <dd>{{ $asset->disposal_notes }}</dd>
                    @endif
                </dl>
            </div>
        @endif

        @if ($asset->notes)
            <div class="rounded-lg border border-border p-4 md:col-span-2">
                <flux:heading size="sm" class="mb-3">{{ __('Notes') }}</flux:heading>
                <flux:text>{{ $asset->notes }}</flux:text>
            </div>
        @endif
    </div>

    <div class="mt-6 space-y-3 rounded-lg border border-border p-4" data-test="asset-attachments">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @forelse ($this->attachments as $att)
            <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="att-{{ $att->id }}" data-test="asset-attachment-row">
                <x-attachment-link :attachment="$att" :company="$company" />
                <flux:button variant="ghost" size="sm" icon="x-mark"
                    wire:click="removeAttachment({{ $att->id }})"
                    wire:confirm="{{ __('Remove this attachment?') }}"
                    data-test="asset-attachment-remove" />
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
        @endforelse

        <x-attachment-dropzone model="newAttachments"
            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
            :description="__('PDF, images, or Office docs up to 10 MB each.')"
            data-test="asset-attachment-input" />

        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        @if (count($newAttachments) > 0)
            <flux:button variant="filled" wire:click="uploadAttachments" data-test="asset-attachment-upload">
                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
            </flux:button>
        @endif
    </div>
</section>
