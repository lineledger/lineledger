<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Database\Factories\FormStyleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A named sales-form template. Per-field overrides for InvoiceSetting when
 * rendering an invoice PDF: the layout, logo visibility, accent colour and
 * footer message. One style per company may be the default. Invoices only for v1.
 */
#[Fillable(['company_id', 'name', 'layout', 'is_default', 'show_logo', 'accent_color', 'footer_message', 'is_active'])]
class FormStyle extends Model
{
    /** @use HasFactory<FormStyleFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    public const LAYOUT_CLASSIC = 'classic';

    public const LAYOUT_AIRY = 'airy';

    /**
     * The invoice layouts a style can print with, keyed by stored value.
     *
     * @return array<string, string>
     */
    public static function layouts(): array
    {
        return [
            self::LAYOUT_CLASSIC => __('Classic — boxed header, bill-to and ship-to'),
            self::LAYOUT_AIRY => __('Airy — open layout, logo top right, balance due'),
        ];
    }

    /**
     * The Blade view an invoice in this style renders with.
     */
    public function invoiceView(): string
    {
        return $this->layout === self::LAYOUT_AIRY ? 'pdf.invoices.airy' : 'pdf.invoices.invoice';
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'show_logo' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
