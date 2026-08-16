<?php

use App\Models\Company;
use App\Models\Member;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Membership Roster')] class extends Component {
    public Company $company;

    #[Url(as: 'active')]
    public bool $activeOnly = true;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->tracksMembership(), 403);
    }

    #[Computed]
    public function members()
    {
        return Member::query()
            ->with(['company', 'contact', 'level', 'invoices:id,member_id,status,total_cents,amount_paid_cents,reconciled_cents'])
            ->when($this->activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('member_no')
            ->get();
    }

    public function openDuesCents(Member $member): int
    {
        return $member->invoices
            ->filter(fn ($i) => $i->status->isOpen())
            ->sum(fn ($i) => max(0, $i->balanceCents()));
    }

    /**
     * Flat rows for the spreadsheet/PDF exports (and the package renderer).
     *
     * @return list<array{member_no: string, name: string, level: string, status: string, joined: string, expires: string, open_dues: int}>
     */
    private function exportRows(): array
    {
        return $this->members->map(fn (Member $member) => [
            'member_no' => (string) $member->member_no,
            'name' => $member->contact?->display_name ?? '',
            'level' => $member->level?->name ?? '',
            'status' => $member->effectiveStatus()->label(),
            'joined' => $member->joined_on?->format('Y-m-d') ?? '',
            'expires' => $member->expires_on?->format('Y-m-d') ?? '',
            'open_dues' => $this->openDuesCents($member),
        ])->all();
    }

    private function totalOpenDues(): int
    {
        return array_sum(array_column($this->exportRows(), 'open_dues'));
    }

    public function exportXlsx()
    {
        return app(XlsxExporter::class)->listTable(
            'membership-roster.xlsx',
            'Membership Roster',
            __('Membership Roster'),
            $this->company,
            [],
            ['Member #', 'Name', 'Level', 'Status', 'Joined', 'Expires', 'Open dues'],
            collect($this->exportRows())->map(fn (array $row) => [
                $row['member_no'], $row['name'], $row['level'], $row['status'],
                $row['joined'], $row['expires'], $row['open_dues'],
            ])->all(),
            moneyColumns: [7],
            columnWidths: [1 => 14, 2 => 28, 3 => 20, 4 => 14, 5 => 14, 6 => 14, 7 => 16],
            totals: ['Total', '', '', '', '', '', $this->totalOpenDues()],
        );
    }

    public function exportPdf()
    {
        return app(PdfExporter::class)->download('pdf.reports.list-table', [
            'company' => $this->company,
            'title' => __('Membership Roster'),
            'period' => null,
            'headers' => [
                ['label' => 'Member #'], ['label' => 'Name'], ['label' => 'Level'], ['label' => 'Status'],
                ['label' => 'Joined'], ['label' => 'Expires'], ['label' => 'Open dues', 'num' => true],
            ],
            'rows' => collect($this->exportRows())->map(fn (array $row) => [
                ['value' => $row['member_no']],
                ['value' => $row['name']],
                ['value' => $row['level']],
                ['value' => $row['status']],
                ['value' => $row['joined']],
                ['value' => $row['expires']],
                ['value' => number_format($row['open_dues'] / 100, 2), 'num' => true],
            ])->all(),
            'totals' => [
                ['value' => 'Total'], ['value' => ''], ['value' => ''], ['value' => ''],
                ['value' => ''], ['value' => ''],
                ['value' => number_format($this->totalOpenDues() / 100, 2), 'num' => true],
            ],
            'emptyMessage' => 'No members.',
        ], 'membership-roster.pdf');
    }
}; ?>

<section class="w-full">
    <x-reports.control-bar
        :title="__('Membership Roster')"
        :subtitle="$company->name"
        mode="none"
        :exports="['xlsx', 'pdf']"
    >
        <flux:checkbox wire:model.live="activeOnly" :label="__('Active only')" data-test="roster-active-only" />
    </x-reports.control-bar>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Member #') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Level') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Joined') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Expires') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Open dues') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->members as $member)
                    @php($status = $member->effectiveStatus())
                    <tr data-test="roster-row">
                        <td class="px-4 py-2">{{ $member->member_no }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('members.show', ['company' => $company, 'member' => $member]) }}" wire:navigate class="font-medium text-primary hover:underline" data-test="roster-member-link">{{ $member->contact?->display_name }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $member->level?->name ?? '—' }}</td>
                        <td class="px-4 py-2"><flux:badge size="sm" :color="$status->color()">{{ $status->label() }}</flux:badge></td>
                        <td class="px-4 py-2">{{ $member->joined_on?->format('M j, Y') ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $member->expires_on?->format('M j, Y') ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($this->openDuesCents($member), $company->currency_code) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-muted-foreground">{{ __('No members.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
