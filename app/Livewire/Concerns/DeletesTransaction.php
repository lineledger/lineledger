<?php

namespace App\Livewire\Concerns;

use App\Enums\DeletableDocumentType;
use App\Enums\PermissionAction;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Exceptions\EditLocks\RecordEditLockedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Livewire\Attributes\GuardsEditLock;
use App\Livewire\Attributes\Requires;
use App\Services\Deletion\DeletionBlocker;
use App\Services\Deletion\TransactionDeleter;
use App\Services\Posting\PeriodLockOverride;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Delete on a transaction's show page (QuickBooks Desktop's Edit › Delete),
 * for a draft, posted or voided document. Pair it with
 * <x-transaction-delete-dialog> and <x-period-lock-override> in the view:
 *
 *     <x-transaction-delete-dialog :preview="$deletionPreview" :document="$invoice" />
 *     <x-period-lock-override :company="$company" :message="$periodLockMessage" />
 *
 * prepareDelete() works out what the delete would do and opens the dialog;
 * confirmDelete() deletes through {@see TransactionDeleter}, offering the
 * period-lock override when the transaction is dated in a closed period.
 *
 * The page needs a `public Company $company` and uses ShowsEditLock, whose
 * editLockRecord() names the document unless the page overrides
 * deletableDocument().
 */
trait DeletesTransaction
{
    use HandlesPeriodLockOverride;

    /**
     * What the dialog shows: the state, the blockers and the warnings.
     *
     * @var array{state: string, blockers: list<array{message: string, url: ?string}>, warnings: list<string>}|null
     */
    #[Locked]
    public ?array $deletionPreview = null;

    public string $deleteReason = '';

    /**
     * The document Delete acts on: by default the record the page's edit lock
     * guards (ShowsEditLock::editLockRecord()).
     */
    protected function deletableDocument(): Model
    {
        $record = $this->editLockRecord();

        abort_unless($record instanceof Model, 404);

        return $record;
    }

    /**
     * Whether the Actions menu offers Delete: always on a draft, and on a
     * posted or voided document while the organization allows it.
     */
    #[Computed]
    public function canOfferDelete(): bool
    {
        return $this->deletableDocument()->getAttribute('journal_entry_id') === null
            || $this->company->allowsTransactionDeletion();
    }

    #[Requires(null, PermissionAction::Delete)]
    public function prepareDelete(TransactionDeleter $deleter): void
    {
        $this->deletionPreview = $deleter->preview($this->deletableDocument())->toArray();
        $this->deleteReason = '';

        Flux::modal('delete-transaction')->show();
    }

    #[Requires(null, PermissionAction::Delete)]
    #[GuardsEditLock]
    public function confirmDelete(TransactionDeleter $deleter): void
    {
        $document = $this->deletableDocument();
        $type = DeletableDocumentType::forModel($document);

        if ($type === null) {
            abort(404);
        }

        $number = (string) $document->getAttribute($type->numberColumn());

        // The dialog described the document as it stood when it opened. If it
        // has since been posted or voided somewhere else, show what deleting
        // it now would do and ask again.
        if ($this->deletionPreview !== null && ! PeriodLockOverride::isActive()) {
            $current = $deleter->preview($document->fresh() ?? $document)->toArray();

            if ($current['state'] !== $this->deletionPreview['state']) {
                $this->deletionPreview = $current;
                Flux::modal('delete-transaction')->show();
                Flux::toast(variant: 'warning', text: __('This :document changed since you opened the dialog. Check what deleting it does now, then confirm again.', ['document' => $type->noun()]));

                return;
            }
        }

        try {
            $deleter->delete($document, $this->deleteReason);
        } catch (PeriodLockedException $e) {
            Flux::modal('delete-transaction')->close();

            if (! $this->offerPeriodLockOverride($e, 'confirmDelete')) {
                Flux::toast(variant: 'danger', text: $e->getMessage());
            }

            return;
        } catch (DeletionBlockedException $e) {
            $this->deletionPreview = [
                'state' => $this->deletionPreview['state'] ?? '',
                'blockers' => array_map(fn (DeletionBlocker $b): array => $b->toArray(), $e->blockers),
                'warnings' => $this->deletionPreview['warnings'] ?? [],
            ];

            Flux::modal('delete-transaction')->show();

            return;
        } catch (RecordEditLockedException $e) {
            Flux::modal('delete-transaction')->close();
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        Flux::modal('delete-transaction')->close();
        Flux::toast(variant: 'success', text: __(':document :no deleted.', ['document' => $type->label(), 'no' => $number]));

        $this->redirectRoute($type->indexRouteFor($document), ['company' => $this->company->slug, ...$type->indexRouteParametersFor($document)], navigate: true);
    }
}
