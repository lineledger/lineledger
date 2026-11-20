<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Banking\SaveDeposit;
use App\Enums\DepositStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreDepositRequest;
use App\Http\Requests\Api\V1\UpdateDepositRequest;
use App\Http\Resources\Api\V1\DepositResource;
use App\Models\Deposit;
use App\Services\Posting\DepositPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DepositController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected DepositPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Deposit::query()->with('lines');

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'deposit_date',
            'search' => ['deposit_no', 'memo'],
            'sortable' => ['deposit_date', 'deposit_no', 'amount_cents', 'id'],
        ]);

        return DepositResource::collection($this->paginateApi($query, $request));
    }

    public function show(Deposit $deposit): DepositResource
    {
        return new DepositResource($deposit->load('lines'));
    }

    public function store(StoreDepositRequest $request): JsonResponse
    {
        $data = $request->validated();

        $deposit = $this->posting(function () use ($data, $request): Deposit {
            $deposit = app(SaveDeposit::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($deposit);
            }

            return $deposit->fresh(['lines']);
        });

        return (new DepositResource($deposit))->response()->setStatusCode(201);
    }

    /**
     * Edit a deposit. Drafts are rebuilt in place; posted deposits are reposted
     * (their GL entry rebuilt) via DepositPoster, mirroring the Livewire form.
     */
    public function update(UpdateDepositRequest $request, Deposit $deposit): DepositResource
    {
        if ($deposit->status === DepositStatus::Void) {
            $this->conflict('A voided deposit cannot be edited.');
        }

        $wasPosted = $deposit->journal_entry_id !== null;

        $deposit = $this->posting(function () use ($request, $deposit, $wasPosted): Deposit {
            $deposit = app(SaveDeposit::class)->handle($request->validated(), $deposit);

            if ($wasPosted) {
                $this->poster->repost($deposit);
            }

            return $deposit->fresh(['lines']);
        });

        return new DepositResource($deposit);
    }

    /**
     * Post a draft deposit. An already-posted deposit is edited via PATCH (repost).
     */
    public function post(Deposit $deposit): DepositResource
    {
        if ($deposit->status === DepositStatus::Void) {
            $this->conflict('A voided deposit cannot be posted.');
        }

        if ($deposit->journal_entry_id !== null) {
            $this->conflict('Deposit is already posted; edit it with PATCH to change it.');
        }

        $deposit = $this->posting(function () use ($deposit): Deposit {
            $this->poster->post($deposit);

            return $deposit->fresh(['lines']);
        });

        return new DepositResource($deposit);
    }

    /**
     * DELETE: `?permanent=true` deletes the deposit as if never entered; without
     * it a draft is deleted and a posted one voided (deprecated: POST …/void).
     */
    public function destroy(Request $request, Deposit $deposit): JsonResponse
    {
        return $this->destroyDocument(
            $request,
            $deposit,
            fn () => $this->poster->void($deposit),
            fn () => new DepositResource($deposit->fresh(['lines'])),
            'Deposit is already voided.',
        );
    }

    /**
     * POST …/void: void a posted deposit with a reversing journal entry.
     */
    public function void(Deposit $deposit): JsonResponse
    {
        return $this->voidDocument($deposit, fn () => $this->poster->void($deposit), fn () => new DepositResource($deposit->fresh(['lines'])), 'Deposit is already voided.');
    }
}
