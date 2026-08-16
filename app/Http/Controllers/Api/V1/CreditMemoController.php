<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\SaveCreditMemo;
use App\Enums\CreditMemoStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\RefundCreditMemoRequest;
use App\Http\Requests\Api\V1\StoreCreditMemoRequest;
use App\Http\Requests\Api\V1\UpdateCreditMemoRequest;
use App\Http\Resources\Api\V1\CreditMemoResource;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreditMemoController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected CreditMemoPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CreditMemo::query()->with('lines');

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'credit_memo_date',
            'search' => ['credit_memo_no', 'memo'],
            'sortable' => ['credit_memo_date', 'credit_memo_no', 'total_cents', 'id'],
        ]);

        return CreditMemoResource::collection($this->paginateApi($query, $request));
    }

    public function show(CreditMemo $creditMemo): CreditMemoResource
    {
        return new CreditMemoResource($creditMemo->load('lines'));
    }

    public function store(StoreCreditMemoRequest $request): JsonResponse
    {
        $data = $request->validated();

        $creditMemo = $this->posting(function () use ($data, $request): CreditMemo {
            $creditMemo = app(SaveCreditMemo::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($creditMemo);
            }

            return $creditMemo->fresh(['lines']);
        });

        return (new CreditMemoResource($creditMemo))->response()->setStatusCode(201);
    }

    public function update(UpdateCreditMemoRequest $request, CreditMemo $creditMemo): CreditMemoResource
    {
        if ($creditMemo->status === CreditMemoStatus::Void) {
            $this->conflict('A voided credit memo cannot be edited.');
        }

        $wasPosted = $creditMemo->journal_entry_id !== null;

        $creditMemo = $this->posting(function () use ($request, $creditMemo, $wasPosted): CreditMemo {
            $creditMemo = app(SaveCreditMemo::class)->handle($request->validated(), $creditMemo);

            if ($wasPosted) {
                $this->poster->repost($creditMemo);
            }

            return $creditMemo->fresh(['lines']);
        });

        return new CreditMemoResource($creditMemo);
    }

    /**
     * Post a draft, or repost edits to an already-posted credit memo.
     */
    public function post(CreditMemo $creditMemo): CreditMemoResource
    {
        if ($creditMemo->status === CreditMemoStatus::Void) {
            $this->conflict('A voided credit memo cannot be posted.');
        }

        $creditMemo = $this->posting(function () use ($creditMemo): CreditMemo {
            $creditMemo->journal_entry_id !== null
                ? $this->poster->repost($creditMemo)
                : $this->poster->post($creditMemo);

            return $creditMemo->fresh(['lines']);
        });

        return new CreditMemoResource($creditMemo);
    }

    /**
     * Void a posted credit memo (reversing JE) or hard-delete a draft.
     */
    public function destroy(CreditMemo $creditMemo): JsonResponse
    {
        if ($creditMemo->status === CreditMemoStatus::Void) {
            $this->conflict('Credit memo is already voided.');
        }

        if ($creditMemo->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($creditMemo));

            return (new CreditMemoResource($creditMemo->fresh(['lines'])))->response()->setStatusCode(200);
        }

        $creditMemo->lines()->delete();
        $creditMemo->delete();

        return response()->json(null, 204);
    }

    /**
     * Refund a credit memo (money out) — the API equivalent of "Refund to client
     * → by credit card": records and posts a NEGATIVE customer receipt against
     * the credit memo (DR Accounts Receivable, CR the deposit account). Returns
     * the created receipt.
     */
    public function refund(RefundCreditMemoRequest $request, CreditMemo $creditMemo, ReceiptPoster $poster): JsonResponse
    {
        // Mirror the UI's canRefund guard: a refund is money out clearing a
        // posted credit. Refunding a draft would clear a credit that never hit
        // the ledger; refunding a void would pay out against a reversed credit.
        if ($creditMemo->status !== CreditMemoStatus::Posted) {
            $this->conflict('Only a posted credit memo can be refunded.');
        }

        $data = $request->validated();
        $company = app('current_company');

        $receipt = $this->posting(function () use ($data, $creditMemo, $company, $poster): CustomerReceipt {
            $receipt = CustomerReceipt::create([
                'company_id' => $company->id,
                'contact_id' => $creditMemo->contact_id,
                'credit_memo_id' => $creditMemo->id,
                'receipt_no' => $data['receipt_no'] ?? app(DocumentNumberGenerator::class)->next($company, CustomerReceipt::class, 'receipt_no', 'REC'),
                'receipt_date' => $data['refund_date'],
                'deposit_to_account_id' => (int) $data['deposit_to_account_id'],
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'amount_cents' => -1 * (int) $data['amount_cents'],
                'memo' => $data['memo'] ?? ('Refund of credit memo '.$creditMemo->credit_memo_no),
            ]);

            $poster->post($receipt);

            return $receipt->fresh(['applications']);
        });

        return (new ReceiptResource($receipt))->response()->setStatusCode(201);
    }
}
