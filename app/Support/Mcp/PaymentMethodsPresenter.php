<?php

namespace App\Support\Mcp;

use App\Mcp\Resources\PaymentMethodsResource;
use App\Mcp\Tools\PaymentMethodsTool;
use App\Models\Company;
use App\Models\PaymentMethod;

/**
 * Renders a company's payment methods as plain text for the MCP server. Shared
 * by {@see PaymentMethodsResource} and its companion {@see PaymentMethodsTool}.
 * Payment methods are seeded per company and referenced by receipts and bill
 * payments as `payment_method_id` (see {@see ApiIdNote}).
 */
class PaymentMethodsPresenter
{
    public function render(Company $company): string
    {
        $methods = PaymentMethod::query()->orderBy('name')->get();

        if ($methods->isEmpty()) {
            return "{$company->name} has no payment methods configured.";
        }

        $lines = [
            "Payment methods for {$company->name} ({$methods->count()}):",
            ApiIdNote::for('payment_method_id'),
            '',
        ];

        foreach ($methods as $method) {
            $meta = [];

            // Cheque methods are the ones that drive cheque numbering and printing,
            // so a caller picking a method for a payment needs to know which is which.
            if ($method->is_cheque) {
                $meta[] = 'cheque';
            }
            if (! $method->is_active) {
                $meta[] = 'inactive';
            }
            $meta[] = "API id {$method->id}";

            $lines[] = "• {$method->name} (".implode(', ', $meta).')';
        }

        return implode("\n", $lines);
    }
}
