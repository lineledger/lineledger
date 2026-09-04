<?php

use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Models\Attachment;
use App\Models\Cheque;
use App\Models\Company;
use App\Services\AttachmentService;
use App\Services\Posting\ChequePoster;
use App\Support\Contacts\ContactLinkResolver;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Cheque')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public Cheque $cheque;

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    public function mount(Company $company, Cheque $cheque): void
    {
        $this->company = $company;
        $this->cheque = $cheque->load('lines.account', 'lines.taxCode', 'lines.secondaryTaxCode', 'bankAccount', 'payee', 'journalEntry');
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->cheque, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        $service->remove(Attachment::findOrFail($id), $this->cheque);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Attachment>
     */
    #[Computed]
    public function attachments()
    {
        return $this->cheque->attachments()->get();
    }

    /**
     * The linked payee's home page (statement, employee editor, or all-time
     * transactions), or null for a free-text payee or a viewer who cannot
     * reach that page's section — the name then renders as plain text.
     */
    #[Computed]
    public function payeeUrl(): ?string
    {
        return $this->cheque->payee
            ? app(ContactLinkResolver::class)->urlForViewer($this->cheque->payee, $this->company, Auth::user())
            : null;
    }

    public function void(ChequePoster $poster): void
    {
        try {
            $poster->void($this->cheque);
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $this->company->jurisdiction->cheque('singular').' voided.');
        $this->redirectRoute('cheques.index', ['company' => $this->company->slug], navigate: true);
    }
}; ?>

<section class="w-full">
    @php($j = $company->jurisdiction)
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $j->cheque('singular') }} #{{ $cheque->cheque_no }}</flux:heading>
            <flux:subheading>
                @if ($this->payeeUrl)
                    <a href="{{ $this->payeeUrl }}" wire:navigate class="underline" data-test="cheque-payee-link">{{ $cheque->payee_name }}</a>
                @else
                    {{ $cheque->payee_name }}
                @endif
                &middot;
                {{ $cheque->cheque_date->toDateString() }} &middot;
                {{ $cheque->bankAccount->name }}
            </flux:subheading>
            <div class="mt-2 hidden items-center gap-2 lg:flex">
                @switch($cheque->status->value)
                    @case('draft') <flux:badge color="amber">{{ __('Draft') }}</flux:badge> @break
                    @case('posted') <flux:badge color="green">{{ __('Posted') }}</flux:badge> @break
                    @case('void') <flux:badge color="zinc">{{ __('Void') }}</flux:badge> @break
                @endswitch

                @if ($cheque->journal_entry_id)
                    <flux:badge color="zinc">
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $cheque->journal_entry_id]) }}" wire:navigate class="underline">
                            {{ __('GL entry') }} {{ optional($cheque->journalEntry)->entry_no }}
                        </a>
                    </flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($cheque->status === ChequeStatus::Draft)
                <flux:button variant="primary" class="!hidden lg:!inline-flex" :href="route('cheques.edit', ['company' => $company->slug, 'cheque' => $cheque->id])" wire:navigate data-test="edit-cheque-button">
                    {{ __('Edit') }}
                </flux:button>
            @endif

            <flux:dropdown align="end">
                <flux:button icon:trailing="chevron-down" data-test="cheque-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    @if ($cheque->status === ChequeStatus::Draft)
                        <flux:menu.item class="lg:hidden" icon="pencil" :href="route('cheques.edit', ['company' => $company->slug, 'cheque' => $cheque->id])" wire:navigate data-test="edit-cheque-menu-item">
                            {{ __('Edit') }}
                        </flux:menu.item>
                    @endif
                    @if ($cheque->status !== ChequeStatus::Void && filled($cheque->cheque_no))
                        <flux:menu.item icon="printer" :href="route('cheques.print', ['company' => $company->slug, 'cheque' => $cheque->id])" target="_blank" data-test="print-cheque-button">
                            {{ $j->chequeLabel('print') }}
                        </flux:menu.item>
                    @endif
                    @if ($cheque->status === ChequeStatus::Posted)
                        <flux:menu.separator />
                        <flux:menu.item icon="x-circle" variant="danger" wire:click="void" wire:confirm="{{ __('Void this :label? A reversing GL entry will be posted.', ['label' => mb_strtolower($j->cheque('singular'))]) }}" data-test="void-cheque-button">
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
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($cheque->lines as $line)
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
                        <td class="px-4 py-2 text-right">
                            @if (optional($line->account)->subtype === AccountSubtype::FixedAsset)
                                <flux:tooltip :content="__('Create asset record')">
                                    <flux:button
                                        variant="ghost"
                                        size="sm"
                                        icon="cube"
                                        :href="route('assets.create', ['company' => $company->slug, 'source_type' => 'cheque_line', 'source_id' => $line->id])"
                                        wire:navigate
                                        data-test="create-asset-from-cheque-line"
                                    />
                                </flux:tooltip>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                @foreach (\App\Support\Tax\LineTaxBreakdown::forLines($cheque->lines) as $taxRow)
                    <tr data-test="cheque-tax-row">
                        <td colspan="3" class="px-4 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                        <td colspan="2" class="px-4 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="text-base">
                    <td colspan="3" class="px-4 py-2 text-right font-semibold">{{ $j->cheque('singular') }} {{ __('amount') }}</td>
                    <td colspan="2" class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($cheque->amount_cents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($cheque->memo) <flux:text class="mt-4 text-muted-foreground">{{ $cheque->memo }}</flux:text> @endif

    {{-- Supporting documents --}}
    <div class="mt-6 space-y-3 rounded-lg border border-border p-4" data-test="cheque-attachments">
        <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

        @forelse ($this->attachments as $att)
            <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="cheque-att-{{ $att->id }}" data-test="cheque-attachment-row">
                <x-attachment-link :attachment="$att" :company="$company" />
                <flux:button variant="ghost" size="sm" icon="x-mark"
                    wire:click="removeAttachment({{ $att->id }})"
                    wire:confirm="{{ __('Remove this attachment?') }}"
                    data-test="cheque-attachment-remove" />
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('No attachments yet.') }}</flux:text>
        @endforelse

        <x-attachment-dropzone model="newAttachments"
            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
            :description="__('PDF, images, or Office docs up to 10 MB each.')"
            data-test="cheque-attachment-input" />

        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        @if (count($newAttachments) > 0)
            <flux:button variant="filled" wire:click="uploadAttachments" data-test="cheque-attachment-upload">
                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
            </flux:button>
        @endif
    </div>
</section>
