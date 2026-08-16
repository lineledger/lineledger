@props([
    'title',
    'subtitle' => null,
    'mode' => 'range',
    'comparison' => false,
    'basis' => false,
    'numberFormat' => false,
    'tracksClasses' => false,
    'tracksLocations' => false,
    'classificationOptions' => [],
    'locationOptions' => [],
    'sectionsRoute' => null,
    'exports' => ['csv', 'xlsx', 'pdf'],
    'exportsDisabled' => false,
    'titleEditable' => false,
    'memorizable' => false,
    'printUrl' => null,
    'emailable' => false,
])

{{--
    Shared report control bar. Rendered inside a report's Livewire view, so the
    wire:model.live directives bind to the host component. The host must expose
    the matching properties: range mode → preset/startDate/endDate; single mode →
    asOfPreset/asOf; plus comparisonBasis, classId, locationId, reportTitle, and
    the export methods exportCsv/exportXlsx/exportPdf. emailable requires the
    EmailsReport concern; numberFormat requires the HasReportNumberFormat
    concern (negativeStyle/numberUnits); basis requires the HasReportBasis
    concern (reportBasis); printUrl is the reports.print URL for this report
    (the current query string is forwarded so the PDF matches the on-screen
    view).
--}}
<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <flux:heading size="xl" level="1" data-test="report-title">{{ $title }}</flux:heading>
        @if ($subtitle)
            <flux:subheading>{{ $subtitle }}</flux:subheading>
        @endif
    </div>

    <div class="flex flex-wrap items-end gap-2">
        @if ($mode === 'range')
            <flux:select wire:model.live="preset" :label="__('Period')" class="max-w-[200px]" data-test="report-preset">
                @foreach ($this->presetOptions() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model.live="startDate" :label="__('Start')" class="max-w-[180px]" />
            <flux:input type="date" wire:model.live="endDate" :label="__('End')" class="max-w-[180px]" />
        @elseif ($mode === 'single')
            <flux:select wire:model.live="asOfPreset" :label="__('Period')" class="max-w-[200px]" data-test="report-preset">
                @foreach ($this->asOfPresetOptions() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input type="date" wire:model.live="asOf" :label="__('As of')" class="max-w-[180px]" />
        @endif

        @if ($comparison)
            <div class="flex items-end gap-1">
                <flux:select wire:model.live="comparisonBasis" :label="__('Compare')" class="max-w-[160px]" data-test="comparison-basis">
                    @foreach (\App\Support\Reporting\ComparisonPeriod::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:tooltip toggleable>
                    <flux:button icon="question-mark-circle" size="sm" variant="ghost" class="mb-1" data-test="comparison-basis-help" />
                    <flux:tooltip.content class="max-w-[20rem]">
                        {{ __('“Prior period” compares to the immediately preceding period of the same length — last month, last quarter, last year. “Prior year” compares to the same dates one calendar year earlier. The exact dates are shown in the report subtitle.') }}
                    </flux:tooltip.content>
                </flux:tooltip>
            </div>
        @endif

        @if ($basis)
            <flux:select wire:model.live="reportBasis" :label="__('Basis')" class="max-w-[130px]" data-test="report-basis">
                @foreach (\App\Concerns\HasReportBasis::basisOptions() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($numberFormat)
            <flux:select wire:model.live="negativeStyle" :label="__('Negatives')" class="max-w-[160px]" data-test="format-negative">
                @foreach (\App\Support\Reporting\ReportNumberFormat::negativeOptions() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="numberUnits" :label="__('Numbers')" class="max-w-[140px]" data-test="format-units">
                @foreach (\App\Support\Reporting\ReportNumberFormat::unitsOptions() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($tracksClasses)
            <flux:select wire:model.live="classId" :label="__('Class')" class="max-w-[180px]" data-test="filter-class">
                <flux:select.option value="">{{ __('All classes') }}</flux:select.option>
                @foreach ($classificationOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($tracksLocations)
            <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-[180px]" data-test="filter-location">
                <flux:select.option value="">{{ __('All locations') }}</flux:select.option>
                @foreach ($locationOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ($titleEditable)
            <flux:input wire:model.live.debounce.400ms="reportTitle" :label="__('Title')" :placeholder="$title" class="max-w-[200px]" data-test="report-title-input" />
        @endif

        {{ $slot }}

        @if ($memorizable)
            <flux:modal.trigger name="memorize-report">
                <flux:button icon="bookmark" variant="ghost" data-test="memorize-trigger">{{ __('Memorize') }}</flux:button>
            </flux:modal.trigger>

            <flux:modal name="memorize-report" class="max-w-md" x-on:report-memorized.window="$flux.modal('memorize-report').close()">
                <form wire:submit="memorizeReport" class="space-y-4">
                    <flux:heading size="lg">{{ __('Memorize report') }}</flux:heading>
                    <flux:subheading>{{ __('Save the current filters and layout to re-run later.') }}</flux:subheading>

                    <flux:input wire:model="memorizeName" :label="__('Name')" :placeholder="$title" data-test="memorize-name" />

                    @if ($this->memorizedGroupOptions->isNotEmpty())
                        <flux:select wire:model="memorizeGroupId" :label="__('Group (optional)')">
                            <flux:select.option value="">{{ __('— No group —') }}</flux:select.option>
                            @foreach ($this->memorizedGroupOptions as $group)
                                <flux:select.option :value="$group->id">{{ $group->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    <flux:input wire:model="memorizeNewGroup" :label="__('Or new group (optional)')" data-test="memorize-new-group" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="memorize-save">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        @if ($emailable)
            <flux:modal.trigger name="email-report">
                <flux:button icon="envelope" variant="ghost" data-test="email-trigger">{{ __('Email') }}</flux:button>
            </flux:modal.trigger>

            <flux:modal name="email-report" class="max-w-md" x-on:report-email-sent.window="$flux.modal('email-report').close()">
                <form wire:submit="sendReportEmail" class="space-y-4">
                    <flux:heading size="lg">{{ __('Email report') }}</flux:heading>
                    <flux:subheading>{{ __('Send this report as a PDF attachment, exactly as currently filtered.') }}</flux:subheading>

                    <flux:input wire:model="emailRecipients" :label="__('To')" :placeholder="__('name@example.com, second@example.com')" :description="__('Separate multiple addresses with commas.')" data-test="email-recipients" />
                    <flux:input wire:model="emailSubject" :label="__('Subject')" :placeholder="$title" data-test="email-subject" />
                    <flux:textarea wire:model="emailBody" :label="__('Message (optional)')" rows="3" data-test="email-body" />
                    @if ($this->canAttachReportXlsx())
                        <flux:checkbox wire:model="emailAttachXlsx" :label="__('Also attach as Excel')" data-test="email-attach-xlsx" />
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" data-test="email-send">{{ __('Send') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        @if ($sectionsRoute)
            <flux:button icon="cog-6-tooth" variant="ghost" :href="$sectionsRoute" wire:navigate data-test="sections-config-link">{{ __('Sections') }}</flux:button>
        @endif

        @if ($printUrl)
            <flux:button
                icon="printer"
                variant="ghost"
                onclick="window.open('{{ $printUrl }}' + window.location.search, '_blank')"
                :aria-label="__('Print')"
                data-test="print-report"
            >{{ __('Print') }}</flux:button>
        @endif

        @if (! empty($exports))
            <flux:dropdown align="end">
                <flux:button variant="primary" icon="arrow-down-tray" icon:trailing="chevron-down" :disabled="$exportsDisabled">{{ __('Download') }}</flux:button>
                <flux:menu>
                    @if (in_array('csv', $exports, true))
                        <flux:menu.item icon="document-text" wire:click="exportCsv">{{ __('CSV') }}</flux:menu.item>
                    @endif
                    @if (in_array('xlsx', $exports, true))
                        <flux:menu.item icon="table-cells" wire:click="exportXlsx">{{ __('Excel') }}</flux:menu.item>
                    @endif
                    @if (in_array('pdf', $exports, true))
                        <flux:menu.item icon="document" wire:click="exportPdf">{{ __('PDF') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        @endif
    </div>
</div>
