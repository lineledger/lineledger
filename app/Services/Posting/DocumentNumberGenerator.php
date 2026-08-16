<?php

namespace App\Services\Posting;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Generates sequential document numbers per company (INV-000001, REC-000001, etc.).
 * The model and column to inspect, and the prefix, are all caller-supplied —
 * keeps each document type's logic in one line at the call site.
 */
class DocumentNumberGenerator
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public function next(Company $company, string $modelClass, string $column, string $prefix): string
    {
        return DB::transaction(function () use ($company, $modelClass, $column, $prefix) {
            // Most-recent number overall — captures any custom format the user adopted.
            $lastOverall = $modelClass::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($lastOverall) {
                $value = (string) $lastOverall->{$column};

                // A user-adopted custom format (not our PREFIX-000001 default): continue it.
                if (! $this->isSystemDefault($value) && ($continued = self::incrementFormat($value)) !== null) {
                    return $continued;
                }
            }

            // Default: per-prefix sequence (keeps BILL vs REIM, etc. separate).
            $last = $modelClass::query()
                ->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where($column, 'like', $prefix.'-%')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $nextSeq = 1;

            if ($last && preg_match('/'.preg_quote($prefix, '/').'-(\d+)/', $last->{$column}, $m)) {
                $nextSeq = ((int) $m[1]) + 1;
            }

            return $prefix.'-'.str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Increment the trailing run of digits, preserving surrounding text and zero-pad width.
     * "27/001" → "27/002", "INV-2027-009" → "INV-2027-010", "1001" → "1002".
     * Returns null when there is no trailing digit run (e.g. "VOID").
     */
    public static function incrementFormat(string $value): ?string
    {
        if (! preg_match('/^(.*?)(\d+)(\D*)$/', $value, $m)) {
            return null;
        }

        $next = (string) (((int) $m[2]) + 1);

        return $m[1].str_pad($next, strlen($m[2]), '0', STR_PAD_LEFT).$m[3];
    }

    /**
     * Recognise our own machine-generated default (PREFIX-000001) so a fresh custom
     * format isn't confused with it — this keeps the per-prefix sequences separate.
     */
    private function isSystemDefault(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{1,8}-\d{6}$/', $value);
    }
}
