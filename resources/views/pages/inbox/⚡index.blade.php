<?php

use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Jobs\Inbox\ProcessInboxItem;
use App\Models\Company;
use App\Models\InboxItem;
use App\Services\AttachmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Inbox')] class extends Component {
    use WithFileUploads;

    public Company $company;

    /** @var array<int, mixed> */
    public array $uploads = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * Stage each dropped file as an inbox item, attach the file to it, then
     * queue OCR. The item shows immediately (Pending) and the list polls.
     */
    public function save(AttachmentService $attachments): void
    {
        $this->validate(AttachmentService::uploadRules(
            'uploads',
            (int) config('inbox.max_kilobytes', 25 * 1024),
            (array) config('inbox.allowed_extensions', ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif']),
        ));

        foreach ($this->uploads as $upload) {
            $item = InboxItem::create([
                'source' => InboxItemSource::Upload,
                'status' => InboxItemStatus::Pending,
                'original_filename' => $upload->getClientOriginalName(),
                'mime' => $upload->getMimeType() ?: null,
                'created_by_user_id' => Auth::id(),
            ]);

            $attachments->upload($item, [$upload], Auth::id());

            $attachment = $item->attachments()->first();
            $item->forceFill(['attachment_id' => $attachment?->id])->save();

            ProcessInboxItem::dispatch($item->id);
        }

        $this->uploads = [];
        unset($this->items);

        Flux::toast(variant: 'success', text: __('Documents received. Reading them now…'));
    }

    public function dismiss(int $id): void
    {
        $item = InboxItem::findOrFail($id);
        $item->forceFill(['status' => InboxItemStatus::Dismissed->value])->save();

        unset($this->items);

        Flux::toast(variant: 'success', text: __('Item dismissed.'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, InboxItem>
     */
    #[Computed]
    public function items()
    {
        return InboxItem::query()
            ->with('suggestedContact', 'attachment')
            ->whereNotIn('status', [InboxItemStatus::Dismissed->value])
            ->latest()
            ->get();
    }

    public function hasProcessing(): bool
    {
        return $this->items->contains(fn (InboxItem $item) => $item->status->isProcessing());
    }
}; ?>

<section class="w-full" @if ($this->hasProcessing()) wire:poll.3s @endif>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Inbox') }}</flux:heading>
            <flux:subheading>{{ __('Drop receipts and bills here. We read them and prep a draft for review.') }}</flux:subheading>
        </div>
    </div>

    <form wire:submit="save" class="mb-8">
        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="dragging = false; $wire.uploadMultiple('uploads', $event.dataTransfer.files, () => {}, () => {})"
            x-on:click="$refs.inboxInput.click()"
            :class="dragging ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/40' : 'border-border hover:bg-muted'"
            class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-10 text-center transition"
            data-test="inbox-dropzone">
            <flux:icon name="arrow-up-tray" class="size-8 text-muted-foreground" />
            <span class="font-medium">{{ __('Drag receipts here or click to choose files') }}</span>
            <span class="text-xs text-muted-foreground">{{ __('PDF, PNG, JPG, WEBP or GIF') }}</span>
            <input id="inbox-uploads" type="file" multiple class="hidden" wire:model="uploads"
                accept=".pdf,.png,.jpg,.jpeg,.webp,.gif" x-ref="inboxInput" data-test="inbox-file-input" />
        </div>

        @error('uploads.*')
            <flux:text class="mt-2 text-red-600">{{ $message }}</flux:text>
        @enderror

        <div wire:loading wire:target="uploads" class="mt-2">
            <flux:text class="text-muted-foreground">{{ __('Uploading…') }}</flux:text>
        </div>

        @if (! empty($uploads))
            <div class="mt-3 flex items-center justify-end">
                <flux:button type="submit" variant="primary" icon="inbox-arrow-down" data-test="inbox-upload-submit">
                    {{ __('Add :count to inbox', ['count' => count($uploads)]) }}
                </flux:button>
            </div>
        @endif
    </form>

    <div class="overflow-hidden rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-muted-foreground">
                <tr>
                    <th class="px-4 py-2 font-medium">{{ __('Document') }}</th>
                    <th class="px-4 py-2 font-medium">{{ __('Vendor') }}</th>
                    <th class="px-4 py-2 font-medium">{{ __('Total') }}</th>
                    <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->items as $item)
                    <tr data-test="inbox-row">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $item->original_filename ?? __('Document') }}</div>
                            <div class="text-xs text-muted-foreground">{{ $item->source->label() }}</div>
                        </td>
                        <td class="px-4 py-3">
                            {{ data_get($item->extracted, 'vendor') ?? $item->suggestedContact?->display_name ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @php($cents = data_get($item->extracted, 'amount_cents'))
                            {{ $cents !== null ? \App\Support\Money::fromCents((int) $cents, data_get($item->extracted, 'currency') ?? $company->currency_code)->format() : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @switch($item->status->value)
                                @case('pending')
                                @case('processing')
                                    <flux:badge size="sm" color="blue">{{ $item->status->label() }}</flux:badge>
                                    @break
                                @case('needs_review')
                                    <flux:badge size="sm" color="amber">{{ $item->status->label() }}</flux:badge>
                                    @break
                                @case('promoted')
                                    <flux:badge size="sm" color="green">{{ $item->status->label() }}</flux:badge>
                                    @break
                                @case('failed')
                                    <flux:badge size="sm" color="red">{{ $item->status->label() }}</flux:badge>
                                    @break
                                @default
                                    <flux:badge size="sm" color="zinc">{{ $item->status->label() }}</flux:badge>
                            @endswitch
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($item->status === \App\Enums\InboxItemStatus::NeedsReview)
                                    <flux:button size="sm" variant="primary"
                                        :href="route('inbox.show', ['company' => $company->slug, 'item' => $item->id])"
                                        wire:navigate data-test="inbox-review-link">
                                        {{ __('Review') }}
                                    </flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="x-mark"
                                    wire:click="dismiss({{ $item->id }})" data-test="inbox-dismiss">
                                    {{ __('Dismiss') }}
                                </flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">
                            {{ __('Your inbox is empty. Drop a receipt above to get started.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
