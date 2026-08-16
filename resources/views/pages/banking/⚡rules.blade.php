<?php

use App\Actions\Banking\SaveBankRule;
use App\Enums\BankRuleMatchType;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\Company;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Bank rules')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public string $f_match_type = 'contains';

    public string $f_match_pattern = '';

    public ?int $f_action_account_id = null;

    public string $f_priority = '0';

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_name', 'f_match_type', 'f_match_pattern', 'f_action_account_id', 'f_priority', 'f_is_active']);
        $this->f_match_type = 'contains';
        $this->f_priority = '0';
        $this->f_is_active = true;
        Flux::modal('bank-rule-form')->show();
    }

    public function openEdit(int $id): void
    {
        $r = BankRule::findOrFail($id);
        $this->editingId = $r->id;
        $this->f_name = $r->name;
        $this->f_match_type = $r->match_type->value;
        $this->f_match_pattern = $r->match_pattern;
        $this->f_action_account_id = $r->action_account_id;
        $this->f_priority = (string) $r->priority;
        $this->f_is_active = $r->is_active;
        Flux::modal('bank-rule-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_match_type' => ['required', Rule::enum(BankRuleMatchType::class)],
            'f_match_pattern' => ['required', 'string', 'max:255'],
            'f_action_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'f_priority' => ['nullable', 'integer', 'min:0'],
            'f_is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? BankRule::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveBankRule::class)->handle([
            'name' => $validated['f_name'],
            'match_type' => $validated['f_match_type'],
            'match_pattern' => $validated['f_match_pattern'],
            'action_account_id' => $validated['f_action_account_id'],
            'priority' => (int) ($validated['f_priority'] ?: 0),
            'is_active' => $validated['f_is_active'],
        ], $editing);

        Flux::modal('bank-rule-form')->close();
        Flux::toast(variant: 'success', text: __('Bank rule saved.'));
    }

    #[Computed]
    public function rules()
    {
        return BankRule::query()->with('actionAccount')->orderBy('priority')->orderBy('name')->get();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function matchTypeOptions(): array
    {
        return array_map(fn (BankRuleMatchType $t) => ['value' => $t->value, 'label' => $t->label()], BankRuleMatchType::cases());
    }

    #[Computed]
    public function accountOptions()
    {
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->selectableForItemAccount()->where('is_active', true));
                if ($this->f_action_account_id) {
                    $q->orWhere('id', $this->f_action_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Bank rules') }}</flux:heading>
            <flux:subheading>{{ __('Automatically categorize imported bank transactions whose description matches a pattern.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-bank-rule-button">{{ __('New rule') }}</flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Priority') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('When description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Categorize to') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rules as $r)
                    <tr data-test="bank-rule-row" class="@if(! $r->is_active) opacity-50 @endif">
                        <td class="px-4 py-2 font-mono">{{ $r->priority }}</td>
                        <td class="px-4 py-2">{{ $r->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $r->match_type->label() }} “{{ $r->match_pattern }}”</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ optional($r->actionAccount)->code }} — {{ optional($r->actionAccount)->name }}</td>
                        <td class="px-4 py-2 text-right">
                            <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $r->id }})" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No bank rules yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="bank-rule-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit rule') : __('New rule') }}</flux:heading>

            <flux:input wire:model="f_name" :label="__('Rule name')" required data-test="bank-rule-name" />

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model="f_match_type" :label="__('Match')" data-test="bank-rule-match-type">
                    @foreach ($this->matchTypeOptions as $opt)
                        <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="f_match_pattern" :label="__('Pattern')" required data-test="bank-rule-pattern" />
            </div>

            <flux:select wire:model="f_action_account_id" :label="__('Categorize to account')" required data-test="bank-rule-account">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->accountOptions as $a)
                    <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="f_priority" type="number" :label="__('Priority')" :description="__('Lower numbers win first.')" />
                <div class="flex items-end"><flux:switch wire:model="f_is_active" :label="__('Active')" /></div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="bank-rule-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
