<?php

namespace App\Livewire\Concerns;

use App\Actions\Lists\DeleteListRecord;
use App\Enums\PermissionAction;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Exceptions\EditLocks\RecordEditLockedException;
use App\Livewire\Attributes\Requires;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Delete on a list page (customers, accounts, items, tax codes, …): removes
 * an inactive record nothing uses, through DeleteListRecord, which says why
 * when it can't. Pages offer the button on inactive rows only, calling
 * deleteListRecord(id) behind a wire:confirm.
 */
trait DeletesListRecords
{
    /**
     * The page's records, already narrowed to what the page lists (customers,
     * not every contact). Scoped to the organization by BelongsToCompany.
     *
     * @return Builder<Model>
     */
    abstract protected function listRecordQuery(): Builder;

    #[Requires(null, PermissionAction::Delete)]
    public function deleteListRecord(int $id, DeleteListRecord $action): void
    {
        $record = $this->listRecordQuery()->findOrFail($id);
        $user = Auth::user();

        try {
            $action->handle($record, $user instanceof User ? $user : null);
        } catch (DeletionBlockedException|RecordEditLockedException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Deleted.'));
    }
}
