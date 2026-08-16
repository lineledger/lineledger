<?php

namespace App\Rules;

use App\Models\Company;
use App\Models\CompanyInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UniqueCompanyInvitation implements ValidationRule
{
    public function __construct(protected Company $company)
    {
        //
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower($value);

        $isMember = $this->company->members()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();

        if ($isMember) {
            $fail(__('This user is already a member of the company.'));

            return;
        }

        $hasPendingInvitation = CompanyInvitation::where('company_id', $this->company->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($hasPendingInvitation) {
            $fail(__('An invitation has already been sent to this email address.'));
        }
    }
}
