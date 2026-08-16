<?php

namespace App\Support\Mcp;

use App\Enums\TaxAppliesTo;
use App\Mcp\Resources\TaxCodesResource;
use App\Mcp\Tools\TaxCodesTool;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Models\TaxCode;

/**
 * Renders a company's tax agencies and tax codes as plain text for the MCP
 * server. Shared by {@see TaxCodesResource} and its companion
 * {@see TaxCodesTool}. Both levels carry a numeric API id — agencies the
 * `agency_id`, codes the `tax_code_id` (see {@see ApiIdNote}).
 */
class TaxCodesPresenter
{
    public function render(Company $company): string
    {
        $taxLabel = $company->jurisdiction->taxLabel();

        $agencies = TaxAgency::query()
            ->with(['taxCodes' => fn ($query) => $query->orderBy('code')])
            ->orderBy('name')
            ->get();

        if ($agencies->isEmpty()) {
            return "No tax agencies or {$taxLabel} codes are configured for {$company->name}.";
        }

        $lines = [
            "{$taxLabel} codes for {$company->name}:",
            ApiIdNote::forWritable('tax_code_id'),
            'Agency lines carry their own id instead — that one is the `agency_id`.',
            '',
        ];

        foreach ($agencies as $agency) {
            $registration = filled($agency->registration_number) ? " (reg. {$agency->registration_number})" : '';
            $status = $agency->is_active ? '' : ' [inactive]';
            $lines[] = "{$agency->name}{$registration}{$status} (API id {$agency->id})";

            if ($agency->taxCodes->isEmpty()) {
                $lines[] = '  (no tax codes)';
            }

            foreach ($agency->taxCodes as $code) {
                $lines[] = '  - '.$this->codeLine($code);
            }

            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function codeLine(TaxCode $code): string
    {
        $rate = rtrim(rtrim(number_format($code->ratePercent(), 3, '.', ''), '0'), '.');
        $applies = match ($code->applies_to) {
            TaxAppliesTo::SaleOnly => 'sales',
            TaxAppliesTo::PurchaseOnly => 'purchases',
            TaxAppliesTo::Both => 'sales & purchases',
            default => 'unspecified',
        };
        $recoverable = $code->is_recoverable ? 'recoverable' : 'non-recoverable';
        $inactive = $code->is_active ? '' : ', inactive';

        return "{$code->code} {$code->name}: {$rate}% ({$applies}, {$recoverable}{$inactive}, API id {$code->id})";
    }
}
