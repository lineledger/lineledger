<?php

namespace App\Livewire\Concerns;

use App\Models\OpeningBalanceState;
use App\Services\OpeningBalances\OpeningBalanceJournalSynchronizer;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;

/**
 * Shared behaviour for the Opening Balances workspace pages: resolves (or
 * creates) the company's one OpeningBalanceState, runs the auto-apply after a
 * mutation, and exposes the Retry action the blocked-apply banner binds to.
 * The host component must expose `public Company $company` and call
 * {@see resolveObState()} in mount().
 */
trait InteractsWithOpeningBalances
{
    public OpeningBalanceState $obState;

    protected function resolveObState(): void
    {
        $this->obState = OpeningBalanceState::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->company->id],
            [
                'as_of_date' => OpeningBalanceState::defaultAsOfDate($this->company)->toDateString(),
                'created_by_user_id' => Auth::id(),
            ],
        );
    }

    /**
     * Auto-apply after a mutation. Lock errors are recorded on the state and
     * surfaced by the heading banner rather than failing the save.
     */
    protected function applyToBooks(): void
    {
        app(OpeningBalanceJournalSynchronizer::class)->applyQuietly($this->obState->refresh());
        $this->obState->refresh();
    }

    public function retryApply(): void
    {
        $this->obState->refresh();

        if ($this->obState->isFinalized()) {
            return;
        }

        app(OpeningBalanceJournalSynchronizer::class)->applyQuietly($this->obState);
        $this->obState->refresh();

        if ($this->obState->apply_error) {
            Flux::toast(variant: 'danger', text: $this->obState->apply_error);
        } else {
            Flux::toast(variant: 'success', text: __('Books updated to match the draft.'));
        }
    }

    /** True when edits are allowed; toasts and returns false when finalized. */
    protected function obEditable(): bool
    {
        if ($this->obState->refresh()->isFinalized()) {
            Flux::toast(variant: 'danger', text: __('Opening balances are finalized. Un-finalize them on the Overview tab first.'));

            return false;
        }

        return true;
    }
}
