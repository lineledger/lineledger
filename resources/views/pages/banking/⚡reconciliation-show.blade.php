<?php

use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\JournalLine;
use App\Services\AttachmentService;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Reconciliation detail')] class extends Component {
    use WithFileUploads;

    public Company $company;

    public BankReconciliation $reconciliation;

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    public function mount(Company $company, BankReconciliation $reconciliation): void
    {
        $this->company = $company;
        $this->reconciliation = $reconciliation->load(
            'account',
            'serviceChargeAccount',
            'interestEarnedAccount',
            'completedBy',
            'serviceChargeEntry',
            'interestEarnedEntry',
        );
    }

    /**
     * @return array{
     *   payments: \Illuminate\Support\Collection<int, JournalLine>,
     *   deposits: \Illuminate\Support\Collection<int, JournalLine>,
     *   payments_total_cents: int,
     *   deposits_total_cents: int,
     * }
     */
    #[Computed]
    public function detail(): array
    {
        $lines = JournalLine::query()
            ->with('journalEntry')
            ->where('bank_reconciliation_id', $this->reconciliation->id)
            ->get()
            ->sortBy(fn ($l) => [$l->journalEntry->entry_date->format('Y-m-d'), $l->id])
            ->values();

        $payments = $lines->filter(fn ($l) => (int) $l->credit_cents > 0)->values();
        $deposits = $lines->filter(fn ($l) => (int) $l->debit_cents > 0)->values();

        return [
            'payments' => $payments,
            'deposits' => $deposits,
            'payments_total_cents' => (int) $payments->sum('credit_cents'),
            'deposits_total_cents' => (int) $deposits->sum('debit_cents'),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Attachment>
     */
    #[Computed]
    public function attachments()
    {
        return $this->reconciliation->attachments()->get();
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->reconciliation, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        $service->remove(Attachment::findOrFail($id), $this->reconciliation);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    public function clearedBalanceCents(): int
    {
        $d = $this->detail;

        return $this->reconciliation->beginning_balance_cents
            + $d['deposits_total_cents']
            - $d['payments_total_cents'];
    }

    public function differenceCents(): int
    {
        return $this->reconciliation->ending_balance_cents - $this->clearedBalanceCents();
    }

    public function exportCsv()
    {
        $rec = $this->reconciliation;
        $d = $this->detail;

        $rows = collect();

        $rows->push(['Account', $rec->account->code.' — '.$rec->account->name]);
        $rows->push(['Statement date', $rec->statement_date->toDateString()]);
        $rows->push(['Status', $rec->status->label()]);
        $rows->push(['Completed at', $rec->completed_at?->toDateTimeString() ?? '']);
        $rows->push(['Completed by', $rec->completedBy?->name ?? '']);
        $rows->push([]);
        $rows->push(['Summary']);
        $rows->push(['Beginning balance', '', '', '', '', CsvExporter::cents($rec->beginning_balance_cents)]);
        $rows->push(['Ending balance', '', '', '', '', CsvExporter::cents($rec->ending_balance_cents)]);
        $rows->push(['Service charge', '', '', '', '', CsvExporter::cents($rec->service_charge_cents)]);
        $rows->push(['Interest earned', '', '', '', '', CsvExporter::cents($rec->interest_earned_cents)]);
        $rows->push(['Cleared deposits ('.$d['deposits']->count().')', '', '', '', '', CsvExporter::cents($d['deposits_total_cents'])]);
        $rows->push(['Cleared payments ('.$d['payments']->count().')', '', '', '', '', CsvExporter::cents($d['payments_total_cents'])]);
        $rows->push(['Cleared balance', '', '', '', '', CsvExporter::cents($this->clearedBalanceCents())]);
        $rows->push(['Difference', '', '', '', '', CsvExporter::cents($this->differenceCents())]);

        $rows->push([]);
        $rows->push(['Deposits and Other Credits']);
        $rows->push(['Date', 'Entry #', 'Memo', '', '', 'Amount']);

        foreach ($d['deposits'] as $line) {
            $rows->push([
                $line->journalEntry->entry_date->toDateString(),
                $line->journalEntry->entry_no,
                $line->memo ?? $line->journalEntry->memo,
                '',
                '',
                CsvExporter::cents((int) $line->debit_cents),
            ]);
        }
        $rows->push(['Subtotal', '', '', '', '', CsvExporter::cents($d['deposits_total_cents'])]);

        $rows->push([]);
        $rows->push(['Cheques and Payments']);
        $rows->push(['Date', 'Entry #', 'Memo', '', '', 'Amount']);

        foreach ($d['payments'] as $line) {
            $rows->push([
                $line->journalEntry->entry_date->toDateString(),
                $line->journalEntry->entry_no,
                $line->memo ?? $line->journalEntry->memo,
                '',
                '',
                CsvExporter::cents((int) $line->credit_cents),
            ]);
        }
        $rows->push(['Subtotal', '', '', '', '', CsvExporter::cents($d['payments_total_cents'])]);

        return app(CsvExporter::class)->stream(
            $this->baseFilename().'.csv',
            ['Field', 'Detail', '', '', '', 'Amount'],
            $rows,
        );
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->bankReconciliation(
            $this->baseFilename().'.xlsx',
            $this->company,
            $this->reconciliation,
            $this->detail,
            $this->clearedBalanceCents(),
            $this->differenceCents(),
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reconciliations.show', [
            'company' => $this->company,
            'rec' => $this->reconciliation,
            'detail' => $this->detail,
            'clearedBalance' => $this->clearedBalanceCents(),
            'difference' => $this->differenceCents(),
        ], $this->baseFilename().'.pdf');
    }

    protected function baseFilename(): string
    {
        $accountSlug = str_replace(' ', '-', strtolower($this->reconciliation->account?->name ?? 'account'));

        return "reconciliation-{$this->reconciliation->account?->code}-{$accountSlug}-{$this->reconciliation->statement_date->toDateString()}";
    }
}; ?>

<section class="w-full">
    @php
        $rec = $this->reconciliation;
        $detail = $this->detail;
        $cleared = $this->clearedBalanceCents();
        $diff = $this->differenceCents();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="text-sm">
                <a href="{{ route('banking.reconcile', ['company' => $company->slug, 'account' => $rec->account_id]) }}" wire:navigate class="text-muted-foreground hover:underline">
                    ← {{ __('Back to reconciliations') }}
                </a>
            </div>
            <flux:heading size="xl" level="1" class="mt-1">{{ __('Reconciliation') }} #{{ $rec->id }}</flux:heading>
            <flux:subheading>
                {{ $rec->account?->code }} — {{ $rec->account?->name }}
                · {{ __('Statement') }} {{ $rec->statement_date->toFormattedDateString() }}
            </flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:badge :color="$rec->isCompleted() ? 'green' : 'amber'">{{ $rec->status->label() }}</flux:badge>

            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down">{{ __('Download') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    <flux:menu.item icon="table-cells" wire:click="exportXlsx">{{ __('Excel') }}</flux:menu.item>
                    <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    {{-- Summary card --}}
    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Beginning balance') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($rec->beginning_balance_cents / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Ending balance') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($rec->ending_balance_cents / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Cleared balance') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($cleared / 100, 2) }}</div>
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Difference') }}</flux:text>
            <div class="text-lg font-mono @if ($diff === 0) text-green-600 @else text-amber-600 @endif">
                {{ number_format($diff / 100, 2) }}
            </div>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Service charge') }}</flux:text>
            <div class="font-mono">
                {{ number_format($rec->service_charge_cents / 100, 2) }}
                @if ($rec->serviceChargeAccount)
                    <span class="text-xs text-muted-foreground"> · {{ $rec->serviceChargeAccount->code }}</span>
                @endif
            </div>
            @if ($rec->serviceChargeEntry)
                <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $rec->serviceChargeEntry->id]) }}" wire:navigate class="text-xs underline text-muted-foreground">
                    {{ $rec->serviceChargeEntry->entry_no }}
                </a>
            @endif
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Interest earned') }}</flux:text>
            <div class="font-mono">
                {{ number_format($rec->interest_earned_cents / 100, 2) }}
                @if ($rec->interestEarnedAccount)
                    <span class="text-xs text-muted-foreground"> · {{ $rec->interestEarnedAccount->code }}</span>
                @endif
            </div>
            @if ($rec->interestEarnedEntry)
                <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $rec->interestEarnedEntry->id]) }}" wire:navigate class="text-xs underline text-muted-foreground">
                    {{ $rec->interestEarnedEntry->entry_no }}
                </a>
            @endif
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Completed') }}</flux:text>
            <div class="text-sm">{{ $rec->completed_at?->toDateTimeString() ?? '—' }}</div>
            <div class="text-xs text-muted-foreground">{{ $rec->completedBy?->name }}</div>
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Items cleared') }}</flux:text>
            <div class="text-sm">
                {{ $detail['deposits']->count() }} {{ __('deposits') }} ·
                {{ $detail['payments']->count() }} {{ __('payments') }}
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([
            ['key' => 'deposits', 'title' => __('Deposits and Other Credits'), 'lines' => $detail['deposits'], 'col' => 'debit_cents', 'total' => $detail['deposits_total_cents']],
            ['key' => 'payments', 'title' => __('Cheques and Payments'), 'lines' => $detail['payments'], 'col' => 'credit_cents', 'total' => $detail['payments_total_cents']],
        ] as $pane)
            <div class="overflow-x-auto rounded-lg border border-border">
                <div class="border-b border-border bg-muted px-3 py-2">
                    <flux:heading size="sm">{{ $pane['title'] }}</flux:heading>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-muted text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('Entry') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('Memo') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($pane['lines'] as $line)
                            <tr>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $line->journalEntry->entry_date->toDateString() }}</td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $line->journalEntry->id]) }}" wire:navigate class="underline">{{ $line->journalEntry->entry_no }}</a>
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">{{ $line->memo ?? $line->journalEntry->memo }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format(((int) $line->{$pane['col']}) / 100, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-8 text-center text-muted-foreground">{{ __('Nothing cleared on this side.') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-border bg-muted font-semibold">
                            <td class="px-3 py-2" colspan="3">{{ __('Subtotal') }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format($pane['total'] / 100, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endforeach
    </div>

    {{-- Statement & supporting documents --}}
    <div class="mt-6 space-y-3 rounded-lg border border-border p-4" data-test="reconciliation-attachments">
        <flux:heading size="sm">{{ __('Statement & documents') }}</flux:heading>

        @forelse ($this->attachments as $att)
            <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="rec-att-{{ $att->id }}" data-test="reconciliation-attachment-row">
                <x-attachment-link :attachment="$att" :company="$company" />
                <flux:button variant="ghost" size="sm" icon="x-mark"
                    wire:click="removeAttachment({{ $att->id }})"
                    wire:confirm="{{ __('Remove this attachment?') }}"
                    data-test="reconciliation-attachment-remove" />
            </div>
        @empty
            <flux:text class="text-sm text-muted-foreground">{{ __('Attach the bank statement or other supporting files for this reconciliation.') }}</flux:text>
        @endforelse

        <x-attachment-dropzone model="newAttachments"
            accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
            :description="__('PDF, images, or Office docs up to 10 MB each.')"
            data-test="reconciliation-attachment-input" />

        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

        @if (count($newAttachments) > 0)
            <flux:button variant="filled" wire:click="uploadAttachments" data-test="reconciliation-attachment-upload">
                {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
            </flux:button>
        @endif
    </div>
</section>
