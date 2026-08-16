<?php

use App\Enums\SecurityEvent;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Companies')] class extends Component {
    public CompanyInvitation $invitation;

    public function mount(CompanyInvitation $invitation): void
    {
        $this->invitation = $invitation;

        $this->acceptInvitation();
    }

    public function acceptInvitation(): void
    {
        $user = Auth::user();

        $this->validateInvitation($user, $this->invitation);

        $company = $this->invitation->company;
        $membership = null;

        DB::transaction(function () use ($user, $company, &$membership) {
            $membership = $company->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => $this->invitation->role,
                    'sections' => $this->invitation->sections,
                ]
            );

            $this->invitation->update(['accepted_at' => now()]);

            $user->switchCompany($company);
        });

        // Only a genuinely new membership is a provisioning event; re-accepting
        // an already-joined invitation (firstOrCreate found an existing row)
        // should not log a spurious "joined".
        if ($membership?->wasRecentlyCreated) {
            app(SecurityLogRecorder::class)->record(SecurityEvent::CompanyMemberJoined, $user, metadata: [
                'company_id' => $company->id,
                'role' => $this->invitation->role->value,
            ]);
        }

        $this->redirectRoute('dashboard');
    }

    private function validateInvitation(User $user, CompanyInvitation $invitation): void
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has already been accepted.')],
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation has expired.')],
            ]);
        }

        if (Str::lower($invitation->email) !== Str::lower($user->email)) {
            throw ValidationException::withMessages([
                'invitation' => [__('This invitation was sent to a different email address.')],
            ]);
        }
    }
}; ?>

<div></div>
