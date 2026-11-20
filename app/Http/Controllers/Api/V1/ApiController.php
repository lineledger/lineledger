<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\ClientSafeException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\References\RecordReferences;
use Illuminate\Http\Request;
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
