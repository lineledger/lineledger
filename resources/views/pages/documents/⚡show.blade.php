<?php

use App\Actions\Documents\DeleteDocumentFolder;
use App\Actions\Documents\SaveDocumentFolder;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\DocumentFolder;
use App\Models\Membership;
use App\Services\AttachmentService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Folder')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public DocumentFolder $folder;

    public bool $canManage = false;

    /** @var array<int, mixed> */
    public array $newFiles = [];

    public bool $showSubfolderModal = false;

    public string $subfolderName = '';

    public bool $showRenameModal = false;

    public string $renameName = '';

    public bool $showShareModal = false;

    /** @var array<int, int> */
    public array $shareMemberIds = [];

    public bool $showFileModal = false;

    public ?int $editingFileId = null;

    public string $editFileName = '';

    public string $editFileDescription = '';

    public function mount(Company $company, DocumentFolder $folder): void
    {
        $this->company = $company;
        $this->folder = $folder;

        $membership = $this->membership();
        abort_unless($membership !== null && $folder->isVisibleTo($membership), 403);

        $this->canManage = $folder->isManageableBy($membership);
    }

    protected function membership(): ?Membership
    {
        return Auth::user()?->companyMembership($this->company);
    }

    protected function requireManage(): Membership
    {
        $membership = $this->membership();
        abort_unless($membership !== null && $this->folder->isManageableBy($membership), 403);

        return $membership;
    }

    /**
     * Ancestor folders from root down to (but excluding) the current folder.
     * Each entry flags whether the viewer may open it, so restricted ancestor
     * names are not leaked.
     *
     * @return array<int, array{id: int, name: string, visible: bool}>
     */
    #[Computed]
    public function breadcrumb(): array
    {
        $membership = $this->membership();
        $chain = [];
        $cursor = $this->folder->parent;

        while ($cursor !== null) {
            $visible = $membership !== null && $cursor->isVisibleTo($membership);
            array_unshift($chain, [
                'id' => $cursor->id,
                'name' => $visible ? $cursor->name : __('Restricted'),
                'visible' => $visible,
            ]);
            $cursor = $cursor->parent;
        }

        return $chain;
    }

    #[Computed]
    public function subfolders()
    {
        $membership = $this->membership();

        if ($membership === null) {
            return collect();
        }

        return $this->folder->children()
            ->withCount(['children', 'attachments'])
            ->orderBy('name')
            ->get()
            ->filter(fn (DocumentFolder $child) => $child->isVisibleTo($membership))
            ->values();
    }

    #[Computed]
    public function files()
    {
        return $this->folder->attachments()->with('uploadedBy')->get();
    }

    /**
     * Members who can be granted view access (everyone but the creator, who
     * always has it).
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed]
    public function memberOptions(): array
    {
        return Membership::query()
            ->where('company_id', $this->company->id)
            ->where('id', '!=', $this->folder->created_by_member_id)
            ->with('user')
            ->get()
            ->map(fn (Membership $m) => ['id' => $m->id, 'name' => $m->user?->name ?? __('Unknown member')])
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function uploadFiles(AttachmentService $service): void
    {
        $this->requireManage();

        $this->validate(AttachmentService::uploadRules('newFiles', AttachmentService::DOCUMENT_MAX_KILOBYTES, AttachmentService::DOCUMENT_EXTENSIONS));

        $service->upload($this->folder, $this->newFiles, Auth::id());

        $this->newFiles = [];
        unset($this->files);

        Flux::toast(variant: 'success', text: __('Documents uploaded.'));
    }

    public function removeFile(int $id, AttachmentService $service): void
    {
        $this->requireManage();

        $service->remove(Attachment::findOrFail($id), $this->folder);

        unset($this->files);

        Flux::toast(variant: 'success', text: __('Document removed.'));
    }

    public function openFileModal(int $id): void
    {
        $this->requireManage();

        $file = $this->folder->attachments()->findOrFail($id);

        $this->editingFileId = $file->id;
        $this->editFileName = $file->original_filename;
        $this->editFileDescription = (string) $file->description;
        $this->showFileModal = true;
    }

    public function saveFile(AttachmentService $service): void
    {
        $this->requireManage();
        $this->validate([
            'editFileName' => ['required', 'string', 'max:255'],
            'editFileDescription' => ['nullable', 'string', 'max:500'],
        ]);

        $service->updateMeta(
            Attachment::findOrFail($this->editingFileId),
            $this->folder,
            $this->editFileName,
            $this->editFileDescription,
        );

        $this->showFileModal = false;
        $this->editingFileId = null;
        unset($this->files);

        Flux::toast(variant: 'success', text: __('Document updated.'));
    }

    public function openSubfolderModal(): void
    {
        $this->requireManage();
        $this->subfolderName = '';
        $this->showSubfolderModal = true;
    }

    public function createSubfolder(SaveDocumentFolder $action): void
    {
        $membership = $this->requireManage();
        $this->validate(['subfolderName' => ['required', 'string', 'max:255']]);

        $action->handle(['name' => $this->subfolderName, 'parent_folder_id' => $this->folder->id], null, $membership);

        $this->showSubfolderModal = false;
        $this->subfolderName = '';
        unset($this->subfolders);

        Flux::toast(variant: 'success', text: __('Folder created.'));
    }

    public function openRenameModal(): void
    {
        $this->requireManage();
        $this->renameName = $this->folder->name;
        $this->showRenameModal = true;
    }

    public function rename(SaveDocumentFolder $action): void
    {
        $membership = $this->requireManage();
        $this->validate(['renameName' => ['required', 'string', 'max:255']]);

        $action->handle(['name' => $this->renameName], $this->folder, $membership);

        $this->folder->refresh();
        $this->showRenameModal = false;

        Flux::toast(variant: 'success', text: __('Folder renamed.'));
    }

    public function openShareModal(): void
    {
        $this->requireManage();
        $this->shareMemberIds = array_map('intval', $this->folder->viewer_member_ids ?? []);
        $this->showShareModal = true;
    }

    public function saveSharing(SaveDocumentFolder $action): void
    {
        $membership = $this->requireManage();

        $action->handle([
            'name' => $this->folder->name,
            'viewer_member_ids' => $this->shareMemberIds,
        ], $this->folder, $membership);

        $this->folder->refresh();
        $this->showShareModal = false;

        Flux::toast(variant: 'success', text: __('Sharing updated.'));
    }

    public function deleteFolder(DeleteDocumentFolder $action): void
    {
        $membership = $this->requireManage();
        $parentId = $this->folder->parent_folder_id;

        $action->handle($this->folder, $membership);

        Flux::toast(variant: 'success', text: __('Folder deleted.'));

        if ($parentId !== null) {
            $this->redirectRoute('documents.show', ['company' => $this->company->slug, 'folder' => $parentId], navigate: true);

            return;
        }

        $this->redirectRoute('documents.index', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<section class="w-full">
    <nav class="mb-2 flex flex-wrap items-center gap-1 text-sm text-muted-foreground" aria-label="{{ __('Breadcrumb') }}">
        <a href="{{ route('documents.index', ['company' => $company->slug]) }}" wire:navigate class="hover:underline">{{ __('Documents') }}</a>
        @foreach ($this->breadcrumb as $crumb)
            <flux:icon name="chevron-right" class="size-4" />
            @if ($crumb['visible'])
                <a href="{{ route('documents.show', ['company' => $company->slug, 'folder' => $crumb['id']]) }}" wire:navigate class="hover:underline">{{ $crumb['name'] }}</a>
            @else
                <span>{{ $crumb['name'] }}</span>
            @endif
        @endforeach
    </nav>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <flux:icon name="folder-open" class="size-7 text-amber-500" />
            <flux:heading size="xl" level="1">{{ $folder->name }}</flux:heading>
            @if (! empty($folder->viewer_member_ids))
                <flux:badge color="zinc">{{ __('Shared') }}</flux:badge>
            @endif
        </div>

        @if ($canManage)
            <div class="flex flex-wrap items-center gap-2">
                <flux:button icon="folder-plus" wire:click="openSubfolderModal" data-test="new-subfolder-button">{{ __('New subfolder') }}</flux:button>
                <flux:dropdown align="end">
                    <flux:button icon:trailing="chevron-down" data-test="folder-actions-menu">{{ __('Actions') }}</flux:button>
                    <flux:menu>
                        <flux:menu.item icon="pencil" wire:click="openRenameModal" data-test="rename-folder-button">{{ __('Rename') }}</flux:menu.item>
                        <flux:menu.item icon="user-group" wire:click="openShareModal" data-test="share-folder-button">{{ __('Share') }}</flux:menu.item>
                        <flux:menu.separator />
                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteFolder" wire:confirm="{{ __('Delete this folder and everything inside it? This cannot be undone.') }}" data-test="delete-folder-button">{{ __('Delete folder') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        @endif
    </div>

    {{-- Subfolders --}}
    @if ($this->subfolders->isNotEmpty())
        <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->subfolders as $child)
                <a href="{{ route('documents.show', ['company' => $company->slug, 'folder' => $child->id]) }}" wire:navigate
                    class="flex items-start gap-3 rounded-lg border border-border p-4 hover:bg-muted"
                    data-test="subfolder-card">
                    <flux:icon name="folder" class="mt-0.5 size-6 text-amber-500" />
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ $child->name }}</div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ trans_choice(':count file|:count files', $child->attachments_count, ['count' => $child->attachments_count]) }}
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Files --}}
    <div class="space-y-3 rounded-lg border border-border p-4" data-test="folder-files">
        <flux:heading size="sm">{{ __('Documents') }}</flux:heading>

        @forelse ($this->files as $file)
            <div class="flex items-center justify-between gap-2 rounded-md border border-border px-3 py-2" wire:key="file-{{ $file->id }}" data-test="document-row">
                <x-attachment-link :attachment="$file" :company="$company" />
                @if ($canManage)
                    <div class="flex shrink-0 items-center">
                        <flux:button variant="ghost" size="sm" icon="pencil-square"
                            wire:click="openFileModal({{ $file->id }})"
                            data-test="document-edit" />
                        <flux:button variant="ghost" size="sm" icon="x-mark"
                            wire:click="removeFile({{ $file->id }})"
                            wire:confirm="{{ __('Remove this document?') }}"
                            data-test="document-remove" />
                    </div>
                @endif
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('No documents in this folder yet.') }}</flux:text>
        @endforelse

        @if ($canManage)
            <flux:heading size="sm" class="pt-2">{{ __('Add documents') }}</flux:heading>
            <x-attachment-dropzone model="newFiles"
                accept=".pdf,image/*,.doc,.docx,.xls,.xlsx,.csv,.txt,.ppt,.pptx,.odt,.ods"
                :description="__('PDF, images, Office or text files up to 10 MB each.')"
                data-test="document-input" />

            @error('newFiles.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

            @if (count($newFiles) > 0)
                <flux:button variant="filled" wire:click="uploadFiles" data-test="document-upload">
                    {{ __('Upload :count file(s)', ['count' => count($newFiles)]) }}
                </flux:button>
            @endif
        @endif
    </div>

    {{-- New subfolder modal --}}
    <flux:modal name="subfolder-modal" wire:model="showSubfolderModal" class="max-w-md">
        <form wire:submit="createSubfolder" class="space-y-6">
            <flux:heading size="lg">{{ __('New subfolder') }}</flux:heading>
            <flux:input wire:model="subfolderName" :label="__('Folder name')" required data-test="subfolder-name-input" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showSubfolderModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="create-subfolder-submit">{{ __('Create') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Rename modal --}}
    <flux:modal name="rename-modal" wire:model="showRenameModal" class="max-w-md">
        <form wire:submit="rename" class="space-y-6">
            <flux:heading size="lg">{{ __('Rename folder') }}</flux:heading>
            <flux:input wire:model="renameName" :label="__('Folder name')" required data-test="rename-input" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showRenameModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="rename-submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Edit document (rename + description) modal --}}
    <flux:modal name="file-modal" wire:model="showFileModal" class="max-w-md">
        <form wire:submit="saveFile" class="space-y-6">
            <flux:heading size="lg">{{ __('Edit document') }}</flux:heading>
            <flux:input wire:model="editFileName" :label="__('File name')" required data-test="file-name-input" />
            <flux:textarea wire:model="editFileDescription" :label="__('Description')" rows="3"
                :placeholder="__('Optional note about this document…')" data-test="file-description-input" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showFileModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="file-save-submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Share modal --}}
    <flux:modal name="share-modal" wire:model="showShareModal" class="max-w-md">
        <form wire:submit="saveSharing" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Share folder') }}</flux:heading>
                <flux:text>{{ __('Choose who else may view this folder and its documents. Owners and admins always have access.') }}</flux:text>
            </div>

            <div class="space-y-2">
                @forelse ($this->memberOptions as $member)
                    <flux:checkbox wire:model="shareMemberIds" :value="$member['id']" :label="$member['name']" data-test="share-member-checkbox" />
                @empty
                    <flux:text class="text-sm text-muted-foreground">{{ __('No other members to share with.') }}</flux:text>
                @endforelse
            </div>

            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('showShareModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="share-submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
