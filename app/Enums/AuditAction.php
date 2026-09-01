<?php

namespace App\Enums;

enum AuditAction: string
{
    case JournalEntryPosted = 'journal_entry.posted';
    case JournalEntryVoided = 'journal_entry.voided';
    case JournalEntryReversed = 'journal_entry.reversed';

    case PeriodLockChanged = 'period.lock_changed';

    case InvoiceCreated = 'invoice.created';
    case InvoiceUpdated = 'invoice.updated';
    case InvoiceDeleted = 'invoice.deleted';
    case InvoicePosted = 'invoice.posted';
    case InvoiceReposted = 'invoice.reposted';
    case InvoiceVoided = 'invoice.voided';
    case InvoiceReconciled = 'invoice.reconciled';

    case SalesOrderCreated = 'sales_order.created';
    case SalesOrderUpdated = 'sales_order.updated';
    case SalesOrderDeleted = 'sales_order.deleted';

    case PurchaseOrderCreated = 'purchase_order.created';
    case PurchaseOrderUpdated = 'purchase_order.updated';
    case PurchaseOrderDeleted = 'purchase_order.deleted';

    case VendorCreditCreated = 'vendor_credit.created';
    case VendorCreditUpdated = 'vendor_credit.updated';
    case VendorCreditDeleted = 'vendor_credit.deleted';
    case VendorCreditPosted = 'vendor_credit.posted';
    case VendorCreditReposted = 'vendor_credit.reposted';
    case VendorCreditVoided = 'vendor_credit.voided';

    case CreditMemoCreated = 'credit_memo.created';
    case CreditMemoUpdated = 'credit_memo.updated';
    case CreditMemoDeleted = 'credit_memo.deleted';
    case CreditMemoPosted = 'credit_memo.posted';
    case CreditMemoReposted = 'credit_memo.reposted';
    case CreditMemoVoided = 'credit_memo.voided';

    case BillCreated = 'bill.created';
    case BillUpdated = 'bill.updated';
    case BillDeleted = 'bill.deleted';
    case BillPosted = 'bill.posted';
    case BillReposted = 'bill.reposted';
    case BillVoided = 'bill.voided';
    case BillReconciled = 'bill.reconciled';

    case SalesReceiptCreated = 'sales_receipt.created';
    case SalesReceiptUpdated = 'sales_receipt.updated';
    case SalesReceiptDeleted = 'sales_receipt.deleted';
    case SalesReceiptPosted = 'sales_receipt.posted';
    case SalesReceiptReposted = 'sales_receipt.reposted';
    case SalesReceiptVoided = 'sales_receipt.voided';

    case CustomerReceiptCreated = 'customer_receipt.created';
    case CustomerReceiptUpdated = 'customer_receipt.updated';
    case CustomerReceiptDeleted = 'customer_receipt.deleted';
    case CustomerReceiptPosted = 'customer_receipt.posted';
    case CustomerReceiptReposted = 'customer_receipt.reposted';
    case CustomerReceiptVoided = 'customer_receipt.voided';

    case BillPaymentCreated = 'bill_payment.created';
    case BillPaymentUpdated = 'bill_payment.updated';
    case BillPaymentDeleted = 'bill_payment.deleted';
    case BillPaymentPosted = 'bill_payment.posted';
    case BillPaymentReposted = 'bill_payment.reposted';
    case BillPaymentVoided = 'bill_payment.voided';

    case ChequeCreated = 'cheque.created';
    case ChequeUpdated = 'cheque.updated';
    case ChequeDeleted = 'cheque.deleted';
    case ChequePosted = 'cheque.posted';
    case ChequeReposted = 'cheque.reposted';
    case ChequeVoided = 'cheque.voided';

    case ExpensePosted = 'expense.posted';
    case ExpenseVoided = 'expense.voided';

    case DepositCreated = 'deposit.created';
    case DepositUpdated = 'deposit.updated';
    case DepositDeleted = 'deposit.deleted';
    case DepositPosted = 'deposit.posted';
    case DepositReposted = 'deposit.reposted';
    case DepositVoided = 'deposit.voided';

    case TransferCreated = 'transfer.created';
    case TransferUpdated = 'transfer.updated';
    case TransferDeleted = 'transfer.deleted';
    case TransferPosted = 'transfer.posted';
    case TransferVoided = 'transfer.voided';

    case JournalEntryCreated = 'journal_entry.created';
    case JournalEntryUpdated = 'journal_entry.updated';
    case JournalEntryDeleted = 'journal_entry.deleted';

    case JournalLineCreated = 'journal_line.created';
    case JournalLineUpdated = 'journal_line.updated';
    case JournalLineDeleted = 'journal_line.deleted';

    case TaxReturnCreated = 'tax_return.created';
    case TaxReturnUpdated = 'tax_return.updated';
    case TaxReturnDeleted = 'tax_return.deleted';
    case TaxReturnFiled = 'tax_return.filed';
    case TaxReturnVoided = 'tax_return.voided';

    case TaxReturnPaymentCreated = 'tax_return_payment.created';
    case TaxReturnPaymentUpdated = 'tax_return_payment.updated';
    case TaxReturnPaymentDeleted = 'tax_return_payment.deleted';
    case TaxReturnPaymentPosted = 'tax_return_payment.posted';
    case TaxReturnPaymentVoided = 'tax_return_payment.voided';

    case PayRunCreated = 'pay_run.created';
    case PayRunUpdated = 'pay_run.updated';
    case PayRunDeleted = 'pay_run.deleted';
    case PayRunCalculated = 'pay_run.calculated';
    case PayRunPosted = 'pay_run.posted';
    case PayRunVoided = 'pay_run.voided';

    case PayrollChequePosted = 'payroll_cheque.posted';
    case PayrollChequeVoided = 'payroll_cheque.voided';

    case PayrollRemittancePosted = 'payroll_remittance.posted';
    case PayrollRemittanceVoided = 'payroll_remittance.voided';

    case TimeEntryCreated = 'time_entry.created';
    case TimeEntryUpdated = 'time_entry.updated';
    case TimeEntryDeleted = 'time_entry.deleted';
    case TimeEntryApproved = 'time_entry.approved';
    case TimeEntryRejected = 'time_entry.rejected';

    case TimeOffRequestSubmitted = 'time_off_request.submitted';
    case TimeOffRequestUpdated = 'time_off_request.updated';
    case TimeOffRequestManagerApproved = 'time_off_request.manager_approved';
    case TimeOffRequestApproved = 'time_off_request.approved';
    case TimeOffRequestDenied = 'time_off_request.denied';
    case TimeOffRequestCancelled = 'time_off_request.cancelled';

    case DonationReceiptIssued = 'donation_receipt.issued';
    case DonationReceiptVoided = 'donation_receipt.voided';
    case DonationReceiptReissued = 'donation_receipt.reissued';

    case DonationCreated = 'donation.created';
    case DonationUpdated = 'donation.updated';
    case DonationDeleted = 'donation.deleted';
    case DonationPosted = 'donation.posted';
    case DonationReposted = 'donation.reposted';
    case DonationVoided = 'donation.voided';

    case GrantCreated = 'grant.created';
    case GrantUpdated = 'grant.updated';
    case GrantDeleted = 'grant.deleted';
    case GrantPosted = 'grant.posted';
    case GrantVoided = 'grant.voided';
    case GrantRecognized = 'grant.recognized';

    case AccountCreated = 'account.created';
    case AccountUpdated = 'account.updated';
    case AccountDeleted = 'account.deleted';
    case AccountMerged = 'account.merged';

    case ContactCreated = 'contact.created';
    case ContactUpdated = 'contact.updated';
    case ContactDeleted = 'contact.deleted';
    case ContactMerged = 'contact.merged';

    case OpeningBalanceApplied = 'opening_balance.applied';
    case OpeningBalanceTargetsImported = 'opening_balance.targets_imported';
}
