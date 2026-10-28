<?php

namespace App\Enums;

/**
 * The page layouts an invoice can print with, chosen per form style. Every
 * layout gets the same view data from InvoicePdfRenderer and includes the
 * shared pdf/invoices/partials; InvoiceLayoutParityTest renders each case, so
 * a layout that leaves out something the others print fails there.
 */
enum InvoiceLayout: string
{
    case Classic = 'classic';
    case Airy = 'airy';

    public function label(): string
    {
        return match ($this) {
            self::Classic => __('Classic'),
            self::Airy => __('Airy'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Classic => __('Boxed header, bill-to and ship-to side by side'),
            self::Airy => __('Open layout, logo top right, balance due'),
        };
    }

    /**
     * The Blade view that renders this layout.
     */
    public function view(): string
    {
        return match ($this) {
            self::Classic => 'pdf.invoices.invoice',
            self::Airy => 'pdf.invoices.airy',
        };
    }
}
