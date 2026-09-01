<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'company_id',
    'default_sales_account_id',
    'show_logo',
    'show_company_info',
    'show_company_name',
    'show_legal_name',
    'show_company_address',
    'show_company_phone',
    'show_company_email',
    'show_company_website',
    'show_tax_number',
    'show_item_column',
    'show_qty_column',
    'show_tax_column',
    'show_terms',
    'show_template',
    'show_sales_rep',
    'show_customer_po',
    'show_ship_date',
    'show_ship_via',
    'show_fob',
    'show_tracking_no',
    'show_memo',
    'show_customer_message',
    'show_service_date_column',
    'hide_zero_qty_lines',
    'show_account_column',
    'show_unit_column',
    'show_discount_column',
    'show_markup_column',
    'show_class_column',
    'show_location_column',
    'show_document_discount',
    'show_payment_schedule',
    'footer_message',
    'email_from_address',
    'email_from_name',
    'email_default_message',
    'email_cc_self',
    'payment_instructions',
])]
class InvoiceSetting extends Model
{
    use BelongsToCompany;

    /**
     * Default values for a company that has never customised its invoice.
     *
     * @return array<string, bool|string|null>
     */
    public static function defaults(): array
    {
        return [
            'default_sales_account_id' => null,
            'show_logo' => true,
            'show_company_info' => true,
            'show_company_name' => true,
            'show_legal_name' => false,
            'show_company_address' => true,
            'show_company_phone' => true,
            'show_company_email' => false,
            'show_company_website' => false,
            'show_tax_number' => true,
            'show_item_column' => true,
            'show_qty_column' => true,
            // Hidden by default — owners re-show these from the invoice Fields menu.
            'show_tax_column' => false,
            'show_terms' => false,
            'show_template' => true,
            'show_sales_rep' => true,
            'show_customer_po' => false,
            'show_ship_date' => false,
            'show_ship_via' => false,
            'show_fob' => false,
            'show_tracking_no' => false,
            'show_memo' => true,
            'show_customer_message' => true,
            'show_service_date_column' => false,
            'hide_zero_qty_lines' => false,
            'show_account_column' => true,
            'show_unit_column' => true,
            'show_discount_column' => false,
            'show_markup_column' => false,
            // On by default, but only rendered when the company tracks the dimension.
            'show_class_column' => true,
            'show_location_column' => true,
            'show_document_discount' => false,
            'show_payment_schedule' => true,
            'footer_message' => null,
            'email_from_address' => null,
            'email_from_name' => null,
            'email_default_message' => null,
            'email_cc_self' => false,
            'payment_instructions' => null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'show_logo' => 'boolean',
            'show_company_info' => 'boolean',
            'show_company_name' => 'boolean',
            'show_legal_name' => 'boolean',
            'show_company_address' => 'boolean',
            'show_company_phone' => 'boolean',
            'show_company_email' => 'boolean',
            'show_company_website' => 'boolean',
            'show_tax_number' => 'boolean',
            'show_item_column' => 'boolean',
            'show_qty_column' => 'boolean',
            'show_tax_column' => 'boolean',
            'show_service_date_column' => 'boolean',
            'hide_zero_qty_lines' => 'boolean',
            'show_account_column' => 'boolean',
            'show_unit_column' => 'boolean',
            'show_discount_column' => 'boolean',
            'show_markup_column' => 'boolean',
            'show_class_column' => 'boolean',
            'show_location_column' => 'boolean',
            'show_document_discount' => 'boolean',
            'show_payment_schedule' => 'boolean',
            'show_terms' => 'boolean',
            'show_template' => 'boolean',
            'show_sales_rep' => 'boolean',
            'show_customer_po' => 'boolean',
            'show_ship_date' => 'boolean',
            'show_ship_via' => 'boolean',
            'show_fob' => 'boolean',
            'show_tracking_no' => 'boolean',
            'show_memo' => 'boolean',
            'show_customer_message' => 'boolean',
            'email_cc_self' => 'boolean',
        ];
    }
}
