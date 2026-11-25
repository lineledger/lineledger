<?php

namespace App\Enums;

use App\Support\Security\SensitiveFeatureGate;

/**
 * A feature an installation can put behind two-factor authentication, listed
 * in `security.two_factor_required_for` (TWO_FACTOR_REQUIRED_FOR). See
 * {@see SensitiveFeatureGate}.
 */
enum SensitiveFeature: string
{
    /** Every email to a customer or employee: invoices, reminders, statements, portal links, reports to outsiders. */
    case CustomerEmail = 'customer_email';

    /** Receipt OCR, AI categorization, AI bank-statement import and AI-written daily insights. */
    case Ai = 'ai';

    /** Inviting people into the organization. */
    case Invitations = 'invitations';

    /** Creating API keys and connecting an assistant over MCP. */
    case Api = 'api';

    /** Connecting a Stripe account for card payments. */
    case StripeConnect = 'stripe_connect';

    /**
     * What the feature is, as the subject of a sentence.
     */
    public function label(): string
    {
        return match ($this) {
            self::CustomerEmail => __('Emailing customers and employees'),
            self::Ai => __('AI features'),
            self::Invitations => __('Inviting people'),
            self::Api => __('API and AI assistant access'),
            self::StripeConnect => __('Connecting Stripe'),
        };
    }
}
