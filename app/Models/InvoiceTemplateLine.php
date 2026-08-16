<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'invoice_template_id', 'company_id', 'item_id', 'account_id', 'description',
    'quantity', 'unit_price_cents', 'line_discount_pct', 'line_markup_pct',
    'tax_code_id', 'secondary_tax_code_id', 'class_id', 'location_id', 'line_order',
])]
class InvoiceTemplateLine extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<InvoiceTemplate, $this>
     */
    public function invoiceTemplate(): BelongsTo
    {
        return $this->belongsTo(InvoiceTemplate::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function secondaryTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'secondary_tax_code_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'class_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price_cents' => 'integer',
            'line_discount_pct' => 'decimal:4',
            'line_markup_pct' => 'decimal:4',
            'line_order' => 'integer',
        ];
    }
}
