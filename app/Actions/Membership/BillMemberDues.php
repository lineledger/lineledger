<?php

namespace App\Actions\Membership;

use App\Actions\Sales\SaveInvoice;
use App\Models\Invoice;
use App\Models\Member;
use App\Services\Posting\InvoicePoster;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bills a member's dues by generating an invoice through the standard sales flow,
 * reusing AR, payments, aging, and statements. Leaves the invoice as a Draft by
 * default (matching the recurring-document convention); pass ['post' => true] to
 * post it immediately. The invoice is linked back to the member via member_id.
 */
final class BillMemberDues
{
    public function __construct(
        protected SaveInvoice $saveInvoice,
        protected InvoicePoster $poster,
    ) {}

    /**
     * @param  array{post?: bool, date?: string}  $opts
     */
    public function handle(Member $member, array $opts = []): Invoice
    {
        $member->loadMissing('level');
        $level = $member->level;

        if ($level === null) {
            throw new RuntimeException('A membership level is required to bill dues.');
        }

        if ($level->revenue_account_id === null) {
            throw new RuntimeException("Membership level [{$level->name}] has no revenue account; set one before billing dues.");
        }

        $duesCents = $member->effectiveDuesCents();

        if ($duesCents <= 0) {
            throw new RuntimeException('The dues amount must be greater than zero.');
        }

        $company = app('current_company');
        $billDate = $opts['date'] ?? $company->currentDateTime()->toDateString();

        return DB::transaction(function () use ($member, $level, $duesCents, $billDate, $opts): Invoice {
            $invoice = $this->saveInvoice->handle([
                'contact_id' => $member->contact_id,
                'invoice_no' => null,
                'invoice_date' => $billDate,
                'terms_id' => $level->default_terms_id,
                'memo' => "Membership dues — {$level->name} ({$member->member_no})",
                'lines' => [[
                    'account_id' => $level->revenue_account_id,
                    'description' => "Membership dues: {$level->name}",
                    'quantity' => 1,
                    'unit_price_cents' => $duesCents,
                    'tax_code_id' => $level->default_tax_code_id,
                ]],
            ]);

            $invoice->forceFill(['member_id' => $member->id])->save();

            if ($opts['post'] ?? false) {
                $this->poster->post($invoice);
            }

            return $invoice->refresh();
        });
    }
}
