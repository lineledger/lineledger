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
     * Edit a cheque. Drafts are rebuilt in place; posted cheques are reposted
     * (their GL entry rebuilt) via ChequePoster, mirroring the Livewire form.
     */
    public function update(UpdateChequeRequest $request, Cheque $cheque): ChequeResource
    {
        if ($cheque->status === ChequeStatus::Void) {
            $this->conflict('A voided cheque cannot be edited.');
        }

        $wasPosted = $cheque->journal_entry_id !== null;

        $cheque = $this->posting(function () use ($request, $cheque, $wasPosted): Cheque {
            $cheque = app(SaveCheque::class)->handle($request->validated(), $cheque);

            if ($wasPosted) {
                $this->poster->repost($cheque);
            }

            return $cheque->fresh(['lines']);
        });

        return new ChequeResource($cheque);
    }

    /**
     * Post a draft cheque. An already-posted cheque is edited via PATCH (repost).
     */
    public function post(Cheque $cheque): ChequeResource
    {
        if ($cheque->status === ChequeStatus::Void) {
            $this->conflict('A voided cheque cannot be posted.');
        }

        if ($cheque->journal_entry_id !== null) {
            $this->conflict('Cheque is already posted; edit it with PATCH to change it.');
        }

        $cheque = $this->posting(function () use ($cheque): Cheque {
            $this->poster->post($cheque);

            return $cheque->fresh(['lines']);
        });

        return new ChequeResource($cheque);
    }

    /**
     * DELETE: `?permanent=true` deletes the cheque as if never entered; without
     * it a draft is deleted and a posted one voided (deprecated: POST …/void).
     */
    public function destroy(Request $request, Cheque $cheque): JsonResponse
    {
        return $this->destroyDocument(
            $request,
            $cheque,
            fn () => $this->poster->void($cheque),
            fn () => new ChequeResource($cheque->fresh(['lines'])),
            'Cheque is already voided.',
        );
    }

    /**
     * POST …/void: void a posted cheque with a reversing journal entry.
     */
    public function void(Cheque $cheque): JsonResponse
    {
        return $this->voidDocument($cheque, fn () => $this->poster->void($cheque), fn () => new ChequeResource($cheque->fresh(['lines'])), 'Cheque is already voided.');
    }
}
