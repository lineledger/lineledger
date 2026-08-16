<?php

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\SecurityLog;
use App\Services\Security\SecurityAnomalyScanner;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cross-tenant security-log view for the site operator. Deliberately has NO
 * `public Company $company` and does not mount(Company): the admin routes sit
 * outside the {company} prefix, so nothing binds current_company and the
 * (unscoped) SecurityLog query sees every tenant — the whole point here. Adding a
 * Company property would re-bind the scope and hide other tenants' rows.
 */
new #[Title('Security')] class extends Component {
    use WithPagination;

    #[Url(as: 'event')]
    public string $eventFilter = '';

    #[Url(as: 'company')]
    public string $companyFilter = '';

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function tiles(): array
    {
        $day = now()->subDay();
        $week = now()->subWeek();

        return [
            'failed_logins_24h' => SecurityLog::query()
                ->where('event', SecurityEvent::LoginFailed->value)
                ->where('recorded_at', '>=', $day)->count(),
            'lockouts_7d' => SecurityLog::query()
                ->where('event', SecurityEvent::LoginLockout->value)
                ->where('recorded_at', '>=', $week)->count(),
            'api_key_events_7d' => SecurityLog::query()
                ->whereIn('event', [
                    SecurityEvent::ApiKeyCreated->value,
                    SecurityEvent::ApiKeyRotated->value,
                    SecurityEvent::ApiKeyRevoked->value,
                ])
                ->where('recorded_at', '>=', $week)->count(),
            'role_changes_7d' => SecurityLog::query()
                ->where('event', SecurityEvent::CompanyMemberRoleChanged->value)
                ->where('recorded_at', '>=', $week)->count(),
        ];
    }

    /**
     * Failed-login counts per day over the last 14 days. Grouped in PHP rather
     * than with a DATE() expression so the query is portable across MySQL/SQLite.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function failedLoginTrend(): array
    {
        $since = now()->subDays(13)->startOfDay();

        $byDay = SecurityLog::query()
            ->where('event', SecurityEvent::LoginFailed->value)
            ->where('recorded_at', '>=', $since)
            ->get(['recorded_at'])
            ->groupBy(fn (SecurityLog $row) => $row->recorded_at->toDateString())
            ->map->count();

        $trend = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $trend[$date] = (int) ($byDay[$date] ?? 0);
        }

        return $trend;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function anomalies(): array
    {
        return app(SecurityAnomalyScanner::class)->scan(now()->subHours(24));
    }

    #[Computed]
    public function logs()
    {
        return SecurityLog::query()
            ->with(['user', 'company'])
            ->when($this->eventFilter !== '', fn ($q) => $q->where('event', $this->eventFilter))
            ->when($this->companyFilter !== '', fn ($q) => $q->where('company_id', $this->companyFilter))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('ip_address', 'like', '%'.$this->search.'%')
                    ->orWhere('attempted_email', 'like', '%'.$this->search.'%');
            }))
            ->orderByDesc('id')
            ->paginate(50);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function eventOptions(): array
    {
        return array_map(fn (SecurityEvent $e) => ['value' => $e->value, 'label' => $e->value], SecurityEvent::cases());
    }

    #[Computed]
    public function companies()
    {
        return Company::query()->withTrashed()->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<x-pages::admin.layout
    :heading="__('Security')"
    :subheading="__('Cross-tenant security events and anomalies.')"
>
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ([
            ['label' => __('Failed logins (24h)'), 'value' => $this->tiles['failed_logins_24h']],
            ['label' => __('Lockouts (7d)'), 'value' => $this->tiles['lockouts_7d']],
            ['label' => __('API key events (7d)'), 'value' => $this->tiles['api_key_events_7d']],
            ['label' => __('Role changes (7d)'), 'value' => $this->tiles['role_changes_7d']],
        ] as $card)
            <div class="rounded-lg border border-border p-4">
                <flux:text class="text-xs text-muted-foreground">{{ $card['label'] }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($card['value']) }}</div>
            </div>
        @endforeach
    </div>

    @if ($this->anomalies !== [])
        <div class="mt-6 rounded-lg border border-red-300 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-950/30">
            <flux:heading size="sm" class="text-red-700 dark:text-red-400">{{ __('Anomalies (last 24h)') }}</flux:heading>
            <ul class="mt-2 space-y-1">
                @foreach ($this->anomalies as $anomaly)
                    <li class="text-sm text-red-700 dark:text-red-300">• {{ $anomaly }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6">
        <flux:heading size="sm">{{ __('Failed logins (14 days)') }}</flux:heading>
        <div class="mt-2 flex items-end gap-1" style="height: 3rem;">
            @php $max = max(1, ...array_values($this->failedLoginTrend)); @endphp
            @foreach ($this->failedLoginTrend as $date => $count)
                <div
                    class="flex-1 rounded-t bg-accent"
                    style="height: {{ $count === 0 ? 2 : max(4, (int) round($count / $max * 100)) }}%;"
                    title="{{ $date }}: {{ $count }}"
                ></div>
            @endforeach
        </div>
    </div>

    <div class="mt-6 flex flex-wrap gap-3">
        <flux:select wire:model.live="eventFilter" :label="__('Event')" class="max-w-xs">
            <flux:select.option value="">{{ __('All events') }}</flux:select.option>
            @foreach ($this->eventOptions as $option)
                <flux:select.option :value="$option['value']">{{ $option['label'] }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="companyFilter" :label="__('Company')" class="max-w-xs">
            <flux:select.option value="">{{ __('All companies') }}</flux:select.option>
            @foreach ($this->companies as $company)
                <flux:select.option :value="$company->id">{{ $company->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live.debounce.400ms="search" :label="__('IP or email')" :placeholder="__('Search…')" class="max-w-xs" />
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-muted-foreground">
                <tr>
                    <th class="py-2 pr-4">{{ __('When') }}</th>
                    <th class="py-2 pr-4">{{ __('Event') }}</th>
                    <th class="py-2 pr-4">{{ __('User / email') }}</th>
                    <th class="py-2 pr-4">{{ __('Company') }}</th>
                    <th class="py-2 pr-4">{{ __('IP') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->logs as $log)
                    <tr class="border-t border-border">
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $log->recorded_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="py-2 pr-4">{{ $log->event->value }}</td>
                        <td class="py-2 pr-4">{{ $log->user?->email ?? $log->attempted_email ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $log->company?->name ?? '—' }}</td>
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $log->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-6 text-center text-muted-foreground">{{ __('No security events match.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->logs->links() }}
    </div>
</x-pages::admin.layout>
