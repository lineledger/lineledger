<?php

namespace App\Concerns;

use App\Enums\ReportStatement;
use App\Models\ReportGroupLine;
use App\Models\ReportGroupSection;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Shared behaviour for the two combined report-group section config pages (income
 * statement + balance sheet). The host component supplies the ReportGroup, the
 * statement, and its anchor groups; this trait handles section CRUD, line
 * assignment, and ordering. Authorization (creator-only) is enforced in the host
 * component's mount() via the ReportGroup policy.
 *
 * Anchors: for the balance sheet a group_key is a line's AccountSubtype value (or
 * its AccountType value when it has no subtype); for the income statement it is a
 * bucket ('income' | 'cogs' | 'expense').
 */
trait ManagesReportGroupSections
{
    public ?int $editingSectionId = null;

    public string $f_section_name = '';

    public string $f_section_group = '';

    /**
     * The statement this page configures.
     */
    abstract protected function statement(): ReportStatement;

    /**
     * Valid anchor groups in presentation order: group_key => display label.
     *
     * @return array<string, string>
     */
    abstract public function anchorLabels(): array;

    /**
     * The anchor group_key a line belongs to on this statement, or null if the
     * line does not belong on this statement at all.
     */
    abstract protected function anchorFor(ReportGroupLine $line): ?string;

    /**
     * Sections for this group + statement, grouped by anchor for rendering.
     *
     * @return Collection<string, Collection<int, ReportGroupSection>>
     */
    #[Computed]
    public function sections(): Collection
    {
        return collect(
            $this->reportGroup->sections()
                ->where('statement', $this->statement()->value)
                ->with('lines')
                ->orderBy('group_key')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('group_key')
        );
    }

    /**
     * Every combined line that belongs on this statement, keyed by anchor group_key
     * then ordered by sort_order. Drives the Unassigned lists and the move dropdowns.
     *
     * @return Collection<string, Collection<int, ReportGroupLine>>
     */
    #[Computed]
    public function linesByGroup(): Collection
    {
        return collect(
            $this->reportGroup->lines()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy(fn (ReportGroupLine $line): string => $this->anchorFor($line) ?? '')
        )->except(['']);
    }

    public function openNewSection(string $groupKey): void
    {
        $this->reset(['editingSectionId', 'f_section_name']);
        $this->f_section_group = $groupKey;
        Flux::modal('section-form')->show();
    }

    public function openEditSection(int $id): void
    {
        $section = $this->findSection($id);
        $this->editingSectionId = $section->id;
        $this->f_section_name = $section->name;
        $this->f_section_group = $section->group_key;
        Flux::modal('section-form')->show();
    }

    public function saveSection(): void
    {
        $validated = $this->validate([
            'f_section_name' => ['required', 'string', 'max:255'],
            'f_section_group' => ['required', Rule::in(array_keys($this->anchorLabels()))],
        ]);

        if ($this->editingSectionId) {
            $this->findSection($this->editingSectionId)->update([
                'name' => $validated['f_section_name'],
                'group_key' => $validated['f_section_group'],
            ]);
        } else {
            $nextOrder = (int) $this->reportGroup->sections()
                ->where('statement', $this->statement()->value)
                ->where('group_key', $validated['f_section_group'])
                ->max('sort_order') + 1;

            $this->reportGroup->sections()->create([
                'statement' => $this->statement()->value,
                'group_key' => $validated['f_section_group'],
                'name' => $validated['f_section_name'],
                'sort_order' => $nextOrder,
            ]);
        }

        unset($this->sections, $this->linesByGroup);
        Flux::modal('section-form')->close();
        Flux::toast(variant: 'success', text: __('Section saved.'));
    }

    public function deleteSection(int $id): void
    {
        $section = $this->findSection($id);
        $section->lines()->update(['report_group_section_id' => null]);
        $section->delete();

        unset($this->sections, $this->linesByGroup);
        Flux::toast(variant: 'success', text: __('Section removed; its lines moved to Unassigned.'));
    }

    /**
     * Assign a line to a section, or to "unassigned" to clear it. The dropdown only
     * offers same-anchor sections, but we re-check defensively.
     */
    public function moveLine(int $lineId, string $target): void
    {
        $line = $this->reportGroup->lines()->findOrFail($lineId);

        if ($target === 'unassigned') {
            $line->update(['report_group_section_id' => null]);
        } else {
            $section = $this->findSection((int) $target);

            if (! $section->accepts($line)) {
                return;
            }

            $line->update(['report_group_section_id' => $section->id]);
        }

        unset($this->sections, $this->linesByGroup);
    }

    public function moveSectionUp(int $id): void
    {
        $this->swapSection($id, -1);
    }

    public function moveSectionDown(int $id): void
    {
        $this->swapSection($id, 1);
    }

    protected function swapSection(int $id, int $direction): void
    {
        $section = $this->findSection($id);

        $neighbours = $this->reportGroup->sections()
            ->where('statement', $this->statement()->value)
            ->where('group_key', $section->group_key)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $index = $neighbours->search(fn (ReportGroupSection $s): bool => $s->id === $section->id);
        $swapWith = $neighbours->get($index + $direction);

        if ($swapWith === null) {
            return;
        }

        $order = $section->sort_order;
        $section->update(['sort_order' => $swapWith->sort_order]);
        $swapWith->update(['sort_order' => $order]);

        unset($this->sections);
    }

    protected function findSection(int $id): ReportGroupSection
    {
        return $this->reportGroup->sections()
            ->where('statement', $this->statement()->value)
            ->findOrFail($id);
    }
}
