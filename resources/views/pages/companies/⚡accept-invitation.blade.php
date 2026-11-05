<?php

use App\Actions\Companies\Members\AcceptInvitation;
use App\Models\CompanyInvitation;
use Illuminate\Support\Facades\Auth;
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
        app(AcceptInvitation::class)->handle($this->invitation, Auth::user());

        $this->redirectRoute('dashboard');
    }
}; ?>

<div></div>
