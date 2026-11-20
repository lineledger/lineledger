<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Lists\DeleteListRecord;
use App\Contracts\ClientSafeException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\User;
use App\Services\Deletion\DeletableDocuments;
use App\Services\Deletion\TransactionDeleter;
use App\Services\References\RecordReferences;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared base for the v1 JSON API. Centralizes the current-company accessor,
 * the draft opt-out flag, and translation of posting-service exceptions into
 * HTTP responses. ClientSafeException posting failures are re-thrown so the
 * renderers in bootstrap/app.php map them with a safe message; any other bare
 * RuntimeException is internal (missing control account, item misconfig, …) —
 * it is logged and returned as a generic 422 so no accounting detail leaks.
 */
abstract class ApiController extends Controller
{
    protected function company(): Company
    {
        $company = app('current_company');
        assert($company instanceof Company);

        return $company;
    }

    /**
     * Whether the caller asked to leave a created document as a draft
     * (`"post": false`). Defaults to posting, preserving legacy behavior.
     */
    protected function wantsDraft(Request $request): bool
    {
        return $request->boolean('post', true) === false;
    }

    /**
     * Run a posting closure, normalizing poster exceptions to HTTP responses.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    protected function posting(callable $fn): mixed
    {
        try {
            // Wrap in a transaction so a build-then-post sequence is atomic: if
            // posting throws (locked period, unbalanced, etc.) the document the
            // Action just persisted is rolled back too. Posters that open their
            // own transaction simply nest as a savepoint.
            return DB::transaction($fn);
        } catch (ClientSafeException $e) {
            // Re-throw so the renderers in bootstrap/app.php map it to the right
            // status with its client-safe message.
            throw $e;
        } catch (RuntimeException $e) {
            // A poster failed for an internal/config reason (missing control
            // account, item misconfiguration, …). Log the detail; never surface
            // its message to the client.
            report($e);

            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'This document could not be posted.');
        }
    }

    /**
     * Reject an operation that the document's lifecycle does not support
     * (e.g. editing a posted document that has no repost path).
     */
    protected function conflict(string $message): never
    {
        throw new HttpException(Response::HTTP_CONFLICT, $message);
    }

    /**
     * DELETE on a document.
     *
     *  - `?permanent=true` deletes it as if it was never entered, whether draft,
     *    posted or voided (TransactionDeleter): 204, or 409 with the reasons
     *    when something still depends on it.
     *  - Otherwise a draft is deleted the same way, and a posted document is
     *    voided as before (200 with the document), with a Deprecation header
     *    pointing at POST …/void, which takes over voiding in API v2.
     *
     * @param  Closure(): mixed  $void  the poster's void
     * @param  Closure(): JsonResource  $resource  the document after the void
     */
    protected function destroyDocument(Request $request, Model $document, Closure $void, Closure $resource, string $alreadyVoided): JsonResponse
    {
        if ($request->boolean('permanent')) {
            return $this->deletePermanently($request, $document);
        }

        if ($document->getAttribute('voided_at') !== null) {
            $this->conflict($alreadyVoided);
        }

        if ($document->getAttribute('journal_entry_id') !== null) {
            $this->posting($void);

            return $resource()->response()->setStatusCode(200)->withHeaders([
                'Deprecation' => 'true',
                'Link' => '<'.$request->url().'/void>; rel="successor-version"',
            ]);
        }

        return $this->deletePermanently($request, $document);
    }

    /**
     * POST …/void: void a posted document with a reversing entry. A draft has
     * nothing to reverse (delete it instead), and a voided one stays voided.
     *
     * @param  Closure(): mixed  $void
     * @param  Closure(): JsonResource  $resource
     */
    protected function voidDocument(Model $document, Closure $void, Closure $resource, string $alreadyVoided): JsonResponse
    {
        if ($document->getAttribute('voided_at') !== null) {
            $this->conflict($alreadyVoided);
        }

        if ($document->getAttribute('journal_entry_id') === null) {
            $this->conflict('A draft has nothing to reverse; delete it instead.');
        }

        $this->posting($void);

        return $resource()->response()->setStatusCode(200);
    }

    /**
     * Delete the document through TransactionDeleter, outside any transaction
     * (it checks the edit lock first). The API never opens a period-lock
     * override: a closed period answers 422. An optional `reason` is kept
     * with the record of the deletion.
     */
    protected function deletePermanently(Request $request, Model $document): JsonResponse
    {
        if (! DeletableDocuments::supports($document)) {
            $this->conflict('This document can\'t be deleted yet; void it instead.');
        }

        $key = app()->bound('current_api_key') ? app('current_api_key') : null;
        $actor = $key instanceof CompanyApiKey ? $key->createdByUser : null;
        $reason = $request->input('reason');

        app(TransactionDeleter::class)->delete($document, is_string($reason) ? $reason : null, $actor instanceof User ? $actor : null, 'api');

        return response()->json(null, 204);
    }

    /**
     * Delete a list record for good through DeleteListRecord, which refuses
     * (409, with the reasons) while anything still uses it. The API's calls
     * are deliberate, so it doesn't ask for the record to be made inactive
     * first, as the web app does.
     */
    protected function deleteListRecord(Model $record): void
    {
        $key = app()->bound('current_api_key') ? app('current_api_key') : null;
        $actor = $key instanceof CompanyApiKey ? $key->createdByUser : null;

        app(DeleteListRecord::class)->handle($record, $actor instanceof User ? $actor : null, requireInactive: false);
    }

    /**
     * Refuse (409) to delete a list record that something still references,
     * naming where, so the caller deactivates it instead.
     *
     * @param  class-string  $registry  see App\Services\References\RecordReferences
     * @param  list<string>  $ignore  "table.column" entries that do not count as a use
     */
    protected function refuseWhileReferenced(string $registry, int $id, string $record, array $ignore = []): void
    {
        $reference = RecordReferences::firstReference($registry, $id, $ignore);

        if ($reference !== null) {
            $this->conflict(__('This :record is still used (:where), so it can\'t be deleted. Make it inactive instead.', [
                'record' => $record,
                'where' => RecordReferences::describe($reference),
            ]));
        }
    }
}
