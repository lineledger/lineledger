<?php

namespace App\Actions\Payroll;

use App\Enums\PayrollChequeStatus;
use App\Models\PayrollCheque;
use App\Models\PayRun;
use App\Services\Posting\PayrollChequePoster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues and posts one payroll cheque per positive-net employee of a posted pay
 * run, numbering them sequentially from the given starting number. Employees
 * with zero/negative net get no cheque. Idempotent per line: a line that already
 * has a cheque is skipped.
 */
final class IssuePayrollCheques
{
    public function __construct(private PayrollChequePoster $poster) {}

    /**
     * @return Collection<int, PayrollCheque>
     */
    public function handle(PayRun $payRun, int $startingChequeNumber, ?int $bankAccountId = null): Collection
    {
        return DB::transaction(function () use ($payRun, $startingChequeNumber, $bankAccountId): Collection {
            $payRun->loadMissing('lines.contact', 'cheques');

            if (! $payRun->isPosted()) {
                throw new RuntimeException('Post the pay run before writing cheques.');
            }

            $bankAccountId ??= $payRun->bank_account_id;

            if ($bankAccountId === null) {
                throw new RuntimeException('Select a bank account before writing cheques.');
            }

            $alreadyIssued = $payRun->cheques->pluck('pay_run_line_id')->all();
            $number = $startingChequeNumber;
            $issued = collect();

            foreach ($payRun->lines as $line) {
                if ((int) $line->net_cents <= 0 || in_array($line->id, $alreadyIssued, true)) {
                    continue;
                }

                $cheque = PayrollCheque::create([
                    'pay_run_id' => $payRun->id,
                    'pay_run_line_id' => $line->id,
                    'bank_account_id' => $bankAccountId,
                    'cheque_no' => (string) $number,
                    'cheque_date' => $payRun->pay_date,
                    'payee_contact_id' => $line->contact_id,
                    'payee_name' => $line->contact->display_name,
                    'amount_cents' => (int) $line->net_cents,
                    'status' => PayrollChequeStatus::Draft,
                ]);

                $this->poster->post($cheque);

                $issued->push($cheque->fresh());
                $number++;
            }

            return $issued;
        });
    }
}
