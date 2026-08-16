<?php

use App\Console\Commands\VerifyAccountingAuditCommand;
use App\Enums\AuditAction;
use App\Enums\SecurityEvent;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit Logs')] class extends Component {
    use WithPagination;

    public Company $company;

    #[Url(as: 'view')]
    public string $view = 'accounting';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'action')]
    public string $actionFilter = '';

    #[Url(as: 'event')]
    public string $eventFilter = '';

    #[Url(as: 'start')]
    public string $startDate = '';

    #[Url(as: 'end')]
    public string $endDate = '';

    public ?string $verifyStatus = null;

    public ?string $verifyOutput = null;

    public function mount(Company $company): void
    {
        $this->company = $company;

        if ($this->startDate === '') {
            $this->startDate = $this->company->currentDateTime()->subDays(30)->toDateString();
        }

        if ($this->endDate === '') {
            $this->endDate = $this->company->currentDateTime()->toDateString();
        }
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['accounting', 'security'], true) ? $view : 'accounting';
        $this->resetPage();
    }

    public function verifyChain(): void
    {
        // --no-alert: an on-demand check from the UI must not email ops; the
        // nightly integrity:check owns alerting.
        $exitCode = Artisan::call('audit:verify', ['company' => $this->company->id, '--no-alert' => true]);
        $this->verifyStatus = $exitCode === 0 ? 'ok' : 'broken';
        $this->verifyOutput = trim(Artisan::output());
    }

    #[Computed]
    public function accountingEntries()
    {
        $start = CarbonImmutable::parse($this->startDate)->startOfDay();
        $end = CarbonImmutable::parse($this->endDate)->endOfDay();

        return AccountingAuditLog::query()
            ->withoutGlobalScopes()
            ->with(['actor', 'apiKey'])
            ->where('company_id', $this->company->id)
            ->whereBetween('recorded_at', [$start, $end])
            ->when($this->actionFilter !== '', fn ($q) => $q->where('action', $this->actionFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('auditable_type', 'like', '%'.$this->search.'%')
                    ->orWhere('action', 'like', '%'.$this->search.'%')
                    ->orWhere('hash_input', 'like', '%'.$this->search.'%');
            }))
            ->orderByDesc('sequence')
            ->paginate(50);
    }

    #[Computed]
    public function securityEntries()
    {
        $start = CarbonImmutable::parse($this->startDate)->startOfDay();
        $end = CarbonImmutable::parse($this->endDate)->endOfDay();

        return SecurityLog::query()
            ->with('user')
            ->where('company_id', $this->company->id)
            ->whereBetween('recorded_at', [$start, $end])
            ->when($this->eventFilter !== '', fn ($q) => $q->where('event', $this->eventFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('event', 'like', '%'.$this->search.'%')
                    ->orWhere('ip_address', 'like', '%'.$this->search.'%')
                    ->orWhere('attempted_email', 'like', '%'.$this->search.'%');
            }))
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function actionOptions(): array
    {
        return array_map(fn (AuditAction $a) => ['value' => $a->value, 'label' => $a->value], AuditAction::cases());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function eventOptions(): array
    {
        return array_map(fn (SecurityEvent $e) => ['value' => $e->value, 'label' => $e->value], SecurityEvent::cases());
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Audit Logs') }}</flux:heading>
            <flux:subheading>{{ __('Immutable record of every accounting and security event.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if ($verifyStatus === 'ok')
                <flux:badge color="green">{{ __('Chain intact') }}</flux:badge>
            @elseif ($verifyStatus === 'broken')
                <flux:badge color="red">{{ __('Chain broken') }}</flux:badge>
            @endif

            <flux:button variant="outline" icon="shield-check" wire:click="verifyChain" data-test="verify-chain">
                {{ __('Verify chain') }}
            </flux:button>
        </div>
    </div>

    @if ($verifyOutput !== null)
        <div class="mb-4 rounded-md border border-border bg-muted p-3 text-xs">
            <pre class="whitespace-pre-wrap font-mono">{{ $verifyOutput }}</pre>
        </div>
    @endif

    <div class="mb-4 inline-flex rounded-md border border-border p-1" role="tablist">
        <flux:button
            size="sm"
            :variant="$view === 'accounting' ? 'primary' : 'ghost'"
            wire:click="setView('accounting')"
            data-test="view-accounting"
        >
            {{ __('Accounting') }}
        </flux:button>
        <flux:button
            size="sm"
            :variant="$view === 'security' ? 'primary' : 'ghost'"
            wire:click="setView('security')"
            data-test="view-security"
        >
            {{ __('Security') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input type="date" wire:model.live="startDate" :label="__('From')" class="max-w-[180px]" />
        <flux:input type="date" wire:model.live="endDate" :label="__('To')" class="max-w-[180px]" />

        @if ($view === 'accounting')
            <flux:select wire:model.live="actionFilter" :label="__('Action')" class="max-w-[260px]">
                <flux:select.option value="">{{ __('All actions') }}</flux:select.option>
                @foreach ($this->actionOptions as $opt)
                    <flux:select.option value="{{ $opt['value'] }}">{{ $opt['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        @else
            <flux:select wire:model.live="eventFilter" :label="__('Event')" class="max-w-[260px]">
                <flux:select.option value="">{{ __('All events') }}</flux:select.option>
                @foreach ($this->eventOptions as $opt)
                    <flux:select.option value="{{ $opt['value'] }}">{{ $opt['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search…')" icon="magnifying-glass" class="max-w-md" />
    </div>

    @if ($view === 'accounting')
        @php($rows = $this->accountingEntries)

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">#</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('Recorded') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('Action') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('Target') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('Actor') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('IP') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($rows as $row)
                        <tr data-test="audit-row">
                            <td class="px-3 py-2 font-mono text-xs">{{ $row->sequence }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $row->recorded_at->toDayDateTimeString() }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row->action->value }}</td>
                            <td class="px-3 py-2 text-xs">
                                {{ class_basename($row->auditable_type) }} #{{ $row->auditable_id }}
                            </td>
                            <td class="px-3 py-2">
                                @if ($row->apiKey)
                                    <div class="space-y-0.5">
                                        <flux:badge size="sm" color="indigo">{{ __('API') }}</flux:badge>
                                        <p class="text-xs">{{ $row->apiKey->label }}</p>
                                        <p class="font-mono text-[10px] text-muted-foreground">{{ $row->apiKey->prefix }}_…{{ $row->apiKey->last_four }}</p>
                                    </div>
                                @else
                                    {{ $row->actor?->name ?? __('System') }}
                                @endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row->actor_ip }}</td>
                            <td class="px-3 py-2">
                                <details>
                                    <summary class="cursor-pointer text-xs text-muted-foreground hover:underline">{{ __('Payload') }}</summary>
                                    <pre class="mt-2 max-h-64 max-w-2xl overflow-auto rounded bg-muted p-2 text-xs">{{ json_encode($row->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-muted-foreground">{{ __('No audit entries in this range.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $rows->links() }}</div>
    @else
        @php($rows = $this->securityEntries)

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">{{ __('Recorded') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('Event') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('User') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('IP') }}</th>
                        <th class="px-3 py-2 text-left font-medium">{{ __('User agent') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($rows as $row)
                        <tr data-test="security-row">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $row->recorded_at->toDayDateTimeString() }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row->event->value }}</td>
                            <td class="px-3 py-2">
                                @if ($row->user)
                                    {{ $row->user->name }}
                                @elseif ($row->attempted_email)
                                    <span class="text-muted-foreground">{{ $row->attempted_email }} ({{ __('unknown') }})</span>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $row->ip_address }}</td>
                            <td class="px-3 py-2 max-w-md truncate text-xs text-muted-foreground">{{ $row->user_agent }}</td>
                            <td class="px-3 py-2">
                                @if ($row->metadata)
                                    <details>
                                        <summary class="cursor-pointer text-xs text-muted-foreground hover:underline">{{ __('Metadata') }}</summary>
                                        <pre class="mt-2 max-h-64 max-w-2xl overflow-auto rounded bg-muted p-2 text-xs">{{ json_encode($row->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ __('No security events in this range.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</section>
