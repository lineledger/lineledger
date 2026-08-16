<?php

namespace App\Support\Reporting;

use App\Models\ReportSection;
use Illuminate\Support\Collection;

/**
 * Splits an ordered list of report rows into custom display sections plus an
 * "Unassigned" remainder. Purely a regrouping: every input row appears in exactly
 * one output block, so callers can sum block subtotals back to the original total.
 *
 * Each row must carry an `int|null` `section_id`. A row whose `section_id` does not
 * match any section in this group (e.g. the account was later re-typed into a
 * different subtype/bucket) falls into Unassigned — the display self-heals.
 */
class SectionPartitioner
{
    /**
     * @param  Collection<int, ReportSection>  $sections  ordered sections for one group_key
     * @param  array<int, array<string, mixed>>  $rows  rows tagged with a `section_id`
     * @param  string  $valueKey  row key holding the current-period cents (e.g. 'current' or 'balance')
     * @param  string  $priorKey  row key holding the prior-period cents
     * @return array<int, array{
     *     type: 'section'|'unassigned',
     *     id?: int,
     *     name?: string,
     *     rows: array<int, array<string, mixed>>,
     *     subtotal: int,
     *     prior_subtotal: int,
     * }>
     */
    public static function partition(Collection $sections, array $rows, string $valueKey, string $priorKey = 'prior'): array
    {
        $sectionIds = $sections->pluck('id')->all();

        $blocks = [];

        foreach ($sections as $section) {
            $sectionRows = array_values(array_filter(
                $rows,
                fn (array $row): bool => ($row['section_id'] ?? null) === $section->id,
            ));

            if ($sectionRows === []) {
                continue;
            }

            $blocks[] = [
                'type' => 'section',
                'id' => $section->id,
                'name' => $section->name,
                'rows' => $sectionRows,
                'subtotal' => self::sum($sectionRows, $valueKey),
                'prior_subtotal' => self::sum($sectionRows, $priorKey),
            ];
        }

        $unassigned = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! in_array($row['section_id'] ?? null, $sectionIds, true),
        ));

        if ($unassigned !== []) {
            $blocks[] = [
                'type' => 'unassigned',
                'rows' => $unassigned,
                'subtotal' => self::sum($unassigned, $valueKey),
                'prior_subtotal' => self::sum($unassigned, $priorKey),
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function sum(array $rows, string $key): int
    {
        return array_sum(array_map(fn (array $row): int => (int) ($row[$key] ?? 0), $rows));
    }
}
