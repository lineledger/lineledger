<?php

namespace App\Services\References;

use Illuminate\Support\Facades\DB;

/**
 * Answers "does anything still point at this list record?" from one of the
 * reference registries (the merge registries for accounts and contacts, the
 * ones in this namespace for tax codes, payment methods and terms).
 *
 * Ids are unique across the whole table, so each probe is a plain
 * `WHERE column = id` with no company filter. Soft-deleted rows count as
 * references on purpose: they still hold the id.
 */
final class RecordReferences
{
    /**
     * The first "table.column" that still references $id, or null.
     *
     * @param  class-string  $registry  a class with a static columns(): list<array{table: string, column: string}>
     * @param  list<string>  $ignore  "table.column" entries that do not count as a use
     */
    public static function firstReference(string $registry, int $id, array $ignore = []): ?string
    {
        /** @var list<array{table: string, column: string}> $columns */
        $columns = $registry::columns();

        foreach ($columns as $entry) {
            $key = $entry['table'].'.'.$entry['column'];

            if (in_array($key, $ignore, true)) {
                continue;
            }

            if (DB::table($entry['table'])->where($entry['column'], $id)->exists()) {
                return $key;
            }
        }

        return null;
    }

    /**
     * A readable name for where a reference sits, for refusal messages:
     * "invoice_lines.tax_code_id" → "invoice lines".
     */
    public static function describe(string $reference): string
    {
        return str_replace('_', ' ', strstr($reference, '.', true) ?: $reference);
    }
}
