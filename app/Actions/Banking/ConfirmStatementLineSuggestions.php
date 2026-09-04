<?php

namespace App\Actions\Banking;

use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use Illuminate\Support\Facades\DB;

/**
 * Turns pre-filled suggestions into confirmed "Add" lines (match_status =
 * Created) without posting anything — posting happens on commit. Suggestions
 * are pre-fill only, so this is the one explicit step between "the app thinks
 * this is Contractors" and "add it to my books". A line already confirmed is
 * left alone, so a double click is harmless.
 */
final class ConfirmStatementLineSuggestions
{
    /**
     * @param  list<int>|null  $lineIds  restrict to these lines; null = every unconfirmed suggestion on the import
     * @return int lines confirmed
     */
    public function handle(BankStatementImport $import, ?array $lineIds = null): int
    {
        if ($import->isCommitted()) {
            throw new PostingValidationException(__('This statement has already been imported.'));
        }

        return DB::transaction(function () use ($import, $lineIds): int {
            $query = $import->lines()->unconfirmedSuggestions();

            if ($lineIds !== null) {
                $query->whereIn('id', array_map('intval', $lineIds));
            }

            $count = 0;

            foreach ($query->get() as $line) {
                $line->forceFill(['match_status' => StatementLineMatchStatus::Created->value])->save();
                $count++;
            }

            return $count;
        });
    }

    /**
     * Confirm one line. Returns false (no-op) when it carries no unconfirmed suggestion.
     */
    public function handleLine(BankStatementLine $line): bool
    {
        if (! $line->hasUnconfirmedSuggestion()) {
            return false;
        }

        $line->forceFill(['match_status' => StatementLineMatchStatus::Created->value])->save();

        return true;
    }
}
