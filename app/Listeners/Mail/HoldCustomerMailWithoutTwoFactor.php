<?php

namespace App\Listeners\Mail;

use App\Contracts\CustomerFacingNotification;
use App\Enums\SensitiveFeature;
use App\Models\User;
use App\Support\Security\SensitiveFeatureGate;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Log;

/**
 * The backstop for customer email kept behind two-factor authentication
 * ({@see SensitiveFeature::CustomerEmail}). Every interactive send checks the
 * gate first and tells the person why; this cancels any customer-facing
 * notification ({@see CustomerFacingNotification}) that gets this far anyway,
 * when it is actually sent (in the queue worker, for a queued one), if the
 * organization's owner hasn't turned two-factor authentication on.
 *
 * Only mail to people outside the organization: a Contact, or addresses typed
 * in that aren't all members'. A member is a User, and account mail
 * (verification, password reset, new-device alerts, invitations, support
 * replies) never implements the contract, so it is never held.
 *
 * Laravel's listener discovery registers it; adding an Event::listen() too
 * would run it twice per notification.
 */
final class HoldCustomerMailWithoutTwoFactor
{
    public function __construct(private readonly SensitiveFeatureGate $gate) {}

    /**
     * False cancels the send; null lets it go.
     */
    public function handle(NotificationSending $event): ?bool
    {
        $notification = $event->notification;

        if (! $notification instanceof CustomerFacingNotification
            || $event->notifiable instanceof User
            || ! $this->gate->requires(SensitiveFeature::CustomerEmail)) {
            return null;
        }

        $company = $notification->sendingCompany();

        if ($event->notifiable instanceof AnonymousNotifiable
            && $company->nonMemberEmails($this->addresses($event->notifiable, $event->channel)) === []) {
            return null;
        }

        if ($this->gate->allows(SensitiveFeature::CustomerEmail, $company)) {
            return null;
        }

        Log::info('Held a customer email: customer email needs the owner to turn on two-factor authentication.', [
            'company_id' => $company->id,
            'notification' => $notification::class,
        ]);

        return false;
    }

    /**
     * The addresses an on-demand notification is routed to on the channel,
     * whether given as a list or as address => name.
     *
     * @return list<string>
     */
    private function addresses(AnonymousNotifiable $notifiable, string $channel): array
    {
        $route = $notifiable->routeNotificationFor($channel);

        if (! is_array($route)) {
            return is_string($route) ? [$route] : [];
        }

        $addresses = [];

        foreach ($route as $key => $value) {
            $addresses[] = is_string($key) ? $key : (string) $value;
        }

        return $addresses;
    }
}
