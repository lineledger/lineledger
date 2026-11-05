<?php

use App\Actions\MasterData\SavePaymentMethod;
use App\Enums\JurisdictionCapability;
use App\Livewire\Attributes\RequiresSave;
use App\Livewire\Concerns\HoldsEditLock;
use App\Models\Company;
use App\Models\PaymentMethod;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payment methods')] class extends Component {
    use HoldsEditLock;

    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public bool $f_is_cheque = false;

    public bool $f_is_card = false;

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->releaseEditLock();
        $this->reset(['editingId', 'f_name', 'f_is_cheque', 'f_is_card', 'f_is_active']);
        $this->f_is_active = true;
        Flux::modal('payment-methods-form')->show();
    }

    public function openEdit(int $id): void
    {
        $m = PaymentMethod::findOrFail($id);

        if (! $this->acquireEditLock($m, reopen: 'openEdit', reopenArgs: [$id])) {
            return;
        }

        $this->editingId = $m->id;
        $this->f_name = $m->name;
        $this->f_is_cheque = $m->is_cheque;
        $this->f_is_card = $m->is_card;
        $this->f_is_active = $m->is_active;
        Flux::modal('payment-methods-form')->show();
    }

    #[RequiresSave('editingId')]
    public function save(): void
    {
        if ($this->editingId !== null && ! $this->ensureEditLockForSave(PaymentMethod::class, $this->editingId)) {
            return;
        }

        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_is_cheque' => ['boolean'],
            'f_is_card' => ['boolean'],
            'f_is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? PaymentMethod::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        $data = [
            'name' => $validated['f_name'],
            'is_cheque' => $validated['f_is_cheque'],
            'is_active' => $validated['f_is_active'],
        ];

        // Only a US organization sees the card switch; elsewhere a save leaves the flag as it was.
        if ($this->showsCardFlag()) {
            $data['is_card'] = $validated['f_is_card'];
        }

        app(SavePaymentMethod::class)->handle($data, $editing);

        $this->completeEditLockSave();
        Flux::modal('payment-methods-form')->close();
        Flux::toast(variant: 'success', text: __('Payment method saved.'));
    }

    #[Computed]
    public function methods()
    {
        return PaymentMethod::query()->orderBy('name')->get();
    }

    /**
     * The card flag keeps card and payment-app payments out of the 1099 Summary,
     * a US-only report, so only a US organization is shown it.
     */
    #[Computed]
    public function showsCardFlag(): bool
    {
        return $this->company->supports(JurisdictionCapability::Form1099);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    @php($j = $company->jurisdiction)
    <x-pages::settings.layout :heading="__('Payment methods')" :subheading="__('Used on receipts and bill payments. Mark a method as :label to enable :label printing.', ['label' => mb_strtolower($j->cheque('singular'))])">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-method-button">{{ __('New method') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-center">{{ $j->cheque('singular') }}</th>
                        @if ($this->showsCardFlag)
                            <th class="px-4 py-2 text-center">{{ __('Card or app') }}</th>
                        @endif
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($this->methods as $m)
                        <tr data-test="method-row" class="@if(! $m->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">{{ $m->displayName() }}</td>
                            <td class="px-4 py-2 text-center">
                                @if ($m->is_cheque)
                                    <flux:icon name="check" class="mx-auto size-4 text-emerald-600" />
                                @endif
                            </td>
                            @if ($this->showsCardFlag)
                                <td class="px-4 py-2 text-center">
                                    @if ($m->is_card)
                                        <flux:icon name="check" class="mx-auto size-4 text-emerald-600" />
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $m->id }})" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="payment-methods-form" class="max-w-lg" wire:close="releaseEditLock">
        <form wire:submit="save" class="space-y-6">
            @if ($editLockToken)
                <x-edit-lock.keeper :config="$this->editLockKeeper" :token="$editLockToken" />
            @endif
            <flux:heading size="lg">{{ $editingId ? __('Edit payment method') : __('New payment method') }}</flux:heading>
            <flux:input wire:model="f_name" :label="__('Name')" required data-test="method-name" />
            <flux:switch wire:model="f_is_cheque" :label="$j->chequeLabel('method')" :description="__('Enables the :action action on bill payments using this method.', ['action' => $j->chequeLabel('print')])" data-test="method-is-cheque" />
            @if ($this->showsCardFlag)
                <flux:switch wire:model="f_is_card" :label="__('Card or payment app')" :description="__('A credit, debit or prepaid card, or a payment app such as PayPal or Venmo. The card company or app reports these payments on Form 1099-K, so the 1099 Summary leaves them out.')" data-test="method-is-card" />
            @endif
            @if ($editingId)
                <div>
                    <flux:label>{{ __('Payment Method ID (API)') }}</flux:label>
                    <div class="mt-1 font-mono text-sm text-muted-foreground">{{ $editingId }}</div>
                </div>
            @endif
            <flux:switch wire:model="f_is_active" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="method-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-edit-lock.takeover-modal :pending="$editLockPendingTakeover" />
</section>
