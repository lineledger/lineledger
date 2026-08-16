<?php

namespace App\Observers\Audit;

use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SalesOrder;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\AuditMute;
use Illuminate\Database\Eloquent\Model;

/**
 * Records create/update/delete events on every accounting source document
 * as immutable audit rows. Muted while posting services run, because those
 * record their own richer posting/voiding/reposting events.
 */
class AuditableObserver
{
    /**
     * @var array<class-string<Model>, array{created: AuditAction, updated: AuditAction, deleted: AuditAction}>
     */
    public const ACTIONS = [
        Invoice::class => [
            'created' => AuditAction::InvoiceCreated,
            'updated' => AuditAction::InvoiceUpdated,
            'deleted' => AuditAction::InvoiceDeleted,
        ],
        SalesOrder::class => [
            'created' => AuditAction::SalesOrderCreated,
            'updated' => AuditAction::SalesOrderUpdated,
            'deleted' => AuditAction::SalesOrderDeleted,
        ],
        CreditMemo::class => [
            'created' => AuditAction::CreditMemoCreated,
            'updated' => AuditAction::CreditMemoUpdated,
            'deleted' => AuditAction::CreditMemoDeleted,
        ],
        Bill::class => [
            'created' => AuditAction::BillCreated,
            'updated' => AuditAction::BillUpdated,
            'deleted' => AuditAction::BillDeleted,
        ],
        CustomerReceipt::class => [
            'created' => AuditAction::CustomerReceiptCreated,
            'updated' => AuditAction::CustomerReceiptUpdated,
            'deleted' => AuditAction::CustomerReceiptDeleted,
        ],
        BillPayment::class => [
            'created' => AuditAction::BillPaymentCreated,
            'updated' => AuditAction::BillPaymentUpdated,
            'deleted' => AuditAction::BillPaymentDeleted,
        ],
        Cheque::class => [
            'created' => AuditAction::ChequeCreated,
            'updated' => AuditAction::ChequeUpdated,
            'deleted' => AuditAction::ChequeDeleted,
        ],
        Deposit::class => [
            'created' => AuditAction::DepositCreated,
            'updated' => AuditAction::DepositUpdated,
            'deleted' => AuditAction::DepositDeleted,
        ],
        JournalEntry::class => [
            'created' => AuditAction::JournalEntryCreated,
            'updated' => AuditAction::JournalEntryUpdated,
            'deleted' => AuditAction::JournalEntryDeleted,
        ],
        JournalLine::class => [
            'created' => AuditAction::JournalLineCreated,
            'updated' => AuditAction::JournalLineUpdated,
            'deleted' => AuditAction::JournalLineDeleted,
        ],
        TaxReturn::class => [
            'created' => AuditAction::TaxReturnCreated,
            'updated' => AuditAction::TaxReturnUpdated,
            'deleted' => AuditAction::TaxReturnDeleted,
        ],
        TaxReturnPayment::class => [
            'created' => AuditAction::TaxReturnPaymentCreated,
            'updated' => AuditAction::TaxReturnPaymentUpdated,
            'deleted' => AuditAction::TaxReturnPaymentDeleted,
        ],
        Account::class => [
            'created' => AuditAction::AccountCreated,
            'updated' => AuditAction::AccountUpdated,
            'deleted' => AuditAction::AccountDeleted,
        ],
        Contact::class => [
            'created' => AuditAction::ContactCreated,
            'updated' => AuditAction::ContactUpdated,
            'deleted' => AuditAction::ContactDeleted,
        ],
    ];

    protected const IGNORED_ATTRIBUTES = ['created_at', 'updated_at'];

    /**
     * Per-model attribute exclusions, applied on top of IGNORED_ATTRIBUTES.
     * Cached running balances are derived state recomputed from the GL — they
     * churn on every posting and carry no audit value. The recompute paths
     * already use saveQuietly(); listing them here is belt-and-braces so a
     * direct update touching only these columns never emits a noise row.
     *
     * @var array<class-string<Model>, list<string>>
     */
    protected const MODEL_IGNORED_ATTRIBUTES = [
        Account::class => ['balance_cents'],
        Contact::class => ['ar_balance_cents', 'ap_balance_cents', 'remember_token'],
    ];

    public function __construct(protected AccountingAuditRecorder $recorder) {}

    public function created(Model $model): void
    {
        $this->capture($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->capture($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->capture($model, 'deleted');
    }

    protected function capture(Model $model, string $event): void
    {
        if (AuditMute::isMuted()) {
            return;
        }

        $actions = self::ACTIONS[get_class($model)] ?? null;
        if ($actions === null) {
            return;
        }

        $action = $actions[$event] ?? null;
        if ($action === null) {
            return;
        }

        $companyId = $this->resolveCompanyId($model);
        if ($companyId === null) {
            return;
        }

        $payload = match ($event) {
            'created' => ['attributes' => $this->cleanAttributes($model, $model->getAttributes())],
            'deleted' => ['attributes' => $this->cleanAttributes($model, $model->getOriginal())],
            'updated' => $this->dirtyDiff($model),
            default => [],
        };

        if ($event === 'updated' && empty($payload['changes'])) {
            return;
        }

        $this->recorder->record($companyId, $action, $model, $payload);
    }

    protected function resolveCompanyId(Model $model): ?int
    {
        if (isset($model->company_id) && $model->company_id) {
            return (int) $model->company_id;
        }

        if ($model instanceof JournalLine) {
            $entryCompanyId = $model->journalEntry?->company_id
                ?? JournalEntry::withoutGlobalScopes()->whereKey($model->journal_entry_id)->value('company_id');

            return $entryCompanyId ? (int) $entryCompanyId : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    protected function cleanAttributes(Model $model, array $attrs): array
    {
        foreach ($this->ignoredAttributesFor($model) as $ignored) {
            unset($attrs[$ignored]);
        }

        // Never persist hidden attributes (passwords, tokens, 2FA secrets) into
        // immutable audit rows, even if a future auditable model carries them.
        foreach ($model->getHidden() as $hidden) {
            unset($attrs[$hidden]);
        }

        return $attrs;
    }

    /**
     * @return array{changes: array<string, array{from: mixed, to: mixed}>}
     */
    protected function dirtyDiff(Model $model): array
    {
        $changes = [];
        $ignored = $this->ignoredAttributesFor($model);

        foreach ($model->getDirty() as $key => $value) {
            if (in_array($key, $ignored, true) || in_array($key, $model->getHidden(), true)) {
                continue;
            }

            $changes[$key] = [
                'from' => $model->getOriginal($key),
                'to' => $value,
            ];
        }

        return ['changes' => $changes];
    }

    /**
     * Global ignores plus the model's own exclusions (derived/cached columns).
     *
     * @return list<string>
     */
    protected function ignoredAttributesFor(Model $model): array
    {
        return [
            ...self::IGNORED_ATTRIBUTES,
            ...(self::MODEL_IGNORED_ATTRIBUTES[get_class($model)] ?? []),
        ];
    }
}
