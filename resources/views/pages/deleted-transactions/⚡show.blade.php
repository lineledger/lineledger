<?php

use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\DeletedTransaction;
use App\Support\Access\CurrentViewer;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The record kept of a deleted transaction: who deleted it, when and why, and
 * the document and journal entries exactly as they stood, struck through. It
 * reads only the tombstone's self-contained snapshot, so it shows the same
 * after a backup is restored, when the ids inside the snapshot name nothing.
 */
new #[Title('Deleted transaction')] class extends Component {
    public Company $company;

    public DeletedTransaction $deletedTransaction;

    public function mount(Company $company, DeletedTransaction $deletedTransaction): void
    {
        $this->company = $company;

        // An employee's pay is shown only to members who can open pay runs,
        // as on the ledger (PayrollDetail).
        abort_if($deletedTransaction->document_type->isPayroll() && ! CurrentViewer::permissions($company)->seesPayrollDetail(), 404);

        $this->deletedTransaction = $deletedTransaction->load('deletedBy');
    }

    /**
     * The audit trail of the transaction: the deletion itself (whose auditable
     * is this record, so it survives a restore) and, before it, every event
     * recorded against the document while it existed.
     *
     * @return Collection<int, AccountingAuditLog>
     */
    #[Computed]
    public function auditRows(): Collection
    {
        $snapshot = $this->deletedTransaction->snapshot;

        return AccountingAuditLog::query()
            ->withoutGlobalScopes()
            ->with('actor')
            ->where('company_id', $this->company->id)
            ->where(function ($q) use ($snapshot): void {
                $q->where(fn ($q) => $q->where('auditable_type', DeletedTransaction::class)->where('auditable_id', $this->deletedTransaction->id));

                if (isset($snapshot['document_class'], $snapshot['document_id'])) {
                    $q->orWhere(fn ($q) => $q->where('auditable_type', $snapshot['document_class'])->where('auditable_id', (int) $snapshot['document_id']));
                }
            })
            ->orderBy('sequence')
            ->get();
    }
}; ?>

@php
    $record = $deletedTransaction;
    $snapshot = $record->snapshot;
    $document = $snapshot['document'] ?? [];
    $tz = $company->timezone ?: 'UTC';
@endphp

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ $record->title() }}</flux:heading>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <flux:badge color="red" icon="trash">{{ __('Deleted') }}</flux:badge>
            <flux:badge color="zinc">{{ __('Was :state', ['state' => $record->state_at_deletion->label()]) }}</flux:badge>
        </div>
    </div>

    <flux:callout variant="danger" icon="trash" class="mb-6" data-test="deleted-transaction-banner">
        <flux:callout.heading>
            {{ __('Deleted by :name on :when.', ['name' => $record->deletedBy?->name ?? __('someone no longer on file'), 'when' => $record->created_at?->setTimezone($tz)->format('Y-m-d H:i')]) }}
            @if ($record->deleted_via === 'api')
                {{ __('(through the API)') }}
            @endif
        </flux:callout.heading>
        <flux:callout.text>
            {{ __('It is no longer in your books or any report. This is the record kept of it, as it stood when it was deleted.') }}
            @if ($record->reason)
                <span class="block mt-1">{{ __('Reason: :reason', ['reason' => $record->reason]) }}</span>
            @endif
        </flux:callout.text>
    </flux:callout>

    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
            <flux:text class="text-muted-foreground">{{ __('Date') }}</flux:text>
            <div>{{ $record->document_date?->toDateString() ?? '—' }}</div>
        </div>
        <div>
            <flux:text class="text-muted-foreground">{{ __('Name') }}</flux:text>
            <div>{{ $record->contact_name ?? '—' }}</div>
        </div>
        <div>
            <flux:text class="text-muted-foreground">{{ __('Amount') }}</flux:text>
            <div class="font-mono">{{ $company->currencySymbol($record->currency_code) }}{{ number_format($record->total_cents / 100, 2) }}</div>
        </div>
        <div>
            <flux:text class="text-muted-foreground">{{ __('Memo') }}</flux:text>
            <div>{{ $document['memo'] ?? '' }}</div>
        </div>
    </div>

    @if (! empty($document['lines']))
        <flux:heading size="lg" class="mb-2">{{ __('Lines') }}</flux:heading>
        <div class="mb-6 overflow-x-auto rounded-lg border border-border">
            <table class="w-full min-w-[40rem] text-sm text-muted-foreground line-through decoration-1" data-test="deleted-transaction-lines">
                <thead class="bg-muted text-left no-underline">
                    <tr>
                        <th class="px-3 py-2">{{ __('Description') }}</th>
                        <th class="px-3 py-2">{{ __('Account') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Qty') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Price') }}</th>
                        <th class="px-3 py-2">{{ __('Tax') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($document['lines'] as $line)
                        <tr class="border-t border-border">
                            <td class="px-3 py-2">{{ trim(($line['item'] ?? '').' '.($line['description'] ?? '')) }}</td>
                            <td class="px-3 py-2">{{ trim(($line['account_code'] ?? '').' '.($line['account_name'] ?? '')) }}</td>
                            <td class="px-3 py-2 text-right">{{ $line['quantity'] ?? '' }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ isset($line['unit_price_cents']) ? number_format($line['unit_price_cents'] / 100, 2) : '' }}</td>
                            <td class="px-3 py-2">{{ trim(($line['tax_code'] ?? '').' '.($line['secondary_tax_code'] ?? '')) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ isset($line['line_total_cents']) ? number_format($line['line_total_cents'] / 100, 2) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($document['applications']))
        <flux:heading size="lg" class="mb-2">{{ __('Applied to') }}</flux:heading>
        <ul class="mb-6 list-disc ps-6 text-sm text-muted-foreground">
            @foreach ($document['applications'] as $application)
                <li>{{ $application['invoice_no'] }} — {{ number_format($application['amount_cents'] / 100, 2) }}</li>
            @endforeach
        </ul>
    @endif

    @foreach ($snapshot['journal_entries'] ?? [] as $entry)
        <flux:heading size="lg" class="mb-2">
            {{ $loop->first ? __('Journal entry :no', ['no' => $entry['entry_no']]) : __('Reversing entry :no', ['no' => $entry['entry_no']]) }}
            <span class="text-sm font-normal text-muted-foreground">{{ $entry['entry_date'] }}</span>
        </flux:heading>
        <div class="mb-6 overflow-x-auto rounded-lg border border-border">
            <table class="w-full min-w-[40rem] text-sm text-muted-foreground line-through decoration-1" data-test="deleted-transaction-journal">
                <thead class="bg-muted text-left">
                    <tr>
                        <th class="px-3 py-2">{{ __('Account') }}</th>
                        <th class="px-3 py-2">{{ __('Name') }}</th>
                        <th class="px-3 py-2">{{ __('Memo') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Debit') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entry['lines'] as $line)
                        <tr class="border-t border-border">
                            <td class="px-3 py-2">{{ trim(($line['account_code'] ?? '').' '.($line['account_name'] ?? '')) }}</td>
                            <td class="px-3 py-2">{{ $line['contact_name'] ?? '' }}</td>
                            <td class="px-3 py-2">{{ $line['memo'] ?? '' }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $line['debit_cents'] ? number_format($line['debit_cents'] / 100, 2) : '' }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $line['credit_cents'] ? number_format($line['credit_cents'] / 100, 2) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <flux:heading size="lg" class="mb-2">{{ __('Audit trail') }}</flux:heading>
    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm" data-test="deleted-transaction-audit">
            <thead class="bg-muted text-left">
                <tr>
                    <th class="px-3 py-2">{{ __('When') }}</th>
                    <th class="px-3 py-2">{{ __('Action') }}</th>
                    <th class="px-3 py-2">{{ __('By') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->auditRows as $row)
                    <tr class="border-t border-border">
                        <td class="px-3 py-2 whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($row->recorded_at)->setTimezone($tz)->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $row->action }}</td>
                        <td class="px-3 py-2">{{ $row->actor?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
