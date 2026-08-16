<?php

use App\Actions\MasterData\SaveItemCategory;
use App\Livewire\Concerns\ImportsCsvList;
use App\Models\Company;
use App\Models\ItemCategory;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\Importers\ItemCategoriesImporter;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Item categories')] class extends Component {
    use ImportsCsvList;
    use WithFileUploads;

    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public ?int $f_parent_id = null;

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    protected function csvImporter(): CompanyCsvImporter
    {
        return app(ItemCategoriesImporter::class);
    }

    protected function csvImportModalName(): string
    {
        return 'item-category-import';
    }

    protected function csvImportLabelPlural(): string
    {
        return __('categories');
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_name', 'f_parent_id', 'f_is_active']);
        $this->f_is_active = true;
        Flux::modal('item-category-form')->show();
    }

    public function openEdit(int $id): void
    {
        $c = ItemCategory::findOrFail($id);
        $this->editingId = $c->id;
        $this->f_name = $c->name;
        $this->f_parent_id = $c->parent_id;
        $this->f_is_active = $c->is_active;
        Flux::modal('item-category-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_parent_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')->where('company_id', $this->company->id)],
            'f_is_active' => ['boolean'],
        ]);

        // A category cannot be its own parent.
        if ($this->editingId && (int) $validated['f_parent_id'] === $this->editingId) {
            $this->addError('f_parent_id', __('A category cannot be its own parent.'));

            return;
        }

        $editing = $this->editingId ? ItemCategory::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveItemCategory::class)->handle([
            'name' => $validated['f_name'],
            'parent_id' => $validated['f_parent_id'] ?: null,
            'is_active' => $validated['f_is_active'],
        ], $editing);

        Flux::modal('item-category-form')->close();
        Flux::toast(variant: 'success', text: __('Category saved.'));
    }

    #[Computed]
    public function categories()
    {
        return ItemCategory::query()->with('parent')->orderBy('name')->get();
    }

    #[Computed]
    public function parentOptions()
    {
        return ItemCategory::query()
            ->where('is_active', true)
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Item categories')" :subheading="__('Classify products & services to group and filter them on sales forms. QuickBooks calls these Categories.')">
        <div class="mb-4 flex justify-end gap-2">
            <flux:button icon="arrow-up-tray" wire:click="openImport" data-test="import-item-categories-button">{{ __('Import') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-item-category-button">{{ __('New category') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Parent') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->categories as $c)
                        <tr data-test="item-category-row" class="@if(! $c->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">{{ $c->name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ optional($c->parent)->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $c->id }})" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-muted-foreground">{{ __('No categories yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="item-category-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit category') : __('New category') }}</flux:heading>
            <flux:input wire:model="f_name" :label="__('Name')" required data-test="item-category-name" />
            <flux:select wire:model="f_parent_id" :label="__('Parent category (optional)')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->parentOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:switch wire:model="f_is_active" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="item-category-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-csv-import-modal
        name="item-category-import"
        :template-url="route('lists.template', ['company' => $company->slug, 'list' => 'item-categories'])"
        :subtitle="__('Bulk-add categories from a CSV. Names that already exist are skipped.')"
        :preview-rows="$importPreviewRows"
        :row-errors="$importErrors"
        :summary="$importSummary"
        :creatable-count="$this->importCreatableCount"
        :has-file="(bool) $importFile"
    >
        <x-slot:help>
            <p>{{ __('Columns: name, parent_name, is_active.') }}</p>
            <p class="mt-2">{{ __('To nest a category, put its parent\'s name in "parent_name" and list the parent before its children.') }}</p>
        </x-slot:help>
    </x-csv-import-modal>
</section>
