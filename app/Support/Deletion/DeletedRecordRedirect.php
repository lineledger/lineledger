<?php

namespace App\Support\Deletion;

use App\Enums\Activity;
use App\Enums\DeletableDocumentType;
use App\Models\Company;
use App\Models\DeletedTransaction;
use App\Support\Access\CurrentViewer;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * For a document's show route (->missing()): a link to a transaction that
 * has since been deleted, from an email, a bookmark or the audit log, lands
 * on the record kept of it rather than a bare 404, for a member who may see
 * that record. Everyone else still gets the 404.
 */
final class DeletedRecordRedirect
{
    public static function for(DeletableDocumentType $type): Closure
    {
        return function (Request $request) use ($type): RedirectResponse {
            $route = $request->route();
            $company = $route?->parameter('company');
            $id = $route?->originalParameter($type->routeParameter());

            if (! $company instanceof Company || ! is_numeric($id)) {
                abort(404);
            }

            $record = DeletedTransaction::query()
                ->where('company_id', $company->id)
                ->where('document_type', $type->value)
                ->where('original_id', (int) $id)
                ->latest('id')
                ->first();

            abort_if($record === null || ! CurrentViewer::permissions($company)->canView(Activity::ReportsFinancial), 404);

            return redirect()->route('deleted-transactions.show', ['company' => $company->slug, 'deletedTransaction' => $record->id]);
        };
    }
}
