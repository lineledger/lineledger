<?php

namespace App\Services\Posting;

use App\Models\Cheque;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generates sequential document numbers per company (INV-000001, REC-000001, etc.).
 * The model and column to inspect, and the prefix, are all caller-supplied —
 * keeps each document type's logic in one line at the call site.
 *
 * Every number handed back is checked against the table first: the per-company
 * unique index on these columns is unforgiving, and "the newest row by id" is
 * not the same thing as "the highest number" once documents have been imported,
 * back-dated or renumbered.
 */
class DocumentNumberGenerator
{
    /** Guard against an unbounded search when a whole numeric run is taken. */
    private const MAX_PROBES = 1000;

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
                if (! $this->isSystemDefault($value)) {
                    $continued = $this->nextInCustomFormat($company, $modelClass, $column, $value);

                    if ($continued !== null && ($free = $this->firstFree($company, $modelClass, $column, $continued)) !== null) {
                        return $free;
                    }
                }
            }

            // Default: per-prefix sequence (keeps BILL vs REIM, etc. separate).
            $candidate = $prefix.'-'.str_pad((string) $this->nextSequenceFor($company, $modelClass, $column, $prefix), 6, '0', STR_PAD_LEFT);

            $free = $this->firstFree($company, $modelClass, $column, $candidate);

            if ($free === null) {
                throw new RuntimeException(
                    "Could not generate a free {$column} for this company — the numbers around {$candidate} are all taken. Enter one manually.",
                );
            }

            return $free;
        });
    }

    /**
     * Is $number already used by another row of this document type?
     *
     * Callers that let the user type their own number use this to fail with a
     * readable message instead of a raw unique-constraint violation.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function isTaken(Company $company, string $modelClass, string $column, string $number, ?int $exceptId = null): bool
    {
        return $modelClass::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where($column, $number)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * Continue a user-adopted format such as "INV 27/10019" or "2027-099".
     *
     * The seed row is the newest by id, but the sequence must continue from the
     * *highest* number that shares the same shape — a company that imported its
     * back catalogue out of order (or created one late document with an old
     * number) would otherwise be handed a number that already exists.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function nextInCustomFormat(Company $company, string $modelClass, string $column, string $value): ?string
    {
        if (! preg_match('/^(.*?)(\d+)(\D*)$/', $value, $m)) {
            return null;
        }

        [, $stem, $digits, $suffix] = $m;

        $highest = (int) $digits;

        // LIKE may over-match (wildcards in the stem, case-insensitive collations);
        // the exact stem/suffix comparison below filters those out.
        $existing = $modelClass::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where($column, 'like', $stem.'%')
            ->pluck($column);

        foreach ($existing as $candidate) {
            if (preg_match('/^(.*?)(\d+)(\D*)$/', (string) $candidate, $c) && $c[1] === $stem && $c[3] === $suffix) {
                $highest = max($highest, (int) $c[2]);
            }
        }

        return $stem.str_pad((string) ($highest + 1), strlen($digits), '0', STR_PAD_LEFT).$suffix;
    }

    /**
     * Highest PREFIX-nnnnnn sequence number in use, plus one.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function nextSequenceFor(Company $company, string $modelClass, string $column, string $prefix): int
    {
        $numbers = $modelClass::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where($column, 'like', $prefix.'-%')
            ->pluck($column);

        $next = 1;

        foreach ($numbers as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)/', (string) $number, $m)) {
                $next = max($next, ((int) $m[1]) + 1);
            }
        }

        return $next;
    }

    /**
     * Walk forward from $candidate until a number nothing else owns turns up.
     * Returns null when the format cannot be incremented or the run is exhausted.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function firstFree(Company $company, string $modelClass, string $column, string $candidate): ?string
    {
        for ($probe = 0; $probe < self::MAX_PROBES; $probe++) {
            if (! $this->isTaken($company, $modelClass, $column, $candidate)) {
                return $candidate;
            }

            $next = self::incrementFormat($candidate);

            if ($next === null || $next === $candidate) {
                return null;
            }

            $candidate = $next;
        }

        return null;
    }

    /**
     * The number for the next cheque on a bank account: the last cheque written
     * on that account, incremented in its own format ("1001" → "1002",
     * "CHQ-009" → "CHQ-010"), or 1001 for a fresh chequebook. Cheque numbers
     * may repeat on an account ("EFT", "DD"), so this is a suggestion the user
     * can overwrite, not a reserved number.
     */
    public function nextChequeNumber(Company $company, ?int $bankAccountId): string
    {
        if (! $bankAccountId) {
            return '1001';
        }

        $last = Cheque::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('bank_account_id', $bankAccountId)
            ->orderByDesc('id')
            ->first();

        if ($last && ($next = self::incrementFormat((string) $last->cheque_no)) !== null) {
            return $next;
        }

        return '1001';
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
