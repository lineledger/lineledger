<?php

use App\Actions\Contacts\ConvertOtherName;
use App\Actions\Contacts\SaveOtherName;
use App\Enums\Section;
use App\Models\Company;
use App\Models\Contact;
use App\Support\Contacts\ContactLinkResolver;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings → Lists → Other names: QuickBooks-style one-time payees. An Other
 * name is a Contact carrying only the is_other_name flag, so this page is a
 * role-filtered view of the contacts table rather than its own list. Rows
 * link to the all-time Transactions report (the name's only ledger home) and
 * offer the one-way Convert to vendor / customer / employee.
 */
new #[Title('Other names')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_display_name = '';

    public string $f_notes = '';

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_display_name', 'f_notes', 'f_is_active']);
        $this->f_is_active = true;
        $this->resetErrorBag();
        Flux::modal('other-names-form')->show();
    }

    public function openEdit(int $id): void
    {
        // Scoped to other names: this form must never rename a vendor or
        // customer that happens to share the contacts table.
        $contact = Contact::query()->otherNames()->findOrFail($id);
        $this->editingId = $contact->id;
        $this->f_display_name = $contact->display_name;
        $this->f_notes = (string) $contact->notes;
        $this->f_is_active = (bool) $contact->is_active;
        $this->resetErrorBag();
        Flux::modal('other-names-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_display_name' => ['required', 'string', 'max:255'],
            'f_notes' => ['nullable', 'string', 'max:5000'],
            'f_is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? Contact::query()->otherNames()->findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveOtherName::class)->handle([
            'display_name' => trim($validated['f_display_name']),
            'notes' => $validated['f_notes'] ?? '',
            'is_active' => $validated['f_is_active'],
        ], $editing);

        unset($this->otherNames);
        Flux::modal('other-names-form')->close();
        Flux::toast(variant: 'success', text: __('Other name saved.'));
    }

    /**
     * One-way promotion to a directory role. The contact keeps its id, so
     * every cheque, expense and journal line written to it stays linked; it
     * simply leaves this list for the Vendors / Customers / Employees page.
     */
    public function convert(int $id, string $role): void
    {
        $this->resetErrorBag('convert');

        $contact = Contact::findOrFail($id);

        // Mirror the menu: a valid role whose destination page the viewer
        // cannot open is refused server-side too, not just hidden.
        if (in_array($role, ConvertOtherName::ROLES, true) && ! $this->canConvertTo($role)) {
            $this->addError('convert', __('You don’t have access to the section that name would move to.'));

            return;
        }

        try {
            app(ConvertOtherName::class)->handle($contact, $role);
        } catch (ValidationException $e) {
            $this->addError('convert', collect($e->errors())->flatten()->first());

            return;
        }

        unset($this->otherNames);

        Flux::toast(variant: 'success', text: __(':name is now a :role.', [
            'name' => $contact->display_name,
            'role' => $this->roleNoun($role),
        ]));
    }

    public function canConvertTo(string $role): bool
    {
        return in_array($role, $this->convertibleRoles, true);
    }

    /**
     * The directory roles the viewer may promote an Other name to — one per
     * section they can reach, judged the way EnsureSectionAccess judges the
     * target page, so the menu never offers a conversion whose destination
     * the viewer could not open. Computed once per render, not per row.
     *
     * @return list<string>
     */
    #[Computed]
    public function convertibleRoles(): array
    {
        $viewer = Auth::user();

        $sections = [
            'is_vendor' => Section::Vendors,
            'is_customer' => Section::Customers,
            'is_employee' => Section::Employees,
        ];

        $roles = [];

        foreach ($sections as $role => $section) {
            if ($viewer?->canAccessSection($this->company, $section)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Soft warning when the entered display name matches another active
     * contact of any role (case-insensitive) — a same-named vendor matters
     * just as much. Informational only; duplicates remain saveable.
     */
    #[Computed]
    public function duplicateNameWarning(): ?string
    {
        $name = mb_strtolower(trim($this->f_display_name));

        if ($name === '') {
            return null;
        }

        $exists = Contact::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(display_name) = ?', [$name])
            ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
            ->exists();

        return $exists ? __('Another contact already uses this name.') : null;
    }

    #[Computed]
    public function otherNames()
    {
        return Contact::query()->otherNames()->orderBy('display_name')->get();
    }

    protected function roleNoun(string $role): string
    {
        return match ($role) {
            'is_vendor' => __('vendor'),
            'is_customer' => __('customer'),
            'is_employee' => __('employee'),
            default => __('contact'),
        };
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Other names')" :subheading="__('One-time payees for cheques and expenses that aren’t vendors, customers or employees. QuickBooks calls these Other Names.')" contentClass="max-w-3xl">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-other-name-button">{{ __('New other name') }}</flux:button>
        </div>

        @error('convert')
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4" data-test="other-name-convert-error">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror

        @php($resolver = app(ContactLinkResolver::class))
        @php($viewer = Auth::user())
        @php($canConvertVendor = $this->canConvertTo('is_vendor'))
        @php($canConvertCustomer = $this->canConvertTo('is_customer'))
        @php($canConvertEmployee = $this->canConvertTo('is_employee'))
        @php($canConvert = $canConvertVendor || $canConvertCustomer || $canConvertEmployee)

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->otherNames as $c)
                        @php($transactionsUrl = $resolver->transactionsUrlForViewer($c, $company, $viewer))
                        <tr data-test="other-name-row" class="@if(! $c->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium" data-test="other-name-display-name">{{ $c->display_name }}</span>
                                    @if (! $c->is_active)
                                        <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                    @endif
                                </div>
                                @if ($c->notes)
                                    <div class="mt-0.5 text-xs text-muted-foreground">{{ $c->notes }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($transactionsUrl)
                                        <flux:button variant="ghost" size="sm" icon="list-bullet" :href="$transactionsUrl" wire:navigate data-test="other-name-transactions">{{ __('Transactions') }}</flux:button>
                                    @endif
                                    <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $c->id }})" data-test="other-name-edit" />
                                    @if ($canConvert)
                                        <flux:dropdown align="end">
                                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" data-test="other-name-actions-button" />
                                            <flux:menu>
                                                @if ($canConvertVendor)
                                                    <flux:menu.item icon="building-storefront" wire:click="convert({{ $c->id }}, 'is_vendor')" wire:confirm="{{ __('Convert “:name” to a vendor? This can’t be undone; its cheques and expenses stay linked.', ['name' => $c->display_name]) }}" data-test="other-name-convert-vendor">{{ __('Convert to vendor') }}</flux:menu.item>
                                                @endif
                                                @if ($canConvertCustomer)
                                                    <flux:menu.item icon="user" wire:click="convert({{ $c->id }}, 'is_customer')" wire:confirm="{{ __('Convert “:name” to a customer? This can’t be undone; its cheques and expenses stay linked.', ['name' => $c->display_name]) }}" data-test="other-name-convert-customer">{{ __('Convert to customer') }}</flux:menu.item>
                                                @endif
                                                @if ($canConvertEmployee)
                                                    <flux:menu.item icon="identification" wire:click="convert({{ $c->id }}, 'is_employee')" wire:confirm="{{ __('Convert “:name” to an employee? This can’t be undone; its cheques and expenses stay linked.', ['name' => $c->display_name]) }}" data-test="other-name-convert-employee">{{ __('Convert to employee') }}</flux:menu.item>
                                                @endif
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="px-4 py-6 text-center text-muted-foreground">{{ __('No other names yet. Add one here, or from the “Pay to the order of” field on a cheque.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="other-names-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit other name') : __('New other name') }}</flux:heading>
            <div>
                <flux:input wire:model.live.debounce.500ms="f_display_name" :label="__('Name')" :description="__('Printed on cheques as the payee.')" required data-test="other-name-name" />
                @if ($this->duplicateNameWarning)
                    <flux:text class="mt-1 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400" data-test="duplicate-name-warning">
                        <flux:icon name="exclamation-triangle" class="size-4 shrink-0" />
                        {{ $this->duplicateNameWarning }}
                    </flux:text>
                @endif
            </div>
            <flux:textarea wire:model="f_notes" :label="__('Notes')" :description="__('Internal only — never printed.')" rows="3" data-test="other-name-notes" />
            <flux:switch wire:model="f_is_active" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="other-name-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
