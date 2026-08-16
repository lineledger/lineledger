<?php

namespace App\Concerns;

use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\ReportSection;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Shared behaviour for the two report-section config pages (income statement +
 * balance sheet). The host component supplies the statement and its anchor
 * groups; this trait handles section CRUD, account assignment, and ordering.
 *
 * Anchors: for the balance sheet a group_key is an AccountSubtype value; for the
 * income statement it is a bucket ('income' | 'cogs' | 'expense').
 */
trait ManagesReportSections
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
     * Public because the config views call it directly.
     *
     * @return array<string, string>
     */
    abstract public function anchorLabels(): array;

    /**
     * The anchor group_key an account belongs to on this statement, or null if
     * the account does not belong on this statement at all.
     */
    abstract protected function anchorFor(Account $account): ?string;

    /**
     * Sections for this company + statement, eager-loaded with their accounts and
     * grouped by anchor for rendering.
     *
     * @return Collection<string, Collection<int, ReportSection>>
     */
    #[Computed]
    public function sections(): Collection
    {
        // Wrap in a base collection so Livewire's EloquentCollectionSynth doesn't
        // try to serialize the grouped result as a collection-of-models (the inner
        // groups are themselves collections, which it can't key by getKey()).
        return collect(
            ReportSection::query()
                ->where('company_id', $this->company->id)
                ->where('statement', $this->statement()->value)
                ->with('accounts')
                ->orderBy('group_key')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('group_key')
        );
    }

    /**
     * Every account that belongs on this statement, keyed by anchor group_key
     * then ordered by code. Drives the Unassigned lists and the move dropdowns.
     *
     * @return Collection<string, Collection<int, Account>>
     */
    #[Computed]
    public function accountsByGroup(): Collection
    {
        // collect() first so except() runs on a base collection (keyed by string
        // anchor) — EloquentCollection::except() would call getKey() on the grouped
        // values, which are themselves collections.
        return collect(
            Account::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->orderBy('code')
                ->get()
                ->groupBy(fn (Account $account): string => $this->anchorFor($account) ?? '')
        )->except(['']); // drop accounts that don't belong on this statement
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
            $nextOrder = (int) ReportSection::query()
                ->where('company_id', $this->company->id)
                ->where('statement', $this->statement()->value)
                ->where('group_key', $validated['f_section_group'])
                ->max('sort_order') + 1;

            ReportSection::create([
                'company_id' => $this->company->id,
                'statement' => $this->statement()->value,
                'group_key' => $validated['f_section_group'],
                'name' => $validated['f_section_name'],
                'sort_order' => $nextOrder,
            ]);
        }

        unset($this->sections, $this->accountsByGroup);
        Flux::modal('section-form')->close();
        Flux::toast(variant: 'success', text: __('Section saved.'));
    }

    public function deleteSection(int $id): void
    {
        $section = $this->findSection($id);
        $section->accounts()->update(['report_section_id' => null]);
        $section->delete();

        unset($this->sections, $this->accountsByGroup);
        Flux::toast(variant: 'success', text: __('Section removed; its accounts moved to Unassigned.'));
    }

    /**
     * Assign an account to a section, or to "unassigned" to clear it. The
     * dropdown only offers same-anchor sections, but we re-check defensively.
     */
    public function moveAccount(int $accountId, string $target): void
    {
        $account = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->findOrFail($accountId);

        if ($target === 'unassigned') {
            $account->update(['report_section_id' => null]);
        } else {
            $section = $this->findSection((int) $target);

            if (! $section->accepts($account)) {
                return;
            }

            $account->update(['report_section_id' => $section->id]);
        }

        unset($this->sections, $this->accountsByGroup);
    }

    public function moveSectionUp(int $id): void
    {
        $this->swapSection($id, -1);
    }

    public function moveSectionDown(int $id): void
    {
        $this->swapSection($id, 1);
    }

    /**
     * Swap a section's sort_order with its neighbour (within the same group) in
     * the given direction (-1 up, +1 down).
     */
    protected function swapSection(int $id, int $direction): void
    {
        $section = $this->findSection($id);

        $neighbours = ReportSection::query()
            ->where('company_id', $this->company->id)
            ->where('statement', $this->statement()->value)
            ->where('group_key', $section->group_key)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $index = $neighbours->search(fn (ReportSection $s): bool => $s->id === $section->id);
        $swapWith = $neighbours->get($index + $direction);

        if ($swapWith === null) {
            return;
        }

        $order = $section->sort_order;
        $section->update(['sort_order' => $swapWith->sort_order]);
        $swapWith->update(['sort_order' => $order]);

        unset($this->sections);
    }

    protected function findSection(int $id): ReportSection
    {
        return ReportSection::query()
            ->where('company_id', $this->company->id)
            ->where('statement', $this->statement()->value)
            ->findOrFail($id);
    }
}
