<?php

namespace App\Services\Deletion;

use App\Enums\DeletableDocumentType;
use App\Models\BankReconciliation;
use App\Models\CurrencyRevaluation;
use App\Models\DonationReceipt;
use App\Models\GrantRecognition;
use App\Models\OpeningBalanceState;
use App\Models\PayrollCheque;
use App\Models\PayrollRemittance;
use App\Models\PayRun;
use App\Models\TaxReturn;
use App\Services\Deletion\Handlers\BillDeletionHandler;
use App\Services\Deletion\Handlers\BillPaymentDeletionHandler;
use App\Services\Deletion\Handlers\ChequeDeletionHandler;
use App\Services\Deletion\Handlers\CreditMemoDeletionHandler;
use App\Services\Deletion\Handlers\CustomerReceiptDeletionHandler;
use App\Services\Deletion\Handlers\DepositDeletionHandler;
use App\Services\Deletion\Handlers\DonationDeletionHandler;
use App\Services\Deletion\Handlers\EstimateDeletionHandler;
use App\Services\Deletion\Handlers\ExpenseDeletionHandler;
use App\Services\Deletion\Handlers\GrantDeletionHandler;
use App\Services\Deletion\Handlers\InvoiceDeletionHandler;
use App\Services\Deletion\Handlers\JournalEntryDeletionHandler;
use App\Services\Deletion\Handlers\PurchaseOrderDeletionHandler;
use App\Services\Deletion\Handlers\SalesOrderDeletionHandler;
use App\Services\Deletion\Handlers\SalesReceiptDeletionHandler;
use App\Services\Deletion\Handlers\StockAdjustmentDeletionHandler;
use App\Services\Deletion\Handlers\TaxReturnPaymentDeletionHandler;
use App\Services\Deletion\Handlers\TransferDeletionHandler;
use App\Services\Deletion\Handlers\VendorCreditDeletionHandler;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Which transaction types can be deleted, and the handler for each. A posting
 * document missing here can only be voided;
 * tests/Feature/Deletion/DeletableDocumentCoverageTest.php fails on one that
 * is in neither this list nor its void-only list.
 */
final class DeletableDocuments
{
    /**
     * @return array<string, class-string<DeletionHandler>>
     */
    public static function handlers(): array
    {
        return [
            DeletableDocumentType::Invoice->value => InvoiceDeletionHandler::class,
            DeletableDocumentType::SalesReceipt->value => SalesReceiptDeletionHandler::class,
            DeletableDocumentType::CustomerReceipt->value => CustomerReceiptDeletionHandler::class,
            DeletableDocumentType::CreditMemo->value => CreditMemoDeletionHandler::class,
            DeletableDocumentType::Bill->value => BillDeletionHandler::class,
            DeletableDocumentType::BillPayment->value => BillPaymentDeletionHandler::class,
            DeletableDocumentType::VendorCredit->value => VendorCreditDeletionHandler::class,
            DeletableDocumentType::Expense->value => ExpenseDeletionHandler::class,
            DeletableDocumentType::Cheque->value => ChequeDeletionHandler::class,
            DeletableDocumentType::Deposit->value => DepositDeletionHandler::class,
            DeletableDocumentType::Transfer->value => TransferDeletionHandler::class,
            DeletableDocumentType::JournalEntry->value => JournalEntryDeletionHandler::class,
            DeletableDocumentType::Estimate->value => EstimateDeletionHandler::class,
            DeletableDocumentType::SalesOrder->value => SalesOrderDeletionHandler::class,
            DeletableDocumentType::PurchaseOrder->value => PurchaseOrderDeletionHandler::class,
            DeletableDocumentType::StockAdjustment->value => StockAdjustmentDeletionHandler::class,
            DeletableDocumentType::TaxReturnPayment->value => TaxReturnPaymentDeletionHandler::class,
            DeletableDocumentType::Donation->value => DonationDeletionHandler::class,
            DeletableDocumentType::Grant->value => GrantDeletionHandler::class,
        ];
    }

    /**
     * Records that own a journal entry but can only be voided (or are managed
     * by their own screen), each with the reason. Types still to come under
     * Delete say so; the rest stay void-only on purpose.
     *
     * @return array<class-string<Model>, string>
     */
    public static function voidOnly(): array
    {
        $planned = 'Delete is planned for this type (issue #241); until then, void it.';

        return [
            GrantRecognition::class => 'Part of its grant; it goes when the grant does.',
            PayRun::class => $planned,
            PayrollCheque::class => $planned,
            PayrollRemittance::class => $planned,
            TaxReturn::class => 'A filed return is a legal filing: it is voided, never deleted.',
            DonationReceipt::class => 'An issued official donation receipt must be kept (CRA); it is voided, never deleted.',
            CurrencyRevaluation::class => 'System entry: the revaluation run owns and rebuilds it.',
            OpeningBalanceState::class => 'System entry: the Opening Balances workspace maintains it.',
            BankReconciliation::class => 'Its service-charge and interest entries belong to the reconciliation; undo the reconciliation instead.',
        ];
    }

    public static function supports(Model $document): bool
    {
        $type = DeletableDocumentType::forModel($document);

        return $type !== null && isset(self::handlers()[$type->value]);
    }

    public static function handlerFor(Model $document): DeletionHandler
    {
        $type = DeletableDocumentType::forModel($document);
        $class = $type !== null ? (self::handlers()[$type->value] ?? null) : null;

        if ($class === null) {
            throw new InvalidArgumentException(class_basename($document).' cannot be deleted; void it instead.');
        }

        return app($class);
    }
}
