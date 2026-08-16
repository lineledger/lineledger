<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentCompany;
use App\Models\User;
use App\Services\Security\TrustedDeviceManager;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    use RedirectsToCurrentCompany;

    public function toResponse($request): Response
    {
        $this->rememberDeviceIfRequested($request);

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 200)
            : redirect()->intended($this->redirectPathForCurrentCompany($request, Fortify::redirects('login')));
    }

    /**
     * When the user ticked "remember this device" on the challenge, trust the
     * device so future logins skip the 2FA prompt. Covers both OTP and recovery
     * code logins — they both resolve to this response. The trusted-device
     * cookie is queued onto the outgoing response by the manager.
     */
    private function rememberDeviceIfRequested($request): void
    {
        $user = $request->user();

        if ($request->boolean('remember_device') && $user instanceof User) {
            app(TrustedDeviceManager::class)->rememberDevice($user, $request);
        }
    }
}
