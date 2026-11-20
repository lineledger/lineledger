<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Enums\PayrollChequeStatus;
use App\Enums\PayRunStatus;
use App\Services\Deletion\DeletionWork;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a payroll cheque. The employee's net pay goes back to owing in the
 * run (Net Pay Clearing), so a Paid run reverts to Posted, as voiding the
 * cheque does, and a new cheque can be written for that employee. Its number
 * stays spent on the bank account.
 */
final class PayrollChequeDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::PayrollCheque;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function contactColumn(): string
    {
        return 'payee_contact_id';
    }

    protected function totalColumn(): string
    {
        return 'amount_cents';
    }

    public function summary(Model $document): array
    {
        return [
            ...parent::summary($document),
            'bank_account_id' => $document->getAttribute('bank_account_id') !== null ? (int) $document->getAttribute('bank_account_id') : null,
        ];
    }

    protected function extraSnapshot(Model $document): array
    {
        return [
            'pay_run_no' => $this->stringOrNull(DB::table('pay_runs')->where('id', $document->getAttribute('pay_run_id'))->value('run_no')),
        ];
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        $status = $document->getAttribute('status');

        if ($status === PayrollChequeStatus::Posted || $status === PayrollChequeStatus::Posted->value) {
            DB::table('pay_runs')
                ->where('id', $document->getAttribute('pay_run_id'))
                ->where('status', PayRunStatus::Paid->value)
                ->update(['status' => PayRunStatus::Posted->value, 'updated_at' => now()]);
        }
    }
}
