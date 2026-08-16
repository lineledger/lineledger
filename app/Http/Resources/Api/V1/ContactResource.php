<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'company_name' => $this->company_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_number' => $this->tax_number,
            'is_customer' => (bool) $this->is_customer,
            'is_vendor' => (bool) $this->is_vendor,
            'is_employee' => (bool) $this->is_employee,
            'invoice_emails_enabled' => (bool) $this->invoice_emails_enabled,
            'reminder_emails_enabled' => (bool) $this->reminder_emails_enabled,
            'billing_address' => [
                'line1' => $this->billing_line1,
                'line2' => $this->billing_line2,
                'city' => $this->billing_city,
                'region' => $this->billing_region,
                'postal_code' => $this->billing_postal_code,
                'country' => $this->billing_country,
            ],
            'shipping_address' => [
                'line1' => $this->shipping_line1,
                'line2' => $this->shipping_line2,
                'city' => $this->shipping_city,
                'region' => $this->shipping_region,
                'postal_code' => $this->shipping_postal_code,
                'country' => $this->shipping_country,
            ],
            'default_terms_id' => $this->default_terms_id,
            'default_tax_code_id' => $this->default_tax_code_id,
            'default_income_account_id' => $this->default_income_account_id,
            'notes' => $this->notes,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
