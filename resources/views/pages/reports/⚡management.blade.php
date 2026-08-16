<?php

use App\Models\Company;
use App\Models\ReportPackage;
use App\Services\Reporting\Render\ManagementReportBuilder;
use App\Support\Reporting\RenderableReports;
use App\Support\Reporting\ReportDatePresets;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Management Reports')] class extends Component {
    public Company $company;

    // --- Package modal state ---

    public ?int $editingId = null;

    public string $name = '';

    public string $title = '';

    public string $subtitle = '';

    public string $periodPreset = 'last_month';

    public bool $showCover = true;

    public bool $showLogo = true;

    public bool $showToc = true;

    public string $preliminaryText = '';

    public string $endNotes = '';

    /** @var list<string> report keys, in package order */
    public array $editItems = [];

    public string $newItemKey = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return Collection<int, ReportPackage>
     */
    #[Computed]
    public function packages(): Collection
    {
        return ReportPackage::query()
            ->where('user_id', Auth::id())
            ->with('items')
            ->orderBy('name')
            ->get();
    }

    /**
     * PDF-capable reports offered by the item picker, key => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function reportOptions(): array
    {
        $tracksMembership = $this->company->tracksMembership();

        return collect(RenderableReports::all())
            ->filter(fn (array $entry): bool => in_array('pdf', $entry['formats'], true))
            // The membership list is only meaningful — and only renders (it 403s
            // otherwise) — for companies that track membership.
            ->reject(fn (array $entry, string $key): bool => $key === 'reports.membership-roster' && ! $tracksMembership)
            ->map(fn (array $entry): string => $entry['label'])
            ->all();
    }

    /**
     * Period presets for the package. 'custom' is excluded — a package has no
     * date inputs of its own; the preset re-resolves at every download.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function presetOptions(): array
    {
        return collect(ReportDatePresets::options())->except('custom')->all();
    }

    public function itemLabel(string $reportKey): string
    {
        return $this->reportOptions[$reportKey] ?? $reportKey;
    }

    public function openCreate(): void
    {
        $this->resetForm();

        Flux::modal('package-form')->show();
    }

    public function openEdit(int $id): void
    {
        $package = ReportPackage::query()->where('user_id', Auth::id())->with('items')->find($id);

        if ($package === null) {
            return;
        }

        $this->resetForm();
        $this->editingId = $package->id;
        $this->name = $package->name;
        $this->title = (string) $package->title;
        $this->subtitle = (string) $package->subtitle;
        $this->periodPreset = $package->period_preset;
        $this->showCover = $package->show_cover;
        $this->showLogo = $package->show_logo;
        $this->showToc = $package->show_toc;
        $this->preliminaryText = (string) $package->preliminary_text;
        $this->endNotes = (string) $package->end_notes;
        $this->editItems = $package->items->pluck('report_key')->all();

        Flux::modal('package-form')->show();
    }

    public function addItem(): void
    {
        if (! array_key_exists($this->newItemKey, $this->reportOptions)
            || count($this->editItems) >= ManagementReportBuilder::MAX_ITEMS) {
            return;
        }

        $this->editItems[] = $this->newItemKey;
        $this->newItemKey = '';
    }

    public function removeItem(int $index): void
    {
        unset($this->editItems[$index]);
        $this->editItems = array_values($this->editItems);
    }

    public function moveItemUp(int $index): void
    {
        if ($index < 1 || $index >= count($this->editItems)) {
            return;
        }

        [$this->editItems[$index - 1], $this->editItems[$index]] = [$this->editItems[$index], $this->editItems[$index - 1]];
    }

    public function moveItemDown(int $index): void
    {
        $this->moveItemUp($index + 1);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'periodPreset' => ['required', Rule::in(array_keys($this->presetOptions))],
            'preliminaryText' => ['nullable', 'string', 'max:10000'],
            'endNotes' => ['nullable', 'string', 'max:10000'],
        ]);

        $this->resetErrorBag('editItems');

        $items = array_values(array_filter(
            $this->editItems,
            fn (string $key): bool => array_key_exists($key, $this->reportOptions),
        ));

        if (count($items) > ManagementReportBuilder::MAX_ITEMS) {
            $this->addError('editItems', __('A package can include at most :max reports.', ['max' => ManagementReportBuilder::MAX_ITEMS]));

            return;
        }

        $attributes = [
            'company_id' => $this->company->id,
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'title' => trim($this->title) !== '' ? trim($this->title) : null,
            'subtitle' => trim($this->subtitle) !== '' ? trim($this->subtitle) : null,
            'period_preset' => $validated['periodPreset'],
            'show_cover' => $this->showCover,
            'show_logo' => $this->showLogo,
            'show_toc' => $this->showToc,
            'preliminary_text' => trim($this->preliminaryText) !== '' ? $this->preliminaryText : null,
            'end_notes' => trim($this->endNotes) !== '' ? $this->endNotes : null,
        ];

        if ($this->editingId !== null) {
            $package = ReportPackage::query()->where('user_id', Auth::id())->find($this->editingId);

            if ($package === null) {
                return;
            }

            $package->update($attributes);
            $package->items()->delete();
        } else {
            $package = ReportPackage::create($attributes);
        }

        foreach ($items as $index => $reportKey) {
            $package->items()->create([
                'company_id' => $this->company->id,
                'report_key' => $reportKey,
                'sort_order' => $index,
            ]);
        }

        unset($this->packages);
        Flux::toast(variant: 'success', text: __('Package saved.'));
        Flux::modal('package-form')->close();
    }

    public function delete(int $id): void
    {
        ReportPackage::query()->where('user_id', Auth::id())->where('id', $id)->delete();
        unset($this->packages);
    }

    /**
     * Build the package into a single bundled PDF and stream it down.
     */
    public function download(int $id): ?BinaryFileResponse
    {
        $package = ReportPackage::query()->where('user_id', Auth::id())->with('items')->find($id);

        if ($package === null) {
            return null;
        }

        try {
            $artifact = app(ManagementReportBuilder::class)->build($package);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return null;
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'report-package-');
        file_put_contents($tmp, $artifact->bytes);

        return response()
            ->download($tmp, $artifact->filename, ['Content-Type' => $artifact->mime])
            ->deleteFileAfterSend(true);
    }

    protected function resetForm(): void
    {
        $this->resetErrorBag();
        $this->editingId = null;
        $this->name = '';
        $this->title = '';
        $this->subtitle = '';
        $this->periodPreset = 'last_month';
        $this->showCover = true;
        $this->showLogo = true;
        $this->showToc = true;
        $this->preliminaryText = '';
        $this->endNotes = '';
        $this->editItems = [];
        $this->newItemKey = '';
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Management Reports') }}</flux:heading>
            <flux:subheading>{{ __('Bundle reports into a single professional PDF with a cover page and table of contents.') }}</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:button :href="route('reports.index', ['company' => $company->slug])" wire:navigate variant="ghost" icon="arrow-left">{{ __('Report Center') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-package">{{ __('New package') }}</flux:button>
        </div>
    </div>

    @if ($this->packages->isEmpty())
        <flux:callout icon="document-duplicate" data-test="packages-empty">
            <flux:callout.heading>{{ __('No report packages yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Create a package to combine several reports for a period into one polished PDF — ideal for board meetings and month-end reviews.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-2 font-medium">{{ __('Period') }}</th>
                        <th class="px-4 py-2 font-medium">{{ __('Reports') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->packages as $package)
                        <tr class="border-t border-zinc-200 dark:border-zinc-700" wire:key="pkg-{{ $package->id }}" data-test="package-row">
                            <td class="px-4 py-2 font-medium">{{ $package->name }}</td>
                            <td class="px-4 py-2 text-zinc-500">{{ \App\Support\Reporting\ReportDatePresets::options()[$package->period_preset] ?? $package->period_preset }}</td>
                            <td class="px-4 py-2 text-zinc-500">
                                {{ $package->items->map(fn ($i) => $this->itemLabel($i->report_key))->implode(', ') ?: __('No reports') }}
                            </td>
                            <td class="px-4 py-2">
                                <div class="flex justify-end gap-1">
                                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray" wire:click="download({{ $package->id }})" data-test="download-package">{{ __('Download') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $package->id }})" data-test="edit-package">{{ __('Edit') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $package->id }})" wire:confirm="{{ __('Delete this package?') }}" data-test="delete-package">{{ __('Delete') }}</flux:button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <flux:modal name="package-form" class="max-w-xl">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $editingId !== null ? __('Edit package') : __('New package') }}</flux:heading>
            <flux:subheading>{{ __('The period re-resolves each time you download, so "Last Month" always covers the most recent month.') }}</flux:subheading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Package name')" required data-test="package-name" />
                <flux:select wire:model="periodPreset" :label="__('Period')" data-test="package-period">
                    @foreach ($this->presetOptions as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="title" :label="__('Cover title (optional)')" :placeholder="__('Defaults to the package name')" data-test="package-title" />
                <flux:input wire:model="subtitle" :label="__('Cover subtitle (optional)')" data-test="package-subtitle" />
            </div>

            <div class="flex flex-wrap gap-6">
                <flux:checkbox wire:model="showCover" :label="__('Cover page')" data-test="package-show-cover" />
                <flux:checkbox wire:model="showLogo" :label="__('Logo on cover')" data-test="package-show-logo" />
                <flux:checkbox wire:model="showToc" :label="__('Table of contents')" data-test="package-show-toc" />
            </div>

            <flux:fieldset>
                <flux:legend>{{ __('Reports') }}</flux:legend>

                @if ($editItems === [])
                    <flux:text class="mb-2">{{ __('No reports added yet.') }}</flux:text>
                @else
                    <ul class="mb-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($editItems as $index => $reportKey)
                            <li class="flex items-center justify-between gap-2 px-3 py-1.5" wire:key="item-{{ $index }}-{{ $reportKey }}" data-test="package-item">
                                <span class="text-sm">{{ $index + 1 }}. {{ $this->itemLabel($reportKey) }}</span>
                                <span class="flex gap-1">
                                    <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveItemUp({{ $index }})" :disabled="$index === 0" data-test="item-up" />
                                    <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveItemDown({{ $index }})" :disabled="$index === count($editItems) - 1" data-test="item-down" />
                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeItem({{ $index }})" data-test="item-remove" />
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <flux:error name="editItems" />

                <div class="flex items-end gap-2">
                    <flux:select wire:model="newItemKey" :label="__('Add a report')" data-test="package-new-item">
                        <flux:select.option value="">{{ __('Choose a report…') }}</flux:select.option>
                        @foreach ($this->reportOptions as $key => $label)
                            <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button wire:click="addItem" icon="plus" data-test="package-add-item">{{ __('Add') }}</flux:button>
                </div>
            </flux:fieldset>

            <flux:textarea wire:model="preliminaryText" :label="__('Preliminary text (optional)')" rows="3" :description="__('Appears on its own page after the cover, before the table of contents.')" data-test="package-preliminary" />
            <flux:textarea wire:model="endNotes" :label="__('End notes (optional)')" rows="3" :description="__('Appears on its own page after the last report.')" data-test="package-end-notes" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="package-save">{{ __('Save package') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
