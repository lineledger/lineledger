<?php

use App\Models\Company;
use App\Models\DeletedTransaction;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Access\CurrentViewer;
use App\Support\Access\PayrollDetail;
use App\Support\Reporting\SourceLinkResolver;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * QuickBooks Desktop's Voided/Deleted Transactions report: every transaction
 * voided or deleted in a period, newest first, by when it happened. A voided
 * one is still in the books (its entry and the void's reversal net to zero);
 * a deleted one is gone from them, and its row opens the record kept of it.
 */
new #[Title('Voided/Deleted Transactions')] class extends Component {
    public Company $company;

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    #[Url(as: 'show')]
    public string $show = 'all';

    public function mount(Company $company): void
    {
        $this->company = $company;

        if ($this->startDate === '') {
            $this->startDate = $company->currentDateTime()->startOfMonth()->toDateString();
        }

        if ($this->endDate === '') {
            $this->endDate = $company->currentDateTime()->toDateString();
        }
    }

    /**
     * @return list<array{action: string, when: CarbonImmutable, by: ?string, type: string, number: ?string, date: ?string, name: ?string, memo: ?string, amount_cents: int, url: ?string, reason: ?string}>
     */
    #[Computed]
    public function rows(): array
    {
        $start = CarbonImmutable::parse($this->startDate ?: 'today', $this->company->timezone ?: 'UTC')->startOfDay()->utc();
        $end = CarbonImmutable::parse($this->endDate ?: 'today', $this->company->timezone ?: 'UTC')->endOfDay()->utc();
        $rows = [];

        if ($this->show !== 'deleted') {
            $resolver = app(SourceLinkResolver::class);
            $viewer = CurrentViewer::permissions($this->company);
            $users = [];

            $voided = JournalEntry::query()
                ->with('lines.contact')
                ->whereNotNull('voided_at')
                ->whereBetween('voided_at', [$start, $end])
                ->orderByDesc('voided_at')
                ->get();

            foreach ($voided as $entry) {
                if (PayrollDetail::hidesEntry($entry, $viewer)) {
                    continue;
                }

                $userId = $entry->voided_by_user_id;
                $users[$userId] ??= $userId !== null ? User::query()->whereKey($userId)->value('name') : null;

                $rows[] = [
                    'action' => 'voided',
                    'when' => CarbonImmutable::parse($entry->voided_at),
                    'by' => $users[$userId],
                    'type' => $resolver->label($entry),
                    'number' => $entry->entry_no,
                    'date' => $entry->entry_date?->toDateString(),
                    'name' => $entry->lines->first(fn ($line) => $line->contact_id !== null)?->contact?->display_name,
                    'memo' => $entry->memo,
                    'amount_cents' => (int) $entry->lines->sum('debit_cents'),
                    'url' => $resolver->urlFor($entry, $this->company),
                    'reason' => null,
                ];
            }
        }

        if ($this->show !== 'voided') {
            $deleted = DeletedTransaction::query()
                ->with('deletedBy')
                ->whereBetween('created_at', [$start, $end])
                ->orderByDesc('created_at')
                ->get();

            foreach ($deleted as $record) {
                $rows[] = [
                    'action' => 'deleted',
                    'when' => CarbonImmutable::parse($record->created_at),
                    'by' => $record->deletedBy?->name,
                    'type' => $record->document_type->label(),
                    'number' => $record->document_number,
                    'date' => $record->document_date?->toDateString(),
                    'name' => $record->contact_name,
                    'memo' => $record->reason,
                    'amount_cents' => (int) $record->total_cents,
                    'url' => route('deleted-transactions.show', ['company' => $this->company->slug, 'deletedTransaction' => $record->id]),
                    'reason' => $record->reason,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => $b['when'] <=> $a['when']);

        return $rows;
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Voided/Deleted Transactions') }}</flux:heading>
        <flux:subheading>{{ __('Every transaction voided or deleted in the period, by when it happened. A deleted transaction is gone from your books; open it to see the record kept of it.') }}</flux:subheading>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input type="date" wire:model.live="startDate" :label="__('From')" class="max-w-[180px]" data-test="voided-deleted-start" />
        <flux:input type="date" wire:model.live="endDate" :label="__('To')" class="max-w-[180px]" data-test="voided-deleted-end" />
        <flux:select wire:model.live="show" :label="__('Show')" class="max-w-[220px]" data-test="voided-deleted-show">
            <flux:select.option value="all">{{ __('Voided and deleted') }}</flux:select.option>
            <flux:select.option value="voided">{{ __('Voided only') }}</flux:select.option>
            <flux:select.option value="deleted">{{ __('Deleted only') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full min-w-[56rem] text-sm" data-test="voided-deleted-table">
            <thead class="bg-muted text-left">
                <tr>
                    <th class="px-3 py-2">{{ __('Action') }}</th>
                    <th class="px-3 py-2">{{ __('Entered/Last modified') }}</th>
                    <th class="px-3 py-2">{{ __('By') }}</th>
                    <th class="px-3 py-2">{{ __('Type') }}</th>
                    <th class="px-3 py-2">{{ __('Num') }}</th>
                    <th class="px-3 py-2">{{ __('Date') }}</th>
                    <th class="px-3 py-2">{{ __('Name') }}</th>
                    <th class="px-3 py-2">{{ __('Memo') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Original amount') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Current amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    <tr class="border-t border-border" data-test="voided-deleted-row">
                        <td class="px-3 py-2">
                            @if ($row['action'] === 'deleted')
                                <flux:badge color="red" size="sm">{{ __('Deleted') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Voided') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['when']->setTimezone($company->timezone ?: 'UTC')->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2">{{ $row['by'] ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row['type'] }}</td>
                        <td class="px-3 py-2">
                            @if ($row['url'])
                                <a href="{{ $row['url'] }}" wire:navigate class="underline">{{ $row['number'] ?? '—' }}</a>
                            @else
                                {{ $row['number'] ?? '—' }}
                            @endif
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $row['date'] ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $row['name'] ?? '' }}</td>
                        <td class="px-3 py-2 text-muted-foreground">{{ $row['memo'] ?? '' }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ number_format($row['amount_cents'] / 100, 2) }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ number_format(0, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-3 py-6 text-center text-muted-foreground">{{ __('No transactions were voided or deleted in this period.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
