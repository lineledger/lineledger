<?php

namespace App\Support\Attachments;

use App\Enums\Section;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Expense;
use App\Models\InboxItem;
use App\Models\Invoice;
use App\Models\SalesReceipt;
use App\Models\TaxReturn;
use App\Models\TaxReturnPayment;
use App\Models\User;
use App\Models\VendorCredit;

/**
 * Who may download a record's attachment (receipts, statements, contracts …).
 *
 * The download route itself is ungated by section, because one route serves
 * every record type. So the attachment carries the gate of the record it is
 * attached to: a member may download it when they can open the section that
 * record lives in — or the Documents section, whose attachment index lists
 * every record's files by design. Folder attachments are separate: they sit
 * behind DocumentFolderPolicy, which the route checks first.
 *
 * A record type missing from {@see SECTIONS} is refused (fail closed);
 * AttachmentAccessTest fails when a model gains attachments without an entry.
 */
final class AttachmentAccess
{
    /**
     * Attachable model => the section(s) its pages live in (any one suffices).
     * Bills and contacts are refined per record in {@see sectionsFor()}.
     *
     * @var array<class-string, list<Section>>
     */
    public const array SECTIONS = [
        Invoice::class => [Section::Customers],
        CreditMemo::class => [Section::Customers],
        SalesReceipt::class => [Section::Customers],
        Bill::class => [Section::Vendors, Section::Employees],
        Expense::class => [Section::Vendors],
        VendorCredit::class => [Section::Vendors],
        Cheque::class => [Section::Banking],
        BankReconciliation::class => [Section::Banking],
        Asset::class => [Section::Accounting],
        InboxItem::class => [Section::Inbox],
        TaxReturn::class => [Section::Reports],
        TaxReturnPayment::class => [Section::Reports],
        Contact::class => [Section::Customers, Section::Vendors, Section::Employees, Section::Lists],
    ];

    /**
     * Whether the viewer may download a (non-folder) attachment in this company.
     */
    public static function viewerCanDownload(User $viewer, Company $company, Attachment $attachment): bool
    {
        $sections = [...self::sectionsFor($attachment), Section::Documents];

        foreach ($sections as $section) {
            if ($company->sectionEnabled($section) && $viewer->canAccessSection($company, $section)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sections whose members may open the attachment's record. Empty for a
     * record type that isn't mapped, or a record that no longer exists.
     *
     * @return list<Section>
     */
    public static function sectionsFor(Attachment $attachment): array
    {
        $type = (string) $attachment->attachable_type;
        $sections = self::SECTIONS[$type] ?? [];

        if ($type === Bill::class) {
            // Vendor bills open under Vendors, reimbursements under Employees.
            if (Bill::query()->whereKey($attachment->attachable_id)->vendor()->exists()) {
                return [Section::Vendors];
            }

            return Bill::query()->whereKey($attachment->attachable_id)->reimbursement()->exists()
                ? [Section::Employees]
                : [];
        }

        if ($type === Contact::class) {
            $contact = Contact::query()->find($attachment->attachable_id);

            if ($contact === null) {
                return [];
            }

            return array_values(array_filter([
                $contact->is_customer ? Section::Customers : null,
                $contact->is_vendor ? Section::Vendors : null,
                $contact->is_employee ? Section::Employees : null,
                $contact->is_other_name ? Section::Lists : null,
            ]));
        }

        return $sections;
    }
}
