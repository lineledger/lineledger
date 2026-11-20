<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Enums\SlipType;
use App\Models\Bill;
use App\Models\Company;
use App\Services\Deletion\DeletionWork;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillReconciler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a bill payment. Its applications are its own, so they go with it
 * and the bills it paid show as owing again. A finalized T4A year it feeds
 * blocks.
 */
final class BillPaymentDeletionHandler extends DocumentDeletionHandler
{
    /** @var list<int> */
    private array $bills = [];

    public function __construct(
        private readonly BillPaymentPoster $payments,
        private readonly BillReconciler $reconciler,
    ) {}

    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::BillPayment;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function totalColumn(): string
    {
        return 'amount_cents';
    }

    public function blockers(Model $document): array
    {
        return $this->finalizedSlipBlocker($document, SlipType::T4a, $document->getAttribute('payment_date'));
    }

    public function warnings(Model $document): array
    {
        $numbers = $this->appliedBillNumbers($document);

        return $numbers === [] || $document->getAttribute('voided_at') !== null
            ? []
            : [__('It is applied to :bills, which will show as owing again.', ['bills' => implode(', ', $numbers)])];
    }

    protected function extraSnapshot(Model $document): array
    {
        return [
            'applications' => DB::table('bill_payment_applications as a')
                ->join('bills as b', 'b.id', '=', 'a.bill_id')
                ->where('a.bill_payment_id', $document->getKey())
                ->orderBy('a.id')
                ->get(['b.bill_no', 'a.amount_cents'])
                ->map(fn ($row): array => ['invoice_no' => (string) $row->bill_no, 'amount_cents' => (int) $row->amount_cents])
                ->all(),
        ];
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        $this->bills = DB::table('bill_payment_applications')->where('bill_payment_id', $document->getKey())->pluck('bill_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        DB::table('bill_payment_applications')->where('bill_payment_id', $document->getKey())->delete();
    }

    public function afterPurge(DeletionWork $work, int $companyId): void
    {
        foreach (Bill::withoutGlobalScopes()->whereIn('id', $this->bills)->get() as $bill) {
            $this->payments->recomputeBillPaidFromAllPayments($bill);
        }

        // AP went up: a bill "Close settled balance" closed against this
        // payment's credit opens again.
        $company = Company::query()->find($companyId);

        if ($company !== null) {
            foreach (array_keys($work->contactIds) as $contactId) {
                $this->reconciler->releaseExcessReconciliation($company, $contactId);
            }
        }

        $this->bills = [];
    }

    /**
     * @return list<string>
     */
    private function appliedBillNumbers(Model $document): array
    {
        return DB::table('bill_payment_applications as a')
            ->join('bills as b', 'b.id', '=', 'a.bill_id')
            ->where('a.bill_payment_id', $document->getKey())
            ->orderBy('b.bill_no')
            ->pluck('b.bill_no')
            ->unique()
            ->map(fn ($no): string => (string) $no)
            ->values()
            ->all();
    }
}
