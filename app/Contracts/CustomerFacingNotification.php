<?php

namespace App\Contracts;

use App\Enums\SensitiveFeature;
use App\Listeners\Mail\HoldCustomerMailWithoutTwoFactor;
use App\Models\Company;

/**
 * A notification an organization sends to someone outside it: a customer or
 * an employee (a Contact), or addresses typed in by a member. Never account
 * mail (verification, password reset, new-device alerts, invitations, support
 * replies), which goes to a User.
 *
 * While the installation keeps {@see SensitiveFeature::CustomerEmail} behind
 * two-factor authentication, {@see HoldCustomerMailWithoutTwoFactor} cancels
 * one whose organization's owner hasn't turned it on.
 */
interface CustomerFacingNotification
{
    /**
     * The organization the email is sent for.
     */
    public function sendingCompany(): Company;
}
