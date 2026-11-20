<?php

namespace App\Enums;

/**
 * Which part of a customer statement a document line belongs to: the
 * organization's own services and goods, disbursements — costs paid out on
 * the customer's behalf and passed through at cost (a death certificate, a
 * filing fee, airfare) — or tax charged as a line of its own (a "GST (5%)"
 * line posted straight to GST payable, as imported QuickBooks invoices do).
 * Statements total the three separately.
 */
enum StatementSection: string
{
    case Services = 'services';
    case Disbursement = 'disbursement';
    case Tax = 'tax';
}
