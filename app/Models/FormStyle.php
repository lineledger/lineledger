<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Database\Factories\FormStyleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A named sales-form template. Per-field overrides for InvoiceSetting when
 * rendering an invoice PDF: logo visibility, accent colour, footer message.
 * One style per company may be the default. Invoices only for v1.
 */
#[Fillable(['company_id', 'name', 'is_default', 'show_logo', 'accent_color', 'footer_message', 'is_active'])]
class FormStyle extends Model
{
    /** @use HasFactory<FormStyleFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'show_logo' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
