<?php

use App\Models\Company;
use App\Models\Contact;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Employees')] class extends Component {
    use WithPagination;
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showInactive = false;

    public ?int $editingId = null;

    public string $f_display_name = '';

    public string $f_first_name = '';

    public string $f_last_name = '';

    public string $f_email = '';

    public string $f_phone = '';

    public string $f_job_title = '';

    public string $f_employee_id = '';

    public string $f_billing_line1 = '';

    public string $f_billing_city = '';

    public string $f_billing_region = '';

    public string $f_billing_postal_code = '';

    public string $f_notes = '';

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->resetForm();
        Flux::modal('employee-form')->show();
    }

    public function openEdit(int $id): void
    {
        $c = Contact::findOrFail($id);

        $this->editingId = $c->id;
        $this->f_display_name = $c->display_name;
        $this->f_first_name = $c->first_name ?? '';
        $this->f_last_name = $c->last_name ?? '';
        $this->f_email = $c->email ?? '';
        $this->f_phone = $c->phone ?? '';
        $this->f_job_title = $c->job_title ?? '';
        $this->f_employee_id = $c->employee_id ?? '';
        $this->f_billing_line1 = $c->billing_line1 ?? '';
        $this->f_billing_city = $c->billing_city ?? '';
        $this->f_billing_region = $c->billing_region ?? '';
        $this->f_billing_postal_code = $c->billing_postal_code ?? '';
        $this->f_notes = $c->notes ?? '';
        $this->f_is_active = $c->is_active;

        Flux::modal('employee-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_display_name' => ['required', 'string', 'max:255'],
            'f_first_name' => ['nullable', 'string', 'max:100'],
            'f_last_name' => ['nullable', 'string', 'max:100'],
            'f_email' => ['nullable', 'email', 'max:255'],
            'f_phone' => ['nullable', 'string', 'max:50'],
            'f_job_title' => ['nullable', 'string', 'max:255'],
            'f_employee_id' => ['nullable', 'string', 'max:50'],
            'f_billing_line1' => ['nullable', 'string', 'max:255'],
            'f_billing_city' => ['nullable', 'string', 'max:120'],
            'f_billing_region' => ['nullable', 'string', 'max:120'],
            'f_billing_postal_code' => ['nullable', 'string', 'max:20'],
            'f_notes' => ['nullable', 'string'],
            'f_is_active' => ['boolean'],
        ]);

        $payload = [
            'display_name' => $validated['f_display_name'],
            'first_name' => $validated['f_first_name'] ?: null,
            'last_name' => $validated['f_last_name'] ?: null,
            'email' => $validated['f_email'] ?: null,
            'phone' => $validated['f_phone'] ?: null,
            'job_title' => $validated['f_job_title'] ?: null,
            'employee_id' => $validated['f_employee_id'] ?: null,
            'billing_line1' => $validated['f_billing_line1'] ?: null,
            'billing_city' => $validated['f_billing_city'] ?: null,
            'billing_region' => $validated['f_billing_region'] ?: null,
            'billing_postal_code' => $validated['f_billing_postal_code'] ?: null,
            'notes' => $validated['f_notes'] ?: null,
            'is_active' => $validated['f_is_active'],
        ];

        if ($this->editingId) {
            $contact = Contact::findOrFail($this->editingId);
            abort_unless($contact->company_id === $this->company->id, 403);
            $contact->update([...$payload, 'is_employee' => true]);
        } else {
            Contact::create([...$payload, 'is_employee' => true]);
        }

        Flux::modal('employee-form')->close();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Employee saved.'));
    }

    #[Computed]
    public function employees()
    {
        return Contact::query()
            ->where('is_employee', true)
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('display_name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            }))
            ->orderBy('display_name')
            ->paginate(25);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'f_display_name', 'f_first_name', 'f_last_name', 'f_email', 'f_phone',
            'f_job_title', 'f_employee_id', 'f_billing_line1', 'f_billing_city', 'f_billing_region',
            'f_billing_postal_code', 'f_notes',
        ]);
        $this->f_is_active = true;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Employees') }}</flux:heading>
            <flux:subheading>{{ __('Employees who submit expense reimbursements.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-employee-button">
            {{ __('New employee') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search name or email…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" />
    </div>

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->employees as $employee)
            <div wire:click="openEdit({{ $employee->id }})" class="block cursor-pointer rounded-lg border border-border p-4 @if(! $employee->is_active) opacity-50 @endif" data-test="employee-card">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ $employee->display_name }}</span>
                    <div class="text-right"><div class="font-mono font-semibold">{{ number_format($employee->ap_balance_cents / 100, 2) }}</div></div>
                </div>
                <div class="mt-1 text-sm text-muted-foreground">{{ $employee->email }}</div>
            </div>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No employees yet.') }}</flux:text>
        @endforelse
    </div>

    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Phone') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Owed to employee') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->employees as $employee)
                    <tr data-test="employee-row" class="@if(! $employee->is_active) opacity-50 @endif">
                        <td class="px-4 py-2">{{ $employee->display_name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $employee->email }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $employee->phone }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($employee->ap_balance_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right">
                            <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $employee->id }})" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No employees yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->employees->links() }}</div>

    <flux:modal name="employee-form" class="max-w-xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit employee') : __('New employee') }}</flux:heading>

            <flux:input wire:model="f_display_name" :label="__('Display name')" required data-test="employee-display-name" />

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="f_first_name" :label="__('First name')" />
                <flux:input wire:model="f_last_name" :label="__('Last name')" />
                <flux:input wire:model="f_email" type="email" :label="__('Email')" />
                <flux:input wire:model="f_phone" :label="__('Phone')" />
                <flux:input wire:model="f_job_title" :label="__('Job title')" />
                <flux:input wire:model="f_employee_id" :label="__('Employee ID')" :placeholder="__('e.g. EMP-014')" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="f_billing_line1" :label="__('Address')" class="md:col-span-2" />
                <flux:input wire:model="f_billing_city" :label="__('City')" />
                <flux:input wire:model="f_billing_region" :label="__('Province')" />
                <flux:input wire:model="f_billing_postal_code" :label="__('Postal code')" />
            </div>

            <flux:textarea wire:model="f_notes" :label="__('Notes')" rows="2" />

            <flux:switch wire:model="f_is_active" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="employee-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
