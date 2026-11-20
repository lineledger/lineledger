<?php

namespace App\Services\Deletion;

use App\Enums\DeletableDocumentType;
use App\Models\BankReconciliation;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\CreditMemo;
use App\Models\CurrencyRevaluation;
use App\Models\Deposit;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\GrantRecognition;
use App\Models\OpeningBalanceState;
use App\Models\PayrollCheque;
use App\Models\PayrollRemittance;
use App\Models\PayRun;
use App\Models\StockAdjustment;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Models\Transfer;
use App\Models\VendorCredit;
use App\Services\Deletion\Handlers\CustomerReceiptDeletionHandler;
use App\Services\Deletion\Handlers\InvoiceDeletionHandler;
use App\Services\Deletion\Handlers\SalesReceiptDeletionHandler;
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
            Bill::class => $planned,
            BillPayment::class => $planned,
            Cheque::class => $planned,
            CreditMemo::class => $planned,
            Deposit::class => $planned,
            Expense::class => $planned,
            Transfer::class => $planned,
            VendorCredit::class => $planned,
            StockAdjustment::class => $planned,
            TaxReturnPayment::class => $planned,
            Donation::class => $planned,
            Grant::class => $planned,
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
