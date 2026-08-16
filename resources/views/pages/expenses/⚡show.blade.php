<?php

use App\Enums\ExpenseStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Expense;
use App\Services\AttachmentService;
use App\Services\Posting\ExpensePoster;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Expense')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public Expense $expense;

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    public function mount(Company $company, Expense $expense): void
    {
        $this->company = $company;
        $this->expense = $expense->load('lines.account', 'lines.taxCode', 'lines.secondaryTaxCode', 'paymentAccount', 'paymentMethod', 'payee', 'journalEntry');
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->expense, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        $service->remove(Attachment::findOrFail($id), $this->expense);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Attachment>
     */
    #[Computed]
    public function attachments()
    {
        return $this->expense->attachments()->get();
    }

    public function void(ExpensePoster $poster): void
    {
        try {
            $poster->void($this->expense);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Expense voided.'));
        $this->redirectRoute('expenses.index', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Expense') }}{{ $expense->reference ? ' #'.$expense->reference : '' }}</flux:heading>
            <flux:subheading>
                {{ $expense->payee_name }} &middot;
                {{ $expense->expense_date->toDateString() }} &middot;
                {{ $expense->paymentAccount->name }}@if ($expense->paymentMethod) &middot; {{ $expense->paymentMethod->name }} @endif
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($expense->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($expense->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $expense->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($expense->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($expense->status === ExpenseStatus::Draft)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" :href="route('expenses.edit', ['company' => $company->slug, 'expense' => $expense->id])" wire:navigate data-test="edit-expense-button">
                    {{ __('Edit') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="expense-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($expense->status === ExpenseStatus::Draft)
                        <flux:menu.item class="lg:hidden" icon="pencil" :href="route('expenses.edit', ['company' => $company->slug, 'expense' => $expense->id])" wire:navigate data-test="edit-expense-menu-item">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($expense->status === ExpenseStatus::Posted)
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this expense? A reversing GL entry will be posted.') }}" data-test="void-expense-button">
                            {{ __('Void') }}
                        </flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Tax') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Tax') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($expense->lines as $line)
                    <tr>
                        <td class="px-4 py-2">{{ optional($line->account)->code }} — {{ optional($line->account)->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $line->description }}</td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ optional($line->taxCode)->code }}
                            @if ($line->secondaryTaxCode)
                                <span class="block">{{ $line->secondaryTaxCode->code }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($line->amount_cents / 100, 2) }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ number_format($line->tax_cents / 100, 2) }}
                            @if ($line->secondary_tax_cents)
                                <span class="block">{{ number_format($line->secondary_tax_cents / 100, 2) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                @foreach (\App\Support\Tax\LineTaxBreakdown::forLines($expense->lines) as $taxRow)
                    <tr data-test="expense-tax-row">
                        <td colspan="3" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td colspan="2" class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="text-base">
                    <td colspan="3" class="px-4 py-2 text-right font-semibold">{{ __('Expense amount') }}</td>
                    <td colspan="2" class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($expense->amount_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($expense->memo) <flux:text class="mt-4 text-muted-foreground">{{ $expense->memo }}</flux:text> @endif

    {{-- Supporting documents --}}
    <div class="mt-6 space-y-3 rounded-lg border border-border p-4" data-test="expense-attachments">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @forelse ($this->attachments as $att)
            <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="expense-att-{{ $att->id }}" data-test="expense-attachment-row">
                <x-attachment-link :attachment="$att" :company="$company" />
                <flux:button variant="ghost" size="sm" icon="x-mark"
                    wire:click="removeAttachment({{ $att->id }})"
                    wire:confirm="{{ __('Remove this attachment?') }}"
                    data-test="expense-attachment-remove" />
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
        @endforelse

        <x-attachment-dropzone model="newAttachments"
            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
            :description="__('PDF, images, or Office docs up to 10 MB each.')"
            data-test="expense-attachment-input" />

        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        @if (count($newAttachments) > 0)
            <flux:button variant="filled" wire:click="uploadAttachments" data-test="expense-attachment-upload">
                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
            </flux:button>
        @endif
    </div>
</section>
