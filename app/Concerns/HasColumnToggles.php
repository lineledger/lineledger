<?php

namespace App\Concerns;

use Livewire\Attributes\Url;

/**
 * Per-report column show/hide picker (the QuickBooks-style "Columns" control,
 * rendered by <x-reports.column-picker>). The URL carries the HIDDEN column
 * keys (?hide=memo,entry_no) so default URLs stay clean.
 *
 * Hiding a column changes presentation only:
 * - Exports stay full-width regardless of hidden columns — deliberate, the
 *   same stance as the General Ledger page's "full dataset is always exported".
 * - If the active sortField points at a hidden column, sorting silently
 *   continues by it.
 */
trait HasColumnToggles
{
    #[Url(as: 'hide')]
    public string $hiddenColumns = '';

    /**
     * key => translated label for every toggleable column, in display order.
     * Always-visible columns are not listed. Public (not protected) because
     * the report's Blade view reads it — compiled views cannot call protected
     * methods on the component.
     *
     * @return array<string, string>
     */
    abstract public function columnRegistry(): array;

    /**
     * The hidden-column keys currently in effect: the parsed URL value
     * intersected with the registry, so unknown keys are silently dropped.
     *
     * @return list<string>
     */
    public function hiddenColumnKeys(): array
    {
        $requested = array_filter(array_map('trim', explode(',', $this->hiddenColumns)));

        return array_values(array_intersect($requested, array_keys($this->columnRegistry())));
    }

    public function columnVisible(string $key): bool
    {
        return ! in_array($key, $this->hiddenColumnKeys(), true);
    }

    public function toggleColumn(string $key): void
    {
        if (! array_key_exists($key, $this->columnRegistry())) {
            return;
        }

        $hidden = $this->hiddenColumnKeys();

        $hidden = in_array($key, $hidden, true)
            ? array_values(array_diff($hidden, [$key]))
            : [...$hidden, $key];

        $this->hiddenColumns = implode(',', $hidden);
    }

    /**
     * Number of table columns currently rendered, for empty-state colspans.
     * $fixed counts the always-visible columns not in the registry.
     */
    public function visibleColumnCount(int $fixed = 0): int
    {
        return count($this->columnRegistry()) - count($this->hiddenColumnKeys()) + $fixed;
    }
}
