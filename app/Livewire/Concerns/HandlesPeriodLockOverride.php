<?php

namespace App\Livewire\Concerns;

use App\Enums\AuditAction;
use App\Enums\PeriodLockMode;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Company;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Posting\PeriodLockOverride;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;

/**
 * Lets a form post into the company's closed period after the user confirms,
 * per the company's {@see PeriodLockMode}: in Warn mode anyone who can use the
 * form confirms; in Block mode only an Owner or Admin can, by re-entering their
 * password. Render <x-period-lock-override :company="$company" :message="$periodLockMessage" />
 * in the view and offer the override from the action's existing catch block:
 *
 *     } catch (PeriodLockedException $e) {
 *         if ($this->offerPeriodLockOverride($e, 'save')) {
 *             return;
 *         }
 *         $this->addError('form', $e->getMessage());
 *     }
 *
 * Confirming re-runs that public action inside a {@see PeriodLockOverride}, and
 * every closed-period date it crossed is written to the audit trail.
 */
trait HandlesPeriodLockOverride
{
    /** The public action to re-run once the override is confirmed. */
    #[Locked]
    public ?string $periodLockRetryAction = null;

    #[Locked]
    public string $periodLockMessage = '';

    public string $periodLockPassword = '';

    /**
     * Open the override dialog for the action that just hit the lock date, if
     * the company's lock mode lets this user through. Returns false (show the
     * error as usual) when it doesn't.
     */
    protected function offerPeriodLockOverride(PeriodLockedException $e, string $retryAction): bool
    {
        // Already overriding: whatever failed now isn't something a second
        // confirmation would fix.
        if (PeriodLockOverride::isActive()) {
            return false;
        }

        $company = $this->periodLockCompany();

        if ($company->lockMode() === PeriodLockMode::Block && ! Gate::allows('update', $company)) {
            return false;
        }

        $this->periodLockRetryAction = $retryAction;
        $this->periodLockMessage = $e->getMessage();
        $this->periodLockPassword = '';
        $this->resetErrorBag('periodLockPassword');

        Flux::modal('period-lock-override')->show();

        return true;
    }

    public function confirmPeriodLockOverride(): void
    {
        $action = $this->periodLockRetryAction;

        if ($action === null) {
            return;
        }

        $company = $this->periodLockCompany()->fresh() ?? $this->periodLockCompany();
        $mode = $company->lockMode();

        if ($mode === PeriodLockMode::Block) {
            Gate::authorize('update', $company);

            if (! Hash::check($this->periodLockPassword, (string) Auth::user()?->password)) {
                $this->addError('periodLockPassword', __('The password is incorrect.'));

                return;
            }
        }

        $this->periodLockRetryAction = null;
        $this->periodLockPassword = '';
        $this->resetErrorBag();
        Flux::modal('period-lock-override')->close();

        [, $crossed] = PeriodLockOverride::run($company, fn () => app()->call([$this, $action]));

        // Nothing was written into the closed period if the re-run stopped on
        // another error (posters roll back their transaction when they throw).
        if ($crossed === [] || $this->getErrorBag()->isNotEmpty()) {
            return;
        }

        app(AccountingAuditRecorder::class)->record(
            (int) $company->id,
            AuditAction::PeriodLockOverridden,
            $company,
            [
                'mode' => $mode->value,
                'lock_date' => $company->lock_date?->toDateString(),
                'dates' => $crossed,
                'form' => $this->getName(),
                'action' => $action,
            ],
        );
    }

    protected function periodLockCompany(): Company
    {
        return $this->company;
    }
}
