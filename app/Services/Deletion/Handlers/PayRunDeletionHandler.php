<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Enums\PayrollChequeStatus;
use App\Enums\PayRunStatus;
use App\Enums\RemittanceStatus;
use App\Enums\SlipType;
use App\Models\PayrollCheque;
use App\Models\PayrollRemittance;
use App\Models\PayrollSlipFiling;
use App\Models\PayRun;
use App\Services\Deletion\DeletionBlocker;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\OwnsChildEntries;
use App\Services\Posting\PayRunPoster;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a pay run: payroll's strictest delete. A posted run feeds every
 * later run's year-to-date (CPP, EI and QPP maxima), the remittance for its
 * month and the year's T4 and RL-1 slips, so while it counts it refuses when
 * any of those has moved on: a later posted run for the same employees
 * (delete newest first), a remittance covering its pay date, or finalized
 * slips for the year. A posted payroll cheque refuses too, as voiding does;
 * draft and voided cheques go with the run.
 *
 * What the run did comes back out: its accruals are reversed (a voided run
 * already reversed them) and the time entries it paid are released.
 */
final class PayRunDeletionHandler extends DocumentDeletionHandler implements OwnsChildEntries
{
    /** Tables holding a pay run line's detail, deleted before the lines. */
    private const LINE_DETAIL_TABLES = [
        'pay_run_line_earnings',
        'pay_run_line_deductions',
        'pay_run_line_contributions',
        'pay_run_line_manual_earnings',
        'pay_run_line_accruals',
    ];

    public function __construct(private readonly PayRunPoster $poster) {}

    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::PayRun;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function contactColumn(): ?string
    {
        return null;
    }

    protected function totalColumn(): string
    {
        return 'gross_cents';
    }

    public function journalEntryIds(Model $document): array
    {
        $run = $document->getAttribute('journal_entry_id');

        return array_values(array_unique(array_merge(
            $run !== null ? [(int) $run] : [],
            DB::table('payroll_cheques')->where('pay_run_id', $document->getKey())->whereNotNull('journal_entry_id')->orderBy('id')->pluck('journal_entry_id')->map(fn ($id): int => (int) $id)->all(),
        )));
    }

    /**
     * Its payroll cheques: the draft and voided ones go with the run (a
     * posted one refuses the delete first).
     */
    public function childEntrySources(Model $document): array
    {
        return DB::table('payroll_cheques')
            ->where('pay_run_id', $document->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): array => [PayrollCheque::class, (int) $id])
            ->values()
            ->all();
    }

    public function blockers(Model $document): array
    {
        $run = $this->run($document);
        $blockers = [];

        $posted = DB::table('payroll_cheques')
            ->where('pay_run_id', $run->id)
            ->where('status', PayrollChequeStatus::Posted->value)
            ->orderBy('id')
            ->get(['cheque_no', 'payee_name']);

        foreach ($posted as $cheque) {
            $blockers[] = new DeletionBlocker(__('Payroll cheque :no to :name is posted. Void or delete it first.', ['no' => (string) $cheque->cheque_no, 'name' => (string) $cheque->payee_name]));
        }

        if (! $this->counts($run)) {
            return $blockers;
        }

        $payDate = CarbonImmutable::parse($run->pay_date);

        foreach ([SlipType::T4, SlipType::Rl1] as $slip) {
            $finalized = PayrollSlipFiling::withoutGlobalScopes()
                ->where('company_id', $run->company_id)
                ->where('slip_type', $slip->value)
                ->where('year', $payDate->year)
                ->exists();

            if ($finalized) {
                $blockers[] = new DeletionBlocker(__('The :year :slip slips are finalized, and this pay run is part of them. Unfinalize the year first.', ['year' => $payDate->year, 'slip' => strtoupper(str_replace('rl1', 'rl-1', $slip->value))]));
            }
        }

        $remittances = PayrollRemittance::withoutGlobalScopes()
            ->where('company_id', $run->company_id)
            ->where('status', '!=', RemittanceStatus::Void->value)
            ->where('period_start', '<=', $payDate->toDateString())
            ->where('period_end', '>=', $payDate->toDateString())
            ->orderBy('period_start')
            ->get();

        foreach ($remittances as $remittance) {
            $blockers[] = $this->blocker(
                $run,
                __('The :agency remittance for :start to :end covers this pay date. Void or delete the remittance first.', [
                    'agency' => $remittance->agency->label(),
                    'start' => $remittance->period_start->toDateString(),
                    'end' => $remittance->period_end->toDateString(),
                ]),
                'payroll.reports.remittances',
                [],
            );
        }

        foreach ($this->laterRuns($run, [PayRunStatus::Posted, PayRunStatus::Paid]) as $later) {
            $blockers[] = $this->blocker(
                $run,
                __('Pay run :no, paid :date, comes later in the year for the same employees, and its year-to-date figures count this one. Delete the newest pay run first.', ['no' => $later->run_no, 'date' => CarbonImmutable::parse($later->pay_date)->toDateString()]),
                'pay-runs.show',
                ['payRun' => $later->id],
            );
        }

        return $blockers;
    }

    public function warnings(Model $document): array
    {
        $run = $this->run($document);

        if (! $this->counts($run)) {
            return [];
        }

        return array_map(
            fn (PayRun $later): string => __('Pay run :no was calculated with this one in its year-to-date. Recalculate it afterwards.', ['no' => $later->run_no]),
            $this->laterRuns($run, [PayRunStatus::Calculated]),
        );
    }

    protected function extraSnapshot(Model $document): array
    {
        $lines = DB::table('pay_run_lines')
            ->leftJoin('contacts', 'contacts.id', '=', 'pay_run_lines.contact_id')
            ->where('pay_run_lines.pay_run_id', $document->getKey())
            ->orderBy('pay_run_lines.id')
            ->get(['contacts.display_name', 'pay_run_lines.gross_cents', 'pay_run_lines.net_cents']);

        return [
            'period_start' => $this->stringOrNull(CarbonImmutable::parse((string) $document->getAttribute('period_start_date'))->toDateString()),
            'period_end' => $this->stringOrNull(CarbonImmutable::parse((string) $document->getAttribute('period_end_date'))->toDateString()),
            'net_cents' => (int) $document->getAttribute('net_cents'),
            'employees' => $lines->map(fn ($line): array => [
                'name' => $line->display_name,
                'gross_cents' => (int) $line->gross_cents,
                'net_cents' => (int) $line->net_cents,
            ])->all(),
            'cheques' => DB::table('payroll_cheques')
                ->where('pay_run_id', $document->getKey())
                ->orderBy('id')
                ->get(['cheque_no', 'payee_name', 'amount_cents', 'status'])
                ->map(fn ($cheque): array => ['cheque_no' => $cheque->cheque_no, 'payee_name' => $cheque->payee_name, 'amount_cents' => (int) $cheque->amount_cents, 'status' => $cheque->status])
                ->all(),
        ];
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        $run = $this->run($document);

        // A posted run added to its employees' accrual balances; a voided one
        // has already taken that back.
        if ($this->counts($run)) {
            $this->poster->reverseAccrualBalances($run);
        }

        DB::table('time_entries')->where('pay_run_id', $run->id)->update(['pay_run_id' => null]);
        DB::table('payroll_cheques')->where('pay_run_id', $run->id)->delete();

        $lineIds = DB::table('pay_run_lines')->where('pay_run_id', $run->id)->pluck('id');

        foreach (self::LINE_DETAIL_TABLES as $table) {
            DB::table($table)->whereIn('pay_run_line_id', $lineIds)->delete();
        }

        DB::table('pay_run_lines')->where('pay_run_id', $run->id)->delete();
    }

    /**
     * Whether the run counts toward its employees' year-to-date: posted (or
     * paid) and not voided.
     */
    private function counts(PayRun $run): bool
    {
        return $run->journal_entry_id !== null && in_array($run->status, [PayRunStatus::Posted, PayRunStatus::Paid], true);
    }

    /**
     * Runs later in the same calendar year, in one of these statuses, that pay
     * any of this run's employees.
     *
     * @param  list<PayRunStatus>  $statuses
     * @return list<PayRun>
     */
    private function laterRuns(PayRun $run, array $statuses): array
    {
        $payDate = CarbonImmutable::parse($run->pay_date);
        $date = $payDate->toDateString();
        $contactIds = DB::table('pay_run_lines')->where('pay_run_id', $run->id)->pluck('contact_id')->all();

        if ($contactIds === []) {
            return [];
        }

        return PayRun::withoutGlobalScopes()
            ->where('company_id', $run->company_id)
            ->whereKeyNot($run->id)
            ->whereIn('status', array_map(fn (PayRunStatus $s): string => $s->value, $statuses))
            ->where('pay_date', '<=', $payDate->endOfYear()->toDateString())
            ->where(fn ($q) => $q->where('pay_date', '>', $date)->orWhere(fn ($q) => $q->where('pay_date', $date)->where('id', '>', $run->id)))
            ->whereIn('id', DB::table('pay_run_lines')->select('pay_run_id')->whereIn('contact_id', $contactIds))
            ->orderBy('pay_date')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function run(Model $document): PayRun
    {
        $this->own($document);

        return $document instanceof PayRun ? $document : PayRun::withoutGlobalScopes()->findOrFail($document->getKey());
    }
}
