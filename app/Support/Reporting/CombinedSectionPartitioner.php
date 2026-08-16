<?php

namespace App\Support\Reporting;

use App\Models\ReportGroupSection;
use Illuminate\Support\Collection;

/**
 * Like {@see SectionPartitioner}, but for combined report-group lines: each line
 * row carries a `by_company` map (companyId => cents) that must be aggregated into
 * the section subtotal so the per-company columns stay internally consistent.
 *
 * Each row must carry an `int|null` `section_id`. A row whose `section_id` matches
 * no section in this group falls into "Unassigned" — the display self-heals.
 */
class CombinedSectionPartitioner
{
    /**
     * @param  Collection<int, ReportGroupSection>  $sections  ordered sections for one group_key
     * @param  array<int, array<string, mixed>>  $rows  line rows tagged with a `section_id`
     * @param  string  $valueKey  row key holding the current-period cents ('balance' or 'current')
     * @param  string  $priorKey  row key holding the prior-period cents (income statement only)
     * @return array<int, array{
     *     type: 'section'|'unassigned',
     *     id?: int,
     *     name?: string,
     *     rows: array<int, array<string, mixed>>,
     *     subtotal: int,
     *     prior_subtotal: int,
     *     by_company: array<int, int>,
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
                'by_company' => self::sumByCompany($sectionRows),
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
                'by_company' => self::sumByCompany($unassigned),
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, int>
     */
    private static function sumByCompany(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach (($row['by_company'] ?? []) as $companyId => $value) {
                $totals[$companyId] = ($totals[$companyId] ?? 0) + (int) $value;
            }
        }

        return $totals;
    }
}
