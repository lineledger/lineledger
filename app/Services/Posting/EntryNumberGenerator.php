<?php

namespace App\Services\Posting;

use App\Models\Company;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the next sequential entry number per company.
 * Format: JE-{6-digit-zero-padded-counter}, e.g. JE-000001.
 */
class EntryNumberGenerator
{
    public function next(Company $company): string
    {
        return DB::transaction(function () use ($company) {
            $last = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('entry_no', 'like', 'JE-%')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $nextSeq = 1;

            if ($last && preg_match('/JE-(\d+)/', $last->entry_no, $m)) {
                $nextSeq = ((int) $m[1]) + 1;
            }

            return 'JE-'.str_pad((string) $nextSeq, 6, '0', STR_PAD_LEFT);
        });
    }
}
