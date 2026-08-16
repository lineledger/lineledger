<?php

use App\Actions\Documents\SaveDocumentFolder;
use App\Models\Company;
use App\Models\DocumentFolder;
use App\Models\Membership;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documents')] class extends Component {
    public Company $company;

    public bool $showFolderModal = false;

    public string $folderName = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    protected function membership(): ?Membership
    {
        return Auth::user()?->companyMembership($this->company);
    }

    #[Computed]
    public function folders()
    {
        $membership = $this->membership();

        if ($membership === null) {
            return collect();
        }

        return DocumentFolder::query()
            ->whereNull('parent_folder_id')
            ->withCount(['children', 'attachments'])
            ->orderBy('name')
            ->get()
            ->filter(fn (DocumentFolder $folder) => $folder->isVisibleTo($membership))
            ->values();
    }

    public function openFolderModal(): void
    {
        $this->folderName = '';
        $this->showFolderModal = true;
    }

    public function createFolder(SaveDocumentFolder $action): void
    {
        $this->validate(['folderName' => ['required', 'string', 'max:255']]);

        $membership = $this->membership();
        abort_unless($membership !== null, 403);

        $action->handle(['name' => $this->folderName, 'parent_folder_id' => null], null, $membership);

        $this->showFolderModal = false;
        $this->folderName = '';
        unset($this->folders);

        Flux::toast(variant: 'success', text: __('Folder created.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Documents') }}</flux:heading>
            <flux:subheading>{{ __('Your company document repository.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button icon="paper-clip" :href="route('documents.attached-index', ['company' => $company->slug])" wire:navigate data-test="attachment-index-link">
                {{ __('Attachment index') }}
            </flux:button>
            <flux:button variant="primary" icon="folder-plus" wire:click="openFolderModal" data-test="new-folder-button">
                {{ __('New folder') }}
            </flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($this->folders as $folder)
            <a href="{{ route('documents.show', ['company' => $company->slug, 'folder' => $folder->id]) }}" wire:navigate
                class="flex items-start gap-3 rounded-lg border border-border p-4 hover:bg-muted"
                data-test="folder-card">
                <flux:icon name="folder" class="mt-0.5 size-6 text-amber-500" />
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ $folder->name }}</div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        {{ trans_choice(':count subfolder|:count subfolders', $folder->children_count, ['count' => $folder->children_count]) }}
                        &middot;
                        {{ trans_choice(':count file|:count files', $folder->attachments_count, ['count' => $folder->attachments_count]) }}
                    </div>
                    @if (! empty($folder->viewer_member_ids))
                        <flux:badge size="sm" color="zinc" class="mt-2">{{ __('Shared') }}</flux:badge>
                    @endif
                </div>
            </a>
        @empty
            <flux:text class="col-span-full block py-12 text-center text-muted-foreground">{{ __('No folders yet. Create one to start uploading documents.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="new-folder-modal" wire:model="showFolderModal" class="max-w-md">
        <form wire:submit="createFolder" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('New folder') }}</flux:heading>
                <flux:text>{{ __('Folders are private to you until you share them.') }}</flux:text>
            </div>

            <flux:input wire:model="folderName" :label="__('Folder name')" required data-test="folder-name-input" />

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showFolderModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="create-folder-submit">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
