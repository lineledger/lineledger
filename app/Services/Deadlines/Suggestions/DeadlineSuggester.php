<?php

namespace App\Services\Deadlines\Suggestions;

use App\Enums\Country;
use App\Models\Company;
use App\Models\Deadline;

/**
 * The deadlines an organization probably has, worked out from what LineLedger
 * knows about it (country, province or state, organization type, fiscal year,
 * payroll, sales-tax registration) plus the choices on the suggestions page.
 */
final class DeadlineSuggester
{
    /**
     * @return list<SuggestedDeadline>
     */
    public static function for(Company $company, SuggestionChoices $choices): array
    {
        $today = $company->currentDateTime()->startOfDay();

        return match ($company->jurisdiction ?? Country::Canada) {
            Country::Canada => CanadaCatalog::for($company, $choices, $today),
            Country::UnitedKingdom => UnitedKingdomCatalog::for($company, $choices, $today),
            Country::UnitedStates => UnitedStatesCatalog::for($company, $choices, $today),
        };
    }

    /**
     * Template keys the organization already has (archived ones included, so
     * an archived suggestion isn't offered again as new).
     *
     * @return list<string>
     */
    public static function addedKeys(): array
    {
        return Deadline::query()->whereNotNull('template_key')->pluck('template_key')->unique()->values()->all();
    }
}
