<?php

namespace App\Actions\Deadlines;

use App\Models\Company;
use App\Models\Deadline;
use App\Services\Deadlines\Suggestions\DeadlineSuggester;
use App\Services\Deadlines\Suggestions\SuggestionChoices;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the suggested deadlines the user ticked, all assigned to one member
 * and tracked from one date. Keys the organization already has are skipped,
 * so adding twice never duplicates.
 */
final class AddSuggestedDeadlines
{
    public function __construct(private readonly SaveDeadline $save) {}

    /**
     * @param  list<string>  $keys
     * @return list<Deadline>
     *
     * @throws ValidationException
     */
    public function handle(Company $company, SuggestionChoices $choices, array $keys, ?int $assigneeId, string $trackFrom): array
    {
        $existing = DeadlineSuggester::addedKeys();
        $suggestions = collect(DeadlineSuggester::for($company, $choices))
            ->filter(fn ($s) => in_array($s->key, $keys, true) && ! in_array($s->key, $existing, true));

        if ($suggestions->isEmpty()) {
            throw ValidationException::withMessages(['selected' => __('Tick at least one deadline that isn’t already added.')]);
        }

        if ($suggestions->contains(fn ($s) => $s->key === 'us.state_sales_tax') && $choices->stateSalesTaxDay === null) {
            throw ValidationException::withMessages(['state_sales_tax_day' => __('Enter the day of the month your state sales tax is due.')]);
        }

        return DB::transaction(fn (): array => $suggestions
            ->map(fn ($s) => $this->save->handle($company, [
                ...$s->payload(),
                'assigned_user_id' => $assigneeId,
                'track_from' => $trackFrom,
            ]))
            ->values()
            ->all());
    }
}
