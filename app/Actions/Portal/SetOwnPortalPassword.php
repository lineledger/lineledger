<?php

namespace App\Actions\Portal;

use App\Enums\SecurityEvent;
use App\Models\Contact;
use App\Models\SecurityLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Sets or changes the employee's own portal sign-in password. First-time setup
 * (no password yet) requires no current password — the employee proved control
 * of their inbox via the magic link that signed them in. Once a password
 * exists, changing it requires the current one. Operates strictly on the
 * passed-in authenticated Contact, never on an id taken from the request, and
 * every change is recorded to the immutable {@see SecurityLog}.
 */
final class SetOwnPortalPassword
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Contact $employee, array $input): Contact
    {
        $isFirstTime = $employee->portal_password === null;

        Validator::make($input, [
            'current_password' => $isFirstTime ? ['nullable', 'string'] : ['required', 'string'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ])->after(function ($validator) use ($employee, $input, $isFirstTime): void {
            if (! $isFirstTime && ! Hash::check((string) ($input['current_password'] ?? ''), (string) $employee->portal_password)) {
                $validator->errors()->add('current_password', __('The provided password does not match your current password.'));
            }
        })->validate();

        // Deliberately not mass-assignable: portal_password is excluded from
        // the #[Fillable] list, so this explicit assignment (hashed by the
        // model's cast) is the only write path.
        $employee->portal_password = $input['password'];
        $employee->save();

        SecurityLog::create([
            'recorded_at' => now(),
            'user_id' => null,
            'company_id' => $employee->company_id,
            'event' => SecurityEvent::EmployeePortalPasswordChanged,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            'metadata' => [
                'contact_id' => $employee->id,
                'first_time_setup' => $isFirstTime,
            ],
        ]);

        return $employee;
    }
}
