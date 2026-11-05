<?php

namespace App\Actions\Companies\Members;

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;

/**
 * Withdraw a pending invitation so its link stops working. Authorization
 * (CompanyPolicy@cancelInvitation) is the caller's job.
 */
class CancelInvitation
{
    public function __construct(private readonly SecurityLogRecorder $recorder) {}

    public function handle(Company $company, User $actor, CompanyInvitation $invitation): void
    {
        abort_unless($invitation->company_id === $company->id, 404);

        $invitation->delete();

        $this->recorder->record(SecurityEvent::CompanyInvitationCancelled, $actor, metadata: [
            'company_id' => $company->id,
            'invitation_email' => $invitation->email,
            'role' => $invitation->role->value,
        ]);
    }
}
