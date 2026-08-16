<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreBankReconciliationRequest;
use App\Http\Requests\Api\V1\UpdateBankReconciliationRequest;
use App\Http\Resources\Api\V1\BankReconciliationResource;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Services\Reconciliation\BankReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Process-oriented reconciliation API.
 *
 *   index   — list reconciliations (filterable by ?account_id, ?status)
 *   show    — one reconciliation
 *   store   — begin: open an in-progress session (optionally pre-posting
 *             service-charge / interest journal entries)
 *   update  — replace the set of marked (cleared-candidate) lines on an
 *             in-progress session
 *   complete— finalize: validates the maths balance to zero, clears the
 *             marked lines and marks the session completed
 *   destroy — cancel an in-progress session, or undo the latest completed one
 *             (reverses service-charge / interest entries, un-clears lines)
 *
 * Limitation: line marking is expressed as a full-set replacement via
 * `marked_line_ids` (consistent with the UI's mark-all / unmark-all), not as
 * per-line toggles. Service charge / interest are set only at begin().
 */
class BankReconciliationController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected BankReconciliationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BankReconciliation::query();

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'statement_date',
            'search' => [],
            'sortable' => ['statement_date', 'completed_at', 'id'],
            'default_sort' => ['statement_date', 'desc'],
        ]);

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        return BankReconciliationResource::collection($this->paginateApi($query, $request));
    }

    public function show(BankReconciliation $bankReconciliation): BankReconciliationResource
    {
        return new BankReconciliationResource($bankReconciliation);
    }

    public function store(StoreBankReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $account = Account::query()->findOrFail($data['account_id']);
        $statementDate = CarbonImmutable::parse($data['statement_date']);

        $serviceCharge = isset($data['service_charge']) ? [
            'cents' => (int) $data['service_charge']['cents'],
            'date' => CarbonImmutable::parse($data['service_charge']['date'] ?? $data['statement_date']),
            'account_id' => (int) $data['service_charge']['account_id'],
        ] : null;

        $interestEarned = isset($data['interest_earned']) ? [
            'cents' => (int) $data['interest_earned']['cents'],
            'date' => CarbonImmutable::parse($data['interest_earned']['date'] ?? $data['statement_date']),
            'account_id' => (int) $data['interest_earned']['account_id'],
        ] : null;

        $rec = $this->posting(fn () => $this->service->begin(
            $account,
            $statementDate,
            (int) $data['ending_balance_cents'],
            $serviceCharge,
            $interestEarned,
        ));

        return (new BankReconciliationResource($rec))->response()->setStatusCode(201);
    }

    /**
     * Replace the marked-line set on an in-progress reconciliation.
     */
    public function update(UpdateBankReconciliationRequest $request, BankReconciliation $bankReconciliation): BankReconciliationResource
    {
        if (! $bankReconciliation->isInProgress()) {
            $this->conflict('Only an in-progress reconciliation can be updated.');
        }

        $ids = array_values(array_unique(array_map('intval', $request->validated('marked_line_ids'))));

        $bankReconciliation->forceFill(['marked_line_ids' => $ids])->save();

        return new BankReconciliationResource($bankReconciliation->fresh());
    }

    /**
     * Finalize an in-progress reconciliation.
     */
    public function complete(BankReconciliation $bankReconciliation): BankReconciliationResource
    {
        if (! $bankReconciliation->isInProgress()) {
            $this->conflict('Only an in-progress reconciliation can be completed.');
        }

        $rec = $this->posting(fn () => $this->service->complete($bankReconciliation));

        return new BankReconciliationResource($rec);
    }

    /**
     * Cancel an in-progress session, or undo the latest completed one.
     */
    public function destroy(BankReconciliation $bankReconciliation): JsonResponse
    {
        $this->posting(function () use ($bankReconciliation): void {
            if ($bankReconciliation->isInProgress()) {
                $this->service->cancel($bankReconciliation);
            } else {
                $this->service->undo($bankReconciliation);
            }
        });

        return response()->json(null, 204);
    }
}
