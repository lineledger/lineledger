<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Banking\SaveCheque;
use App\Enums\ChequeStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreChequeRequest;
use App\Http\Requests\Api\V1\UpdateChequeRequest;
use App\Http\Resources\Api\V1\ChequeResource;
use App\Models\Cheque;
use App\Services\Posting\ChequePoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChequeController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected ChequePoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Cheque::query()->with('lines');

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'cheque_date',
            'search' => ['cheque_no', 'payee_name', 'memo'],
            'sortable' => ['cheque_date', 'cheque_no', 'amount_cents', 'id'],
        ]);

        return ChequeResource::collection($this->paginateApi($query, $request));
    }

    public function show(Cheque $cheque): ChequeResource
    {
        return new ChequeResource($cheque->load('lines'));
    }

    public function store(StoreChequeRequest $request): JsonResponse
    {
        $data = $request->validated();

        $cheque = $this->posting(function () use ($data, $request): Cheque {
            $cheque = app(SaveCheque::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($cheque);
            }

            return $cheque->fresh(['lines']);
        });

        return (new ChequeResource($cheque))->response()->setStatusCode(201);
    }

    /**
     * Only draft cheques are editable — ChequePoster has no repost path.
     */
    public function update(UpdateChequeRequest $request, Cheque $cheque): ChequeResource
    {
        if ($cheque->status === ChequeStatus::Void) {
            $this->conflict('A voided cheque cannot be edited.');
        }

        if ($cheque->journal_entry_id !== null) {
            $this->conflict('This posted document cannot be edited; void and recreate.');
        }

        $cheque = app(SaveCheque::class)->handle($request->validated(), $cheque);

        return new ChequeResource($cheque->fresh(['lines']));
    }

    /**
     * Post a draft cheque. Posted cheques have no repost path.
     */
    public function post(Cheque $cheque): ChequeResource
    {
        if ($cheque->status === ChequeStatus::Void) {
            $this->conflict('A voided cheque cannot be posted.');
        }

        if ($cheque->journal_entry_id !== null) {
            $this->conflict('Cheque is already posted; void and recreate to change it.');
        }

        $cheque = $this->posting(function () use ($cheque): Cheque {
            $this->poster->post($cheque);

            return $cheque->fresh(['lines']);
        });

        return new ChequeResource($cheque);
    }

    /**
     * Void a posted cheque (reversing JE) or hard-delete a draft.
     */
    public function destroy(Cheque $cheque): JsonResponse
    {
        if ($cheque->status === ChequeStatus::Void) {
            $this->conflict('Cheque is already voided.');
        }

        if ($cheque->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($cheque));

            return (new ChequeResource($cheque->fresh(['lines'])))->response()->setStatusCode(200);
        }

        $cheque->lines()->delete();
        $cheque->delete();

        return response()->json(null, 204);
    }
}
