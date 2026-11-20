<?php

namespace App\Services\Posting;

use App\Enums\AuditAction;
use App\Enums\PayrollAccount;
use App\Enums\PayRunStatus;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeeRecurringItem;
use App\Models\JournalEntry;
use App\Models\PayrollCheque;
use App\Models\PayRun;
use App\Models\PayRunLine;
use App\Models\PayRunLineEarning;
use App\Models\TimeEntry;
use App\Models\TimeOffPolicy;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Payroll\PayrollYtdService;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a pay run as one balanced journal entry on the pay date:
 *   DR  Wages & Salaries Expense  (per employee, grouped by account/class/location)
 *   DR  Employer CPP/QPP Expense  (employer CPP + CPP2, or QPP + QPP2 for Quebec)
 *   DR  Employer EI Expense
 *   DR  Employer QPIP Expense     (Quebec only)
 *   DR  QHSF / CNESST Expense     (Quebec employer levies)
 *   DR  Vacation Pay Expense      (accrue policy only)
 *     CR  CPP Payable             (employee + employer CPP + CPP2)        — CRA
 *     CR  QPP Payable             (employee + employer QPP + QPP2)        — Revenu Québec
 *     CR  EI Payable              (employee + employer; Quebec EI too)    — CRA
 *     CR  QPIP Payable            (employee + employer)                   — Revenu Québec
 *     CR  Income Tax Payable      (federal abated for QC + provincial + additional) — CRA
 *     CR  Quebec Income Tax Payable (Quebec provincial tax)               — Revenu Québec
 *     CR  QHSF / CNESST Payable   (Quebec employer levies)                — Revenu Québec
 *     CR  Vacation Payable        (accrue policy only)
 *     CR  <voluntary payables>    (per liability account)
 *     CR  Net Pay Clearing        (per employee net; drained as cheques post)
 *
 * Accrued pay taken settles its liability instead of being expensed twice:
 *   DR  Vacation Payable (or the accrual's own liability)  (vacation pay drawn from an accrued dollar balance)
 *   DR  Banked Time Payable                                (banked time taken, liability mode)
 *     CR  Wages & Salaries Expense                         (… backing out the wage debit for that pay)
 *
 * Quebec amounts are 0 on rest-of-Canada lines and vice-versa, so the {@see addLeg}
 * zero-guard suppresses inapplicable legs and one balanced JE serves pure-ROC,
 * pure-QC, and mixed runs alike. The CRA-vs-Revenu-Québec split is a reporting
 * concern (see the remittance reports), not a posting concern.
 *
 * Net pay reaches the bank only when each {@see PayrollCheque} posts
 * (DR Net Pay Clearing / CR Bank), so bank reconciliation stays per-cheque.
 */
class PayRunPoster
{
    public function __construct(
        protected JournalPoster $journalPoster,
        protected EntryNumberGenerator $entryNumbers,
        protected PayrollAccountResolver $accounts,
        protected AccountingAuditRecorder $auditRecorder,
        protected PayrollConstantsRepository $constants,
        protected PayrollYtdService $ytd,
    ) {}

    public function post(PayRun $payRun): JournalEntry
    {
        return DB::transaction(fn () => AuditMute::silence(function () use ($payRun) {
            $payRun->loadMissing('lines.earnings', 'lines.deductions', 'lines.contributions', 'lines.accruals', 'company');

            if ($payRun->journal_entry_id) {
                throw AlreadyPostedException::for((int) $payRun->journal_entry_id);
            }

            // Strictly Calculated — a Draft run either was never calculated
            // (empty snapshots → a 0=0 journal) or was edited after calculation
            // (stale snapshots that no longer match its lines/hours).
            if ($payRun->refresh()->status !== PayRunStatus::Calculated) {
                throw new RuntimeException('Only a calculated pay run can be posted — calculate it first.');
            }

            if ($payRun->lines->isEmpty()) {
                throw new RuntimeException('Pay run has no employees; cannot post.');
            }

            $payDate = CarbonImmutable::parse($payRun->pay_date);

            if ($payRun->company->isLockedFor($payDate)) {
                throw PeriodLockedException::for($payDate, CarbonImmutable::parse($payRun->company->lock_date));
            }

            // Posting never re-runs the engine, so refuse amounts no loaded tax
            // table stands behind, e.g. a run calculated before the engine refused
            // pay dates past the last loaded year.
            foreach ($payRun->lines->pluck('province_of_employment')->unique() as $province) {
                $this->constants->resolve((string) $province, $payDate);
            }

            // The amounts were calculated against the year-to-date of every run
            // that counted at the time. Refuse while an earlier calculated run
            // for the same employee is unposted, when that year-to-date has
            // changed since, or when a line would take posted CPP/CPP2/EI/QPP/
            // QPP2/QPIP past an annual maximum (audit D6Payroll-12).
            $this->ytd->assertPostable($payRun);

            $entry = JournalEntry::create([
                'entry_no' => $this->entryNumbers->next($payRun->company),
                'entry_date' => $payRun->pay_date,
                'memo' => 'Payroll '.$payRun->run_no,
                'source_type' => PayRun::class,
                'source_id' => $payRun->id,
            ]);

            $this->buildLines($payRun, $entry);

            $entry->refresh();
            $this->journalPoster->post($entry);

            $payRun->forceFill([
                'status' => PayRunStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => Auth::id(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->applyAccrualBalances($payRun, 1);

            $entry = $entry->fresh();

            $this->auditRecorder->record(
                (int) $payRun->company_id,
                AuditAction::PayRunPosted,
                $payRun,
                [
                    'run_no' => $payRun->run_no,
                    'pay_date' => $payDate->toDateString(),
                    'gross_cents' => (int) $payRun->gross_cents,
                    'net_cents' => (int) $payRun->net_cents,
                    'journal_entry_id' => (int) $entry->id,
                    'journal' => AccountingAuditRecorder::snapshotJournalEntry($entry),
                ],
                $entry,
            );

            return $entry;
        }));
    }

    public function void(PayRun $payRun, ?CarbonImmutable $voidDate = null): void
    {
        DB::transaction(fn () => AuditMute::silence(function () use ($payRun, $voidDate) {
            $payRun->loadMissing('journalEntry', 'cheques');

            if (! $payRun->journal_entry_id) {
                throw new RuntimeException('Pay run is not posted.');
            }

            if ($payRun->status === PayRunStatus::Void) {
                throw new RuntimeException('Pay run is already voided.');
            }

            if ($payRun->cheques->contains(fn ($cheque) => $cheque->status->value === 'posted')) {
                throw new RuntimeException('Void the pay run cheques before voiding the run.');
            }

            $this->journalPoster->void($payRun->journalEntry, $voidDate, "Void of payroll {$payRun->run_no}");

            $payRun->forceFill([
                'status' => PayRunStatus::Void,
                'voided_at' => now(),
                'voided_by_user_id' => Auth::id(),
            ])->save();

            // Release any time entries this run consumed so they can be re-pulled.
            TimeEntry::query()->where('pay_run_id', $payRun->id)->update(['pay_run_id' => null]);

            $this->applyAccrualBalances($payRun, -1);

            $this->auditRecorder->record(
                (int) $payRun->company_id,
                AuditAction::PayRunVoided,
                $payRun,
                [
                    'run_no' => $payRun->run_no,
                    'voided_at' => optional($payRun->voided_at)->format('Y-m-d H:i:s.u'),
                    'journal_entry_id' => (int) $payRun->journal_entry_id,
                ],
                $payRun->journalEntry,
            );
        }));
    }

    protected function buildLines(PayRun $payRun, JournalEntry $entry): void
    {
        $company = $payRun->company;
        $defaultWageAccount = $this->accounts->resolve($company, PayrollAccount::WagesExpense);

        /** @var array<int, array{account_id: int, debit: int, credit: int, contact_id: ?int, class_id: ?int, location_id: ?int, memo: ?string}> $legs */
        $legs = [];

        $totalEmployerCpp = 0; // Employer CPP + QPP (both expense to 6210).
        $totalEmployerEi = 0;
        $totalEmployerQpip = 0;
        $totalQhsf = 0;
        $totalCnesst = 0;
        $totalWc = 0;
        $totalVacationAccrued = 0;
        $totalCppPayable = 0;
        $totalQppPayable = 0;
        $totalEiPayable = 0;
        $totalQpipPayable = 0;
        $totalIncomeTaxPayable = 0;     // CRA: federal (abated for QC) + provincial (0 for QC) + additional.
        $totalQuebecTaxPayable = 0;     // Revenu Québec: provincial income tax (0 for the rest of Canada).
        $voluntaryByAccount = [];
        $contribExpenseByAccount = []; // DR employer-contribution expense, grouped by account
        $contribLiabByAccount = [];    // CR employer-contribution liability, grouped by account
        $accrualExpenseByAccount = []; // DR dollar-accrual expense, grouped by account
        $accrualLiabByAccount = [];    // CR dollar-accrual liability, grouped by account
        $bankedReliefCredits = [];     // CR wage expense backing out banked-time settlements
        $totalBankedRelief = 0;        // DR Banked Time Payable
        $accruedPayDebits = [];        // DR accrual liability for vacation pay taken, grouped by account
        $accruedPayCredits = [];       // CR wage expense backing it out, keyed like the wage debits
        $wageByKey = [];

        foreach ($payRun->lines as $line) {
            // Wage expense per employee, grouped by account + dimensions.
            foreach ($line->earnings as $earning) {
                // Bases-only earnings (taxable benefits) are non-cash and carry no
                // wage expense — their employer cost posts via the contribution leg
                // below. Skipping them keeps the entry balanced (wage DR == net + the
                // employee deductions), since they were excluded from net too.
                if ($earning->add_to_bases_only) {
                    continue;
                }

                $accountId = $earning->expense_account_id ?? $defaultWageAccount->id;
                $key = $accountId.':'.($earning->class_id ?? '').':'.($earning->location_id ?? '').':'.$line->contact_id;
                $wageByKey[$key] ??= [
                    'account_id' => $accountId,
                    'debit' => 0,
                    'credit' => 0,
                    'contact_id' => $line->contact_id,
                    'class_id' => $earning->class_id,
                    'location_id' => $earning->location_id,
                    'memo' => 'Wages',
                ];
                $wageByKey[$key]['debit'] += (int) $earning->amount_cents;
            }

            // Taking banked time settles the liability built when the hours were
            // banked. EVIDENCE-based — the employee's banked balance tracks
            // dollars — never the live company toggle, so takes always settle in
            // the mode the bank was built (and a toggle flipped between post and
            // void can't desynchronize anything). Relief = hours × the line's
            // effective hourly rate (frozen line data, so identical at void):
            // DR Banked Time Payable / CR wage expense — backing out the cash
            // wage debit for an hourly take, or recognizing a salaried $0 take
            // as settled from the liability.
            foreach ($line->earnings as $earning) {
                if ($earning->code !== 'banked' || (float) $earning->hours <= 0) {
                    continue;
                }

                $relief = $this->bankedReliefCents($company, $line, (float) $earning->hours);

                if ($relief <= 0) {
                    continue;
                }

                $totalBankedRelief += $relief;
                $creditAccount = $earning->expense_account_id ?? $defaultWageAccount->id;
                $bankedReliefCredits[$creditAccount] = ($bankedReliefCredits[$creditAccount] ?? 0) + $relief;
            }

            // Vacation pay taken from an accrued dollar balance was expensed when
            // it accrued, so the part the balance covers settles the accrual's
            // liability instead of being expensed again as wages: DR the liability
            // / CR the wage expense the earning debited, tagged like that debit so
            // the employee's (and the class's) wages net to what wasn't accrued.
            foreach ($this->settleAccruedPay($company, $line) as ['earning' => $earning, 'cents' => $cents, 'liability_account_id' => $liabilityId]) {
                $accruedPayDebits[$liabilityId] = ($accruedPayDebits[$liabilityId] ?? 0) + $cents;

                $accountId = $earning->expense_account_id ?? $defaultWageAccount->id;
                $key = $accountId.':'.($earning->class_id ?? '').':'.($earning->location_id ?? '').':'.$line->contact_id;
                $accruedPayCredits[$key] ??= [
                    'account_id' => $accountId,
                    'debit' => 0,
                    'credit' => 0,
                    'contact_id' => $line->contact_id,
                    'class_id' => $earning->class_id,
                    'location_id' => $earning->location_id,
                    'memo' => 'Vacation pay taken',
                ];
                $accruedPayCredits[$key]['credit'] += $cents;
            }

            // Employer CPP and QPP both expense to 6210 (one is always 0 per line).
            $totalEmployerCpp += $line->cppEmployerCents() + $line->cpp2EmployerCents()
                + $line->qppEmployerCents() + $line->qpp2EmployerCents();
            $totalEmployerEi += $line->eiEmployerCents();
            $totalEmployerQpip += $line->qpipEmployerCents();
            $totalQhsf += $line->qhsfEmployerCents();
            $totalCnesst += $line->cnesstEmployerCents();
            $totalWc += $line->wcEmployerCents();
            $totalVacationAccrued += (int) $line->vacation_accrued_cents;

            $totalCppPayable += $line->cppEmployeeCents() + $line->cppEmployerCents() + $line->cpp2EmployeeCents() + $line->cpp2EmployerCents();
            $totalQppPayable += $line->qppEmployeeCents() + $line->qppEmployerCents() + $line->qpp2EmployeeCents() + $line->qpp2EmployerCents();
            $totalEiPayable += $line->eiEmployeeCents() + $line->eiEmployerCents();
            $totalQpipPayable += $line->qpipEmployeeCents() + $line->qpipEmployerCents();
            // Federal/provincial/additional remit to the CRA; Quebec tax remits to Revenu Québec.
            $totalIncomeTaxPayable += $line->federalTaxCents() + $line->provincialTaxCents() + $line->additionalTaxCents();
            $totalQuebecTaxPayable += $line->quebecTaxCents();

            foreach ($line->deductions as $deduction) {
                $accountId = $deduction->liability_account_id ?? $this->accounts->resolve($company, PayrollAccount::BenefitsPayable)->id;
                $voluntaryByAccount[$accountId] = ($voluntaryByAccount[$accountId] ?? 0) + (int) $deduction->amount_cents;
            }

            // Employer contributions: DR expense / CR liability. Equal debit + credit
            // keep the entry balanced, and net pay clearing is untouched.
            foreach ($line->contributions as $contribution) {
                $expenseId = $contribution->expense_account_id
                    ?? $this->accounts->resolve($company, PayrollAccount::EmployerBenefitsExpense)->id;
                $liabId = $contribution->liability_account_id
                    ?? $this->accounts->resolve($company, PayrollAccount::BenefitsPayable)->id;
                $contribExpenseByAccount[$expenseId] = ($contribExpenseByAccount[$expenseId] ?? 0) + (int) $contribution->amount_cents;
                $contribLiabByAccount[$liabId] = ($contribLiabByAccount[$liabId] ?? 0) + (int) $contribution->amount_cents;
            }

            // Dollar accruals (accrued pay): DR expense / CR liability. Hour accruals
            // carry $0 here — they only move the employee balance. Banked-time
            // accruals default to wage expense + Banked Time Payable; everything
            // else keeps the vacation defaults.
            foreach ($line->accruals as $accrual) {
                $amount = (int) $accrual->amount_cents;

                if ($amount === 0) {
                    continue;
                }

                $isBanked = $accrual->code === 'banked';

                $expenseId = $accrual->expense_account_id
                    ?? ($isBanked ? $defaultWageAccount->id : $this->accounts->resolve($company, PayrollAccount::VacationExpense)->id);
                $liabId = $accrual->liability_account_id
                    ?? $this->accounts->resolve($company, $isBanked ? PayrollAccount::BankedTimePayable : PayrollAccount::VacationPayable)->id;
                $accrualExpenseByAccount[$expenseId] = ($accrualExpenseByAccount[$expenseId] ?? 0) + $amount;
                $accrualLiabByAccount[$liabId] = ($accrualLiabByAccount[$liabId] ?? 0) + $amount;
            }

            // Net pay credited to clearing, tagged per employee.
            if ($line->net_cents !== 0) {
                $legs[] = [
                    'account_id' => $this->accounts->resolve($company, PayrollAccount::NetPayClearing)->id,
                    'debit' => 0,
                    'credit' => (int) $line->net_cents,
                    'contact_id' => $line->contact_id,
                    'class_id' => null,
                    'location_id' => null,
                    'memo' => 'Net pay',
                ];
            }
        }

        // Wage debits.
        foreach ($wageByKey as $leg) {
            $legs[] = $leg;
        }

        // Employer-cost debits. QPIP/QHSF/CNESST are 0 unless the run has QC employees.
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::EmployerCppExpense), $totalEmployerCpp, 0, 'Employer CPP/QPP');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::EmployerEiExpense), $totalEmployerEi, 0, 'Employer EI');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::EmployerQpipExpense), $totalEmployerQpip, 0, 'Employer QPIP');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::QhsfExpense), $totalQhsf, 0, 'QHSF');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::CnesstExpense), $totalCnesst, 0, 'CNESST');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::WorkersCompExpense), $totalWc, 0, "Workers' compensation");
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::VacationExpense), $totalVacationAccrued, 0, 'Vacation accrual');

        // Liability credits. CRA payables (CPP/EI/income tax) and Revenu Québec
        // payables (QPP/QPIP/Quebec tax/QHSF/CNESST) sit side by side; the agency
        // split is a reporting concern, so one balanced JE serves every run.
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::CppPayable), 0, $totalCppPayable, 'CPP payable');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::QppPayable), 0, $totalQppPayable, 'QPP payable');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::EiPayable), 0, $totalEiPayable, 'EI payable');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::QpipPayable), 0, $totalQpipPayable, 'QPIP payable');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::IncomeTaxPayable), 0, $totalIncomeTaxPayable, 'Income tax payable');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::QuebecIncomeTaxPayable), 0, $totalQuebecTaxPayable, 'Quebec income tax payable');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::QhsfPayable), 0, $totalQhsf, 'QHSF payable');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::CnesstPayable), 0, $totalCnesst, 'CNESST payable');
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::WorkersCompPayable), 0, $totalWc, "Workers' compensation payable");
        $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::VacationPayable), 0, $totalVacationAccrued, 'Vacation payable');

        foreach ($voluntaryByAccount as $accountId => $amount) {
            if ($amount !== 0) {
                $legs[] = ['account_id' => (int) $accountId, 'debit' => 0, 'credit' => $amount, 'contact_id' => null, 'class_id' => null, 'location_id' => null, 'memo' => 'Payroll deduction'];
            }
        }

        foreach ($contribExpenseByAccount as $accountId => $amount) {
            if ($amount !== 0) {
                $legs[] = ['account_id' => (int) $accountId, 'debit' => $amount, 'credit' => 0, 'contact_id' => null, 'class_id' => null, 'location_id' => null, 'memo' => 'Employer contribution'];
            }
        }

        foreach ($contribLiabByAccount as $accountId => $amount) {
            if ($amount !== 0) {
                $legs[] = ['account_id' => (int) $accountId, 'debit' => 0, 'credit' => $amount, 'contact_id' => null, 'class_id' => null, 'location_id' => null, 'memo' => 'Employer contribution payable'];
            }
        }

        foreach ($accrualExpenseByAccount as $accountId => $amount) {
            if ($amount !== 0) {
                $legs[] = ['account_id' => (int) $accountId, 'debit' => $amount, 'credit' => 0, 'contact_id' => null, 'class_id' => null, 'location_id' => null, 'memo' => 'Accrual'];
            }
        }

        foreach ($accrualLiabByAccount as $accountId => $amount) {
            if ($amount !== 0) {
                $legs[] = ['account_id' => (int) $accountId, 'debit' => 0, 'credit' => $amount, 'contact_id' => null, 'class_id' => null, 'location_id' => null, 'memo' => 'Accrual payable'];
            }
        }

        // Banked time taken: settle the liability built at banking time.
        if ($totalBankedRelief > 0) {
            $this->addLeg($legs, $this->accounts->resolve($company, PayrollAccount::BankedTimePayable), $totalBankedRelief, 0, 'Banked time taken');

            foreach ($bankedReliefCredits as $accountId => $amount) {
                $legs[] = ['account_id' => (int) $accountId, 'debit' => 0, 'credit' => $amount, 'contact_id' => null, 'class_id' => null, 'location_id' => null, 'memo' => 'Banked time taken'];
            }
        }

        // Vacation pay taken: settle the liability each accrual built.
        foreach ($accruedPayDebits as $accountId => $amount) {
            $legs[] = ['account_id' => (int) $accountId, 'debit' => $amount, 'credit' => 0, 'contact_id' => null, 'class_id' => null, 'location_id' => null, 'memo' => 'Vacation pay taken'];
        }

        foreach ($accruedPayCredits as $leg) {
            $legs[] = $leg;
        }

        $order = 0;

        foreach ($legs as $leg) {
            if ($leg['debit'] === 0 && $leg['credit'] === 0) {
                continue;
            }

            $entry->lines()->create([
                'account_id' => $leg['account_id'],
                'debit_cents' => $leg['debit'],
                'credit_cents' => $leg['credit'],
                'memo' => $leg['memo'],
                'contact_id' => $leg['contact_id'],
                'class_id' => $leg['class_id'],
                'location_id' => $leg['location_id'],
                'line_order' => $order++,
            ]);
        }
    }

    /**
     * Move each employee's accrual + time-off balances by a run: increment on post
     * (sign +1), reverse on void (sign −1). Three movements per line:
     *   - accruals earned → balance + accrued-YTD up (hours or dollars);
     *   - vacation accrued → the 'vacation' dollar balance up, mirrored to the
     *     profile's vacation_balance_cents;
     *   - time off taken (an earning whose code has a balance) → balance down,
     *     used-YTD up: the hours it carries (a dollar policy has no hours side),
     *     and the accrued pay it settled at posting (frozen on the earning).
     */
    /**
     * Take back what posting the run added to (and drew from) each employee's
     * accrual balances: deleting a posted run, as voiding one does.
     */
    public function reverseAccrualBalances(PayRun $payRun): void
    {
        $this->applyAccrualBalances($payRun, -1);
    }

    protected function applyAccrualBalances(PayRun $payRun, int $sign): void
    {
        $payRun->loadMissing('lines.accruals', 'lines.earnings', 'lines.profile');
        $company = $payRun->company;

        foreach ($payRun->lines as $line) {
            $profileId = $line->employee_payroll_profile_id;

            if (! $profileId) {
                continue;
            }

            // Earned: accrual rows raise the balance + accrued YTD.
            foreach ($line->accruals as $accrual) {
                $balance = $this->balanceFor((int) $payRun->company_id, (int) $profileId, (string) $accrual->code, (string) $accrual->name);
                $balance->balance_hours = (float) $balance->balance_hours + $sign * (float) $accrual->hours;
                $balance->balance_cents = (int) $balance->balance_cents + $sign * (int) $accrual->amount_cents;
                $balance->accrued_ytd_hours = (float) $balance->accrued_ytd_hours + $sign * (float) $accrual->hours;
                $balance->accrued_ytd_cents = (int) $balance->accrued_ytd_cents + $sign * (int) $accrual->amount_cents;
                $balance->save();
            }

            // Vacation accrued (accrue policy): a dollar 'vacation' balance, mirrored
            // onto the profile column for quick display.
            if ((int) $line->vacation_accrued_cents !== 0) {
                $vac = $this->balanceFor((int) $payRun->company_id, (int) $profileId, 'vacation', __('Vacation'));
                $vac->balance_cents = (int) $vac->balance_cents + $sign * (int) $line->vacation_accrued_cents;
                $vac->accrued_ytd_cents = (int) $vac->accrued_ytd_cents + $sign * (int) $line->vacation_accrued_cents;
                $vac->save();

                if ($line->profile) {
                    $line->profile->forceFill(['vacation_balance_cents' => (int) $vac->balance_cents])->save();
                }
            }

            // Taken: an earning whose code matches a balance draws it down + raises
            // used YTD. Earnings with no matching balance are ignored.
            foreach ($line->earnings as $earning) {
                $balance = EmployeeAccrualBalance::withoutGlobalScopes()
                    ->where('company_id', $payRun->company_id)
                    ->where('employee_payroll_profile_id', $profileId)
                    ->where('code', $earning->code)
                    ->first();

                if ($balance === null) {
                    continue;
                }

                // Posted before settlements were recorded on the earning: reverse
                // its draw by the rules it was posted under.
                if ($earning->accrual_relief_cents === null) {
                    $this->applyUnrecordedTake($payRun, $line, $balance, $earning, $sign);

                    continue;
                }

                $hours = (float) $earning->hours;
                $settled = (int) $earning->accrual_relief_cents;

                // Hours draw the hours side — except a dollar policy's, which has
                // none (its take is measured by the dollars it settled).
                if ($hours !== 0.0 && ! $this->policyFor($company, (string) $balance->code)?->isDollarUnit()) {
                    $balance->balance_hours = (float) $balance->balance_hours - $sign * $hours;
                    $balance->used_ytd_hours = (float) $balance->used_ytd_hours + $sign * $hours;

                    // A dollar-tracked banked balance settles its cents side too,
                    // mirroring the GL relief legs: EVIDENCE-based (the balance
                    // tracks dollars) with the same frozen-line-data formula, so
                    // post and void move identical amounts no matter how the
                    // company liability toggle has changed in between.
                    if ($balance->code === 'banked' && $this->balanceTracksDollars($balance)) {
                        $relief = (int) round($hours * $this->lineHourlyRateCents($company, $line));
                        $balance->balance_cents = (int) $balance->balance_cents - $sign * $relief;
                        $balance->used_ytd_cents = (int) $balance->used_ytd_cents + $sign * $relief;
                    }
                }

                // The accrued pay this take settled from the liability draws the
                // dollar side by the same frozen amount (see settleAccruedPay()).
                if ($settled !== 0) {
                    $balance->balance_cents = (int) $balance->balance_cents - $sign * $settled;
                    $balance->used_ytd_cents = (int) $balance->used_ytd_cents + $sign * $settled;
                }

                $balance->save();

                if ($settled !== 0 && $balance->code === 'vacation' && $line->profile) {
                    $line->profile->forceFill(['vacation_balance_cents' => (int) $balance->balance_cents])->save();
                }
            }
        }
    }

    /**
     * Draw (or, on void, restore) a take posted before accrual settlements were
     * recorded on the earning, exactly as the poster of the time did: hours
     * from the hours side, and a dollar amount from a dollar-tracked balance in
     * full. None of that run's journal relieved a liability for it.
     */
    private function applyUnrecordedTake(PayRun $payRun, PayRunLine $line, EmployeeAccrualBalance $balance, PayRunLineEarning $earning, int $sign): void
    {
        $hours = (float) $earning->hours;
        $amount = (int) $earning->amount_cents;

        if ($hours !== 0.0) {
            $balance->balance_hours = (float) $balance->balance_hours - $sign * $hours;
            $balance->used_ytd_hours = (float) $balance->used_ytd_hours + $sign * $hours;

            if ($balance->code === 'banked' && $this->balanceTracksDollars($balance)) {
                $relief = (int) round($hours * $this->lineHourlyRateCents($payRun->company, $line));
                $balance->balance_cents = (int) $balance->balance_cents - $sign * $relief;
                $balance->used_ytd_cents = (int) $balance->used_ytd_cents + $sign * $relief;
            }

            $balance->save();
        } elseif ($amount !== 0 && ((int) $balance->balance_cents !== 0 || (int) $balance->accrued_ytd_cents !== 0 || $balance->code === 'vacation')) {
            $balance->balance_cents = (int) $balance->balance_cents - $sign * $amount;
            $balance->used_ytd_cents = (int) $balance->used_ytd_cents + $sign * $amount;
            $balance->save();

            if ($balance->code === 'vacation' && $line->profile) {
                $line->profile->forceFill(['vacation_balance_cents' => (int) $balance->balance_cents])->save();
            }
        }
    }

    /**
     * Freeze on each of the line's earnings how much of it was paid out of the
     * employee's accrued DOLLAR balance for its code — vacation pay taken — and
     * return the ones that settled something, with the liability their accrual
     * credits.
     *
     * A take is worth what it paid; a $0 row on a paid leave policy (a salaried
     * employee's day off, which the salary pays) is worth its hours at the
     * line's hourly rate, as banked time is. It settles at most what has
     * accrued: the balance plus this run's accrual for the code (the balance
     * takes accruals before draws). Pay beyond that is still paid in full and
     * stays wage expense, and a balance at or below zero settles nothing.
     * Banked time keeps its own relief ({@see bankedReliefCents()}).
     *
     * The amount is stored on the earning (0 when nothing settles), so a void
     * returns the balance by exactly what posting drew, whatever has moved it
     * since.
     *
     * @return list<array{earning: PayRunLineEarning, cents: int, liability_account_id: int}>
     */
    private function settleAccruedPay(Company $company, PayRunLine $line): array
    {
        /** @var array<string, int> $room */
        $room = [];

        if ($line->employee_payroll_profile_id) {
            $balances = EmployeeAccrualBalance::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('employee_payroll_profile_id', $line->employee_payroll_profile_id)
                ->get();

            foreach ($balances as $balance) {
                $room[(string) $balance->code] = (int) $balance->balance_cents;
            }

            foreach ($line->accruals as $accrual) {
                $room[(string) $accrual->code] = ($room[(string) $accrual->code] ?? 0) + (int) $accrual->amount_cents;
            }

            $room['vacation'] = ($room['vacation'] ?? 0) + (int) $line->vacation_accrued_cents;
        }

        $settled = [];

        foreach ($line->earnings as $earning) {
            $code = (string) $earning->code;
            $available = $room[$code] ?? 0;
            $cents = 0;

            if ($available > 0 && $code !== 'banked' && ! $earning->add_to_bases_only && ! $earning->add_to_net_pay_only) {
                $cents = min($available, $this->accruedPayTakenCents($company, $line, $earning));
                $room[$code] = $available - $cents;
            }

            $earning->forceFill(['accrual_relief_cents' => $cents])->save();

            if ($cents > 0) {
                $settled[] = [
                    'earning' => $earning,
                    'cents' => $cents,
                    'liability_account_id' => $this->accruedPayLiabilityId($company, $line, $code),
                ];
            }
        }

        return $settled;
    }

    /**
     * What a take of accrued pay is worth: the cash it paid, or — for a $0
     * row on a PAID leave policy, whose hours the salary already pays — its
     * hours at the line's effective hourly rate. Unpaid leave is worth nothing.
     */
    private function accruedPayTakenCents(Company $company, PayRunLine $line, PayRunLineEarning $earning): int
    {
        $amount = (int) $earning->amount_cents;

        if ($amount !== 0) {
            return max(0, $amount);
        }

        $hours = (float) $earning->hours;

        if ($hours <= 0 || ! $this->policyFor($company, (string) $earning->code)?->paid) {
            return 0;
        }

        return (int) round($hours * $this->lineHourlyRateCents($company, $line));
    }

    /**
     * The liability a code's dollar accrual credits, so a take settles the
     * account its accrual built: a dollar time-off policy's own account, else
     * an accrual item's, else Vacation Payable (where the built-in vacation
     * accrual and any accrual without an account of its own go).
     */
    private function accruedPayLiabilityId(Company $company, PayRunLine $line, string $code): int
    {
        $policy = $this->policyFor($company, $code);

        $accountId = $policy?->isDollarUnit()
            ? $policy->liability_account_id
            : EmployeeRecurringItem::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('employee_payroll_profile_id', $line->employee_payroll_profile_id)
                ->where('kind', 'accrual')
                ->where('code', $code)
                ->value('liability_account_id');

        return (int) ($accountId ?? $this->accounts->resolve($company, PayrollAccount::VacationPayable)->id);
    }

    /** The company's time-off policy for a code, if one exists (active or not). */
    private function policyFor(Company $company, string $code): ?TimeOffPolicy
    {
        return TimeOffPolicy::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->first();
    }

    /**
     * Fetch (or start) an employee's running balance for a code, refreshing its
     * display name.
     */
    private function balanceFor(int $companyId, int $profileId, string $code, string $name): EmployeeAccrualBalance
    {
        $balance = EmployeeAccrualBalance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_payroll_profile_id', $profileId)
            ->where('code', $code)
            ->first()
            ?? new EmployeeAccrualBalance([
                'company_id' => $companyId,
                'employee_payroll_profile_id' => $profileId,
                'code' => $code,
            ]);

        $balance->name = $name;

        return $balance;
    }

    /**
     * Whether the employee's banked balance carries a dollar side (it was built
     * under the liability mode). The settlement of a banked take keys off this
     * EVIDENCE, never the live company toggle, so the GL relief and the
     * subledger draw stay in the mode the bank was built — symmetrically at
     * post and void.
     */
    private function balanceTracksDollars(EmployeeAccrualBalance $balance): bool
    {
        return (int) $balance->balance_cents !== 0
            || (int) $balance->accrued_ytd_cents !== 0
            || (int) $balance->used_ytd_cents !== 0;
    }

    /**
     * The dollar value of a banked-time take: hours × the line's effective
     * hourly rate — frozen line data, so a void recomputes the identical
     * amount. Zero when the bank carries no dollar side (hours-only mode).
     */
    private function bankedReliefCents(Company $company, PayRunLine $line, float $hours): int
    {
        if (! $line->employee_payroll_profile_id) {
            return 0;
        }

        $balance = EmployeeAccrualBalance::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('employee_payroll_profile_id', $line->employee_payroll_profile_id)
            ->where('code', 'banked')
            ->first();

        if ($balance === null || ! $this->balanceTracksDollars($balance)) {
            return 0;
        }

        return (int) round($hours * $this->lineHourlyRateCents($company, $line));
    }

    /**
     * The line's effective hourly rate — the stated hourly rate, or the salary
     * spread over the company's standard annual hours. Mirrors
     * {@see CalculatePayRun::effectiveHourlyRateCents()}
     * (frozen line columns, so post and void always agree).
     */
    private function lineHourlyRateCents(Company $company, PayRunLine $line): int
    {
        if ($line->hourly_rate_cents) {
            return (int) $line->hourly_rate_cents;
        }

        if ($line->annual_salary_cents) {
            $standardHours = (int) ($company->payroll_standard_annual_hours ?: 2080);

            return $standardHours > 0
                ? (int) round((int) $line->annual_salary_cents / $standardHours)
                : 0;
        }

        return 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     */
    protected function addLeg(array &$legs, Account $account, int $debit, int $credit, string $memo): void
    {
        if ($debit === 0 && $credit === 0) {
            return;
        }

        $legs[] = [
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'contact_id' => null,
            'class_id' => null,
            'location_id' => null,
            'memo' => $memo,
        ];
    }
}
