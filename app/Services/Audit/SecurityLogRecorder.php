<?php

namespace App\Services\Audit;

use App\Enums\SecurityEvent;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityLogRecorder
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        SecurityEvent $event,
        ?User $user = null,
        ?string $attemptedEmail = null,
        ?array $metadata = null,
    ): SecurityLog {
        $request = $this->currentRequest();

        return SecurityLog::query()->create([
            'recorded_at' => now()->format('Y-m-d H:i:s.u'),
            'user_id' => $user?->id,
            'attempted_email' => $attemptedEmail,
            'company_id' => $user?->current_company_id,
            'event' => $event->value,
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 512),
            'metadata' => $metadata,
        ]);
    }

    protected function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }
}
