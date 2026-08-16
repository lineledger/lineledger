<?php

namespace App\Concerns;

use App\Models\MemorizedReport;
use App\Models\MemorizedReportGroup;
use App\Support\Reporting\ReportSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Lets a report be saved as a "memorized report" (QuickBooks): a snapshot of the
 * report's URL-bound settings, re-applied on run. The consuming report must
 * implement reportKey() (its route name), and call applyMemorized() from mount
 * with the incoming ?memorized=<id> so a saved view rehydrates.
 *
 * Settings are captured generically from the standard report properties present
 * on the component, so any report using the report traits is memorizable.
 */
trait Memorizable
{
    public string $memorizeName = '';

    public ?int $memorizeGroupId = null;

    public string $memorizeNewGroup = '';

    /** The report's route name, used as the memorized report_key. */
    abstract protected function reportKey(): string;

    /**
     * The properties that make up a memorized view (see ReportSettings::KEYS).
     * Only those actually declared on the component are captured.
     *
     * @return array<string, mixed>
     */
    protected function memorizableState(): array
    {
        return ReportSettings::capture($this);
    }

    /**
     * Saved groups for the current user, for the "memorize" group picker.
     *
     * @return Collection<int, MemorizedReportGroup>
     */
    #[Computed]
    public function memorizedGroupOptions(): Collection
    {
        return MemorizedReportGroup::query()
            ->where('user_id', Auth::id())
            ->orderBy('name')
            ->get();
    }

    public function memorizeReport(): void
    {
        $this->validate([
            'memorizeName' => 'required|string|max:255',
            'memorizeNewGroup' => 'nullable|string|max:255',
            'memorizeGroupId' => 'nullable|integer',
        ]);

        $groupId = $this->memorizeGroupId;

        if (trim($this->memorizeNewGroup) !== '') {
            $groupId = MemorizedReportGroup::create([
                'user_id' => Auth::id(),
                'name' => trim($this->memorizeNewGroup),
            ])->id;
        }

        MemorizedReport::create([
            'user_id' => Auth::id(),
            'memorized_report_group_id' => $groupId,
            'report_key' => $this->reportKey(),
            'name' => trim($this->memorizeName),
            'settings' => $this->memorizableState(),
        ]);

        $this->reset('memorizeName', 'memorizeNewGroup', 'memorizeGroupId');
        unset($this->memorizedGroupOptions);

        $this->dispatch('report-memorized');
    }

    /**
     * Re-apply a saved memorized report's settings, validating it belongs to the
     * current user and matches this report. Company scoping is enforced by the
     * model's global scope.
     */
    public function applyMemorized(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $memorized = MemorizedReport::query()
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if ($memorized === null || $memorized->report_key !== $this->reportKey()) {
            return;
        }

        // Reports saved before the comparison basis became a dropdown stored a
        // boolean `showComparison`; map a truthy value onto the prior-year basis.
        if (($memorized->settings['showComparison'] ?? false)
            && ! array_key_exists('comparisonBasis', $memorized->settings)
            && property_exists($this, 'comparisonBasis')) {
            $this->comparisonBasis = 'prior_year';
        }

        ReportSettings::apply($this, $memorized->settings);
    }
}
