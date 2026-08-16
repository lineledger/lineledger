<?php

namespace App\Enums;

/**
 * How a single parsed statement line relates to the existing general ledger.
 *
 *  - Unmatched: no book entry found; the user can "Add" one (becomes Created).
 *  - Suggested: a probable match the user should confirm.
 *  - Matched:   a confident match to an existing posted journal line (auto-ticked).
 *  - Created:   the user chose to create a new journal entry from this line.
 *  - Ignored:   the user excluded this line from the reconciliation.
 *  - Duplicate: already imported/cleared previously (by FITID or fingerprint).
 */
enum StatementLineMatchStatus: string
{
    case Unmatched = 'unmatched';
    case Suggested = 'suggested';
    case Matched = 'matched';
    case Created = 'created';
    case Ignored = 'ignored';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Unmatched',
            self::Suggested => 'Suggested',
            self::Matched => 'Matched',
            self::Created => 'Add',
            self::Ignored => 'Ignored',
            self::Duplicate => 'Duplicate',
        };
    }

    /**
     * Statuses that contribute a cleared line to the reconciliation on commit:
     * a confirmed match clears an existing line, a created line clears the new one.
     */
    public function clearsALine(): bool
    {
        return in_array($this, [self::Matched, self::Created], true);
    }
}
