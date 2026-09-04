<?php

use App\Enums\RecurrenceDayAnchor;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\MemorizedReportGroup;
use App\Models\ReportEmailSchedule;
use App\Services\Recurring\NextRunDateCalculator;
use App\Services\Reporting\Render\ReportBundleBuilder;
use App\Support\Reporting\RenderableReports;
use App\Support\Reporting\ReportCatalog;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new #[Title('Memorized Reports')] class extends Component {
    public Company $company;

    // --- Email schedule modal state (one modal reused for report + group) ---

    public ?int $scheduleReportId = null;

    public ?int $scheduleGroupId = null;

    public string $scheduleFrequency = 'monthly';

    public string $scheduleStartDate = '';

    public ?int $scheduleDayOfMonth = null;

    public string $scheduleDayAnchor = 'day_of_month';

    public string $scheduleEndType = 'never';

    public string $scheduleEndDate = '';

    public ?int $scheduleMaxOccurrences = null;

    public string $scheduleRecipients = '';

    public string $scheduleSubject = '';

    public bool $scheduleAttachXlsx = false;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function catalog(): array
    {
        return ReportCatalog::flatten($this->company, Auth::user());
    }

    /**
     * @return Collection<int, MemorizedReportGroup>
     */
    #[Computed]
    public function groups(): Collection
    {
        return MemorizedReportGroup::query()
            ->where('user_id', Auth::id())
            ->with('memorizedReports')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, MemorizedReport>
     */
    #[Computed]
    public function ungrouped(): Collection
    {
        return MemorizedReport::query()
            ->where('user_id', Auth::id())
            ->whereNull('memorized_report_group_id')
            ->orderBy('name')
            ->get();
    }

    public function runUrl(MemorizedReport $report): ?string
    {
        $entry = $this->catalog[$report->report_key] ?? null;

        return $entry !== null ? $entry['url'].'?memorized='.$report->id : null;
    }

    public function reportLabel(MemorizedReport $report): string
    {
        return $this->catalog[$report->report_key]['label'] ?? $report->report_key;
    }

    public function delete(int $id): void
    {
        MemorizedReport::query()->where('user_id', Auth::id())->where('id', $id)->delete();
        unset($this->groups, $this->ungrouped, $this->schedules);
    }

    public function deleteGroup(int $id): void
    {
        // Reports in the group fall back to ungrouped (nullOnDelete).
        MemorizedReportGroup::query()->where('user_id', Auth::id())->where('id', $id)->delete();
        unset($this->groups, $this->ungrouped, $this->schedules);
    }

    // --- Email schedules ---

    /**
     * @return Collection<int, ReportEmailSchedule>
     */
    #[Computed]
    public function schedules(): Collection
    {
        return ReportEmailSchedule::query()->where('user_id', Auth::id())->get();
    }

    public function scheduleForReport(int $reportId): ?ReportEmailSchedule
    {
        return $this->schedules->firstWhere('memorized_report_id', $reportId);
    }

    public function scheduleForGroup(int $groupId): ?ReportEmailSchedule
    {
        return $this->schedules->firstWhere('memorized_report_group_id', $groupId);
    }

    /**
     * Whether the report can be put on an email schedule — same gate as the
     * one-off email feature: available in this company's catalog and renderable
     * to PDF outside a Livewire request.
     */
    public function schedulable(MemorizedReport $report): bool
    {
        return isset($this->catalog[$report->report_key])
            && RenderableReports::supports($report->report_key, 'pdf');
    }

    public function openSchedule(int $reportId): void
    {
        $report = MemorizedReport::query()->where('user_id', Auth::id())->find($reportId);

        if ($report === null || ! $this->schedulable($report)) {
            return;
        }

        $this->prepareScheduleForm(reportId: $report->id);
    }

    public function openGroupSchedule(int $groupId): void
    {
        $group = MemorizedReportGroup::query()
            ->where('user_id', Auth::id())
            ->with('memorizedReports')
            ->find($groupId);

        if ($group === null || ! $this->groupExportable($group)) {
            return;
        }

        $this->prepareScheduleForm(groupId: $group->id);
    }

    /**
     * Seed the modal from the target's existing schedule (edit-in-place) or
     * sensible defaults, then open it.
     */
    protected function prepareScheduleForm(?int $reportId = null, ?int $groupId = null): void
    {
        $this->resetErrorBag();
        $this->scheduleReportId = $reportId;
        $this->scheduleGroupId = $groupId;

        $existing = $reportId !== null
            ? $this->scheduleForReport($reportId)
            : $this->scheduleForGroup((int) $groupId);

        $today = $this->company->currentDateTime();
        $this->scheduleFrequency = $existing?->frequency->value ?? 'monthly';
        $this->scheduleStartDate = $existing?->start_date?->toDateString() ?? $today->toDateString();
        $this->scheduleDayOfMonth = $existing?->day_of_month ?? (int) $today->format('j');
        $this->scheduleDayAnchor = $existing?->scheduleDayAnchor()->value ?? 'day_of_month';
        $this->scheduleEndType = $existing?->end_type->value ?? 'never';
        $this->scheduleEndDate = $existing?->end_date?->toDateString() ?? '';
        $this->scheduleMaxOccurrences = $existing?->max_occurrences;
        $this->scheduleRecipients = $existing !== null ? implode(', ', $existing->recipients) : '';
        $this->scheduleSubject = $existing?->subject ?? '';
        $this->scheduleAttachXlsx = (bool) ($existing?->attach_xlsx ?? false);

        Flux::modal('schedule-report-email')->show();
    }

    public function saveSchedule(): void
    {
        $validated = $this->validate([
            'scheduleFrequency' => ['required', Rule::in(array_column(RecurrenceFrequency::cases(), 'value'))],
            'scheduleStartDate' => ['required', 'date'],
            'scheduleDayOfMonth' => ['nullable', 'integer', 'min:1', 'max:31'],
            'scheduleDayAnchor' => ['required', Rule::in(array_column(RecurrenceDayAnchor::cases(), 'value'))],
            'scheduleEndType' => ['required', Rule::in(array_column(RecurrenceEndType::cases(), 'value'))],
            'scheduleEndDate' => ['nullable', 'required_if:scheduleEndType,on_date', 'date', 'after_or_equal:scheduleStartDate'],
            'scheduleMaxOccurrences' => ['nullable', 'required_if:scheduleEndType,after_occurrences', 'integer', 'min:1'],
            'scheduleSubject' => ['nullable', 'string', 'max:255'],
        ]);

        $recipients = collect(explode(',', $this->scheduleRecipients))
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->unique()
            ->values();

        $this->resetErrorBag('scheduleRecipients');

        if ($recipients->isEmpty()) {
            $this->addError('scheduleRecipients', __('Enter at least one email address.'));

            return;
        }

        if ($recipients->count() > 10) {
            $this->addError('scheduleRecipients', __('A report can be emailed to at most 10 recipients.'));

            return;
        }

        $invalid = $recipients->reject(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        if ($invalid->isNotEmpty()) {
            $this->addError('scheduleRecipients', __('Invalid email address: :emails', ['emails' => $invalid->implode(', ')]));

            return;
        }

        // Re-verify the target still exists and belongs to this user.
        $reportId = null;
        $groupId = null;

        if ($this->scheduleReportId !== null) {
            $reportId = MemorizedReport::query()->where('user_id', Auth::id())->find($this->scheduleReportId)?->id;
        } elseif ($this->scheduleGroupId !== null) {
            $groupId = MemorizedReportGroup::query()->where('user_id', Auth::id())->find($this->scheduleGroupId)?->id;
        }

        if ($reportId === null && $groupId === null) {
            return;
        }

        $frequency = RecurrenceFrequency::from($validated['scheduleFrequency']);
        $anchor = $frequency->usesDayOfMonth()
            ? RecurrenceDayAnchor::from($validated['scheduleDayAnchor'])
            : RecurrenceDayAnchor::DayOfMonth;
        $endType = RecurrenceEndType::from($validated['scheduleEndType']);

        // One schedule per target per user: saving replaces any existing one.
        ReportEmailSchedule::query()
            ->where('user_id', Auth::id())
            ->when($reportId !== null, fn ($q) => $q->where('memorized_report_id', $reportId))
            ->when($groupId !== null, fn ($q) => $q->where('memorized_report_group_id', $groupId))
            ->delete();

        $schedule = new ReportEmailSchedule([
            'company_id' => $this->company->id,
            'user_id' => Auth::id(),
            'memorized_report_id' => $reportId,
            'memorized_report_group_id' => $groupId,
            'recipients' => $recipients->all(),
            'subject' => trim($this->scheduleSubject) !== '' ? trim($this->scheduleSubject) : null,
            'attach_xlsx' => $this->scheduleAttachXlsx,
            'frequency' => $frequency,
            'start_date' => $validated['scheduleStartDate'],
            'day_anchor' => $anchor,
            'day_of_month' => $frequency->usesDayOfMonth() && $anchor->usesDayOfMonth() ? ($validated['scheduleDayOfMonth'] ?? null) : null,
            'end_type' => $endType,
            'end_date' => $endType === RecurrenceEndType::OnDate ? ($validated['scheduleEndDate'] ?: null) : null,
            'max_occurrences' => $endType === RecurrenceEndType::AfterOccurrences ? ($validated['scheduleMaxOccurrences'] ?: null) : null,
            'is_active' => true,
        ]);
        $schedule->next_run_date = app(NextRunDateCalculator::class)->first($schedule)->toDateString();
        $schedule->save();

        unset($this->schedules);
        Flux::toast(variant: 'success', text: __('Email schedule saved.'));
        Flux::modal('schedule-report-email')->close();
    }

    public function deleteSchedule(int $id): void
    {
        ReportEmailSchedule::query()->where('user_id', Auth::id())->where('id', $id)->delete();
        unset($this->schedules);
    }

    /**
     * Whether any report in the group would make it into a PDF bundle —
     * gates the "Download group" button.
     */
    public function groupExportable(MemorizedReportGroup $group): bool
    {
        return $group->memorizedReports->contains(
            fn (MemorizedReport $report): bool => isset($this->catalog[$report->report_key])
                && RenderableReports::supports($report->report_key, 'pdf'),
        );
    }

    /**
     * Download every renderable report in the group as a single ZIP of PDFs.
     */
    public function exportGroup(int $groupId): ?BinaryFileResponse
    {
        $group = MemorizedReportGroup::query()
            ->where('user_id', Auth::id())
            ->with('memorizedReports')
            ->find($groupId);

        if ($group === null) {
            return null;
        }

        try {
            $artifact = app(ReportBundleBuilder::class)->bundle(
                $this->company,
                $group->memorizedReports,
                array_keys($this->catalog),
                $group->name,
            );
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return null;
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'report-bundle-');
        file_put_contents($tmp, $artifact->bytes);

        return response()
            ->download($tmp, $artifact->filename, ['Content-Type' => $artifact->mime])
            ->deleteFileAfterSend(true);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Memorized Reports') }}</flux:heading>
            <flux:subheading>{{ __('Your saved report views. Run one to reopen it with its saved filters.') }}</flux:subheading>
        </div>
        <flux:button :href="route('reports.index', ['company' => $company->slug])" wire:navigate variant="ghost" icon="arrow-left">{{ __('Report Center') }}</flux:button>
    </div>

    @php $hasAny = $this->groups->contains(fn ($g) => $g->memorizedReports->isNotEmpty()) || $this->ungrouped->isNotEmpty(); @endphp

    @if (! $hasAny)
        <flux:callout icon="bookmark" data-test="memorized-empty">
            <flux:callout.heading>{{ __('No memorized reports yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Open a report, customize it, and click "Memorize" to save it here.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @foreach ($this->groups as $group)
        @if ($group->memorizedReports->isNotEmpty())
            <div class="mb-6" wire:key="grp-{{ $group->id }}">
                <div class="mb-2 flex items-center justify-between">
                    <flux:heading size="lg">{{ $group->name }}</flux:heading>
                    <div class="flex items-center gap-2">
                        @php $groupSchedule = $this->scheduleForGroup($group->id); @endphp
                        @if ($groupSchedule)
                            <flux:badge :color="$groupSchedule->is_active ? 'sky' : 'amber'" size="sm" data-test="group-schedule-badge">
                                {{ $groupSchedule->is_active ? __('Scheduled').' · '.mb_strtolower($groupSchedule->frequency->label()) : __('Schedule paused') }}
                            </flux:badge>
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="deleteSchedule({{ $groupSchedule->id }})" wire:confirm="{{ __('Remove this email schedule?') }}" data-test="group-schedule-delete" />
                        @endif
                        @if ($this->groupExportable($group))
                            <flux:button size="xs" variant="ghost" icon="clock" wire:click="openGroupSchedule({{ $group->id }})" data-test="schedule-group">{{ __('Schedule') }}</flux:button>
                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" wire:click="exportGroup({{ $group->id }})" data-test="export-group">{{ __('Download group') }}</flux:button>
                        @endif
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteGroup({{ $group->id }})" wire:confirm="{{ __('Delete this group? Its reports move to Ungrouped.') }}">{{ __('Delete group') }}</flux:button>
                    </div>
                </div>
                @include('pages.reports.partials.memorized-list', ['reports' => $group->memorizedReports])
            </div>
        @endif
    @endforeach

    @if ($this->ungrouped->isNotEmpty())
        <div class="mb-6">
            <flux:heading size="lg" class="mb-2">{{ __('Ungrouped') }}</flux:heading>
            @include('pages.reports.partials.memorized-list', ['reports' => $this->ungrouped])
        </div>
    @endif

    <flux:modal name="schedule-report-email" class="max-w-md">
        <form wire:submit="saveSchedule" class="space-y-4">
            <flux:heading size="lg">{{ __('Email schedule') }}</flux:heading>
            <flux:subheading>{{ __('Email this report on a schedule. Date presets like "Last Month" re-resolve at each send.') }}</flux:subheading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="scheduleFrequency" :label="__('Frequency')" data-test="schedule-frequency">
                    @foreach (\App\Enums\RecurrenceFrequency::options() as $opt)
                        <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input type="date" wire:model="scheduleStartDate" :label="__('Start date')" required data-test="schedule-start-date" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if ($scheduleFrequency !== 'weekly')
                    <flux:select wire:model.live="scheduleDayAnchor" :label="__('Runs on')" :description="\App\Enums\RecurrenceDayAnchor::tryFrom($scheduleDayAnchor)?->description()" data-test="schedule-day-anchor">
                        @foreach (\App\Enums\RecurrenceDayAnchor::cases() as $a)
                            <flux:select.option :value="$a->value">{{ $a->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($scheduleDayAnchor === 'day_of_month')
                        <flux:input type="number" min="1" max="31" wire:model="scheduleDayOfMonth" :label="__('Day of month')" placeholder="{{ __('1–31') }}" data-test="schedule-day-of-month" />
                    @endif
                @endif

                <flux:select wire:model.live="scheduleEndType" :label="__('Ends')" data-test="schedule-end-type">
                    @foreach (\App\Enums\RecurrenceEndType::cases() as $e)
                        <flux:select.option :value="$e->value">{{ $e->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @if ($scheduleEndType === 'on_date')
                <flux:input type="date" wire:model="scheduleEndDate" :label="__('End date')" data-test="schedule-end-date" />
            @elseif ($scheduleEndType === 'after_occurrences')
                <flux:input type="number" min="1" wire:model="scheduleMaxOccurrences" :label="__('Number of sends')" data-test="schedule-max-occurrences" />
            @endif

            <flux:input wire:model="scheduleRecipients" :label="__('To')" :placeholder="__('name@example.com, second@example.com')" :description="__('Separate multiple addresses with commas.')" data-test="schedule-recipients" />
            <flux:input wire:model="scheduleSubject" :label="__('Subject (optional)')" data-test="schedule-subject" />
            <flux:checkbox wire:model="scheduleAttachXlsx" :label="__('Also attach as Excel')" data-test="schedule-attach-xlsx" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="schedule-save">{{ __('Save schedule') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
