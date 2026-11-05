<?php

namespace App\Support\Access;

use App\Enums\Activity;
use App\Enums\PermissionAction;

/**
 * What each /api/v1 route needs, in the same activity terms as the web app:
 * index/show View, store Create, update Modify, destroy (void a posted
 * document, or delete a draft) Delete, and the extra actions per their
 * meaning. Each inner list is "any one of"; every list must be met.
 *
 * Read by EnsureApiPermission against the permissions of the person who
 * created the key. ApiPermissionCoverageTest fails when an API route has no
 * entry here.
 */
final class ApiRoutePermissionMap
{
    /**
     * API resource URI → activity.
     *
     * @var array<string, Activity>
     */
    public const array RESOURCES = [
        'customers' => Activity::Customers,
        'sales-orders' => Activity::SalesOrders,
        'invoices' => Activity::Invoices,
        'receipts' => Activity::Receipts,
        'credit-memos' => Activity::CreditMemos,
        'vendors' => Activity::Vendors,
        'employees' => Activity::Employees,
        'bills' => Activity::Bills,
        'bill-payments' => Activity::BillPayments,
        'cheques' => Activity::Cheques,
        'deposits' => Activity::Deposits,
        'transfers' => Activity::Transfers,
        'bank-reconciliations' => Activity::Reconciliation,
        'accounts' => Activity::Accounts,
        'journal-entries' => Activity::Journal,
        'items' => Activity::Items,
        'tax-codes' => Activity::TaxCodes,
        'tax-agencies' => Activity::TaxCodes,
        'payment-terms' => Activity::OtherLists,
        'payment-methods' => Activity::OtherLists,
        'tax-returns' => Activity::TaxReturns,
        'tax-return-payments' => Activity::TaxReturns,
        'assets' => Activity::Assets,
        'asset-categories' => Activity::OtherLists,
        'stock-adjustments' => Activity::InventoryAdjustments,
    ];

    /**
     * What the route needs, or null for a route this map doesn't cover.
     *
     * @return list<list<Requirement>>|null
     */
    public static function for(?string $routeName): ?array
    {
        if ($routeName === null || ! str_starts_with($routeName, 'api.v1.')) {
            return null;
        }

        $parts = explode('.', substr($routeName, strlen('api.v1.')));

        if (count($parts) !== 2 || ! isset(self::RESOURCES[$parts[0]])) {
            return null;
        }

        [$resource, $verb] = $parts;
        $activity = self::RESOURCES[$resource];
        $on = fn (PermissionAction $action, ?Activity $other = null): array => [new Requirement($other ?? $activity, $action)];

        return match ($verb) {
            'index', 'show' => [$on(PermissionAction::View)],
            'store' => [$on(PermissionAction::Create)],
            'update', 'post', 'complete', 'file' => [$on(PermissionAction::Modify)],
            'destroy', 'void', 'cancel' => [$on(PermissionAction::Delete)],
            // A refund writes a refund cheque or a customer refund receipt.
            'refund' => [$on(PermissionAction::Modify), [
                new Requirement(Activity::Cheques, PermissionAction::Create),
                new Requirement(Activity::Receipts, PermissionAction::Create),
            ]],
            // Fulfilling a sales order turns it into an invoice.
            'fulfill' => [$on(PermissionAction::Modify), $on(PermissionAction::Create, Activity::Invoices)],
            default => null,
        };
    }
}
