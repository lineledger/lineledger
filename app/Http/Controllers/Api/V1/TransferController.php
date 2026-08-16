<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Banking\SaveTransfer;
use App\Enums\TransferStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreTransferRequest;
use App\Http\Requests\Api\V1\UpdateTransferRequest;
use App\Http\Resources\Api\V1\TransferResource;
use App\Models\Transfer;
use App\Services\Posting\TransferPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransferController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected TransferPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Transfer::query();

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'transfer_date',
            'search' => ['transfer_no', 'memo'],
            'sortable' => ['transfer_date', 'transfer_no', 'from_amount_cents', 'id'],
        ]);

        return TransferResource::collection($this->paginateApi($query, $request));
    }

    public function show(Transfer $transfer): TransferResource
    {
        return new TransferResource($transfer);
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $data = $request->validated();

        $transfer = $this->posting(function () use ($data, $request): Transfer {
            $transfer = app(SaveTransfer::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($transfer);
            }

            return $transfer->fresh();
        });

        return (new TransferResource($transfer))->response()->setStatusCode(201);
    }

    /**
     * Only draft transfers are editable — TransferPoster has no repost path.
     */
    public function update(UpdateTransferRequest $request, Transfer $transfer): TransferResource
    {
        if ($transfer->status === TransferStatus::Void) {
            $this->conflict('A voided transfer cannot be edited.');
        }

        if ($transfer->journal_entry_id !== null) {
            $this->conflict('This posted document cannot be edited; void and recreate.');
        }

        $transfer = app(SaveTransfer::class)->handle($request->validated(), $transfer);

        return new TransferResource($transfer->fresh());
    }

    /**
     * Post a draft transfer. Posted transfers have no repost path.
     */
    public function post(Transfer $transfer): TransferResource
    {
        if ($transfer->status === TransferStatus::Void) {
            $this->conflict('A voided transfer cannot be posted.');
        }

        if ($transfer->journal_entry_id !== null) {
            $this->conflict('Transfer is already posted; void and recreate to change it.');
        }

        $transfer = $this->posting(function () use ($transfer): Transfer {
            $this->poster->post($transfer);

            return $transfer->fresh();
        });

        return new TransferResource($transfer);
    }

    /**
     * Void a posted transfer (reversing JE) or hard-delete a draft.
     */
    public function destroy(Transfer $transfer): JsonResponse
    {
        if ($transfer->status === TransferStatus::Void) {
            $this->conflict('Transfer is already voided.');
        }

        if ($transfer->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($transfer));

            return (new TransferResource($transfer->fresh()))->response()->setStatusCode(200);
        }

        $transfer->delete();

        return response()->json(null, 204);
    }
}
