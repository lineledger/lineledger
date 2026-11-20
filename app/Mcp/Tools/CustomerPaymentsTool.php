<?php

namespace App\Mcp\Tools;

use App\Enums\Activity;
use App\Enums\ChequeStatus;
use App\Enums\CreditMemoStatus;
use App\Enums\ReceiptStatus;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Cheque;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\ReceiptApplication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CustomerPaymentsTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Customer payments';

    protected string $description = 'Every payment, refund, and credit for one customer: posted receipts (date, number, payment method such as Cash, Cheque, E-transfer or an insurer payout, reference / cheque number, amount, and the invoices each was applied to with amounts), refunds paid back by card or cheque, credit memos, and the unapplied credit (deposits and credits) the customer still holds. Name the customer by name, a fragment of the name, or its API id; optionally limit to a start/end date window. Amounts are in each document\'s own currency. Read-only; never modifies data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('sales:read')) {
            return $denied;
        }

        if ($denied = $this->requirePermission(Activity::Receipts)) {
            return $denied;
        }

        $customer = $this->resolveCustomer((string) $request->get('customer'));

        if (is_string($customer)) {
            return Response::text($customer);
        }

        $start = $this->date($request->get('start'));
        $end = $this->date($request->get('end'));

        $receipts = $this->inWindow(CustomerReceipt::query(), 'receipt_date', $start, $end)
            ->where('contact_id', $customer->id)
            ->where('status', ReceiptStatus::Posted->value)
            ->with(['paymentMethod', 'applications.invoice', 'creditMemo', 'refundedReceipt'])
            ->orderBy('receipt_date')
            ->orderBy('id')
            ->get();

        $window = $start === null && $end === null
            ? 'all dates'
            : ($start ?? 'the beginning').' to '.($end ?? 'today');

        $out = ["Payments and credits for {$customer->display_name} (API id {$customer->id}), {$window}:", ''];

        $payments = $receipts->reject(fn (CustomerReceipt $r): bool => $r->isRefund());
        $out[] = $payments->isEmpty() ? 'Payments received: none' : 'Payments received:';
        foreach ($payments as $receipt) {
            $out = [...$out, ...$this->receiptLines($receipt)];
        }

        $refunds = $receipts->filter(fn (CustomerReceipt $r): bool => $r->isRefund())
            ->map(fn (CustomerReceipt $r): array => [
                'date' => $r->receipt_date?->toDateString() ?? '',
                'text' => "{$r->receipt_date?->toDateString()} {$r->receipt_no} (API id {$r->id}) — card refund of ".$this->refundSource($r).': '.$this->moneyIn(abs((int) $r->amount_cents), $r->currency_code),
            ]);

        if ($this->requirePermission(Activity::Cheques) === null) {
            $cheques = $this->inWindow(Cheque::query(), 'cheque_date', $start, $end)
                ->where('payee_contact_id', $customer->id)
                ->where(fn (Builder $q) => $q->whereNotNull('credit_memo_id')->orWhereNotNull('refunded_receipt_id'))
                ->where('status', ChequeStatus::Posted->value)
                ->with(['creditMemo', 'refundedReceipt'])
                ->get()
                ->map(fn (Cheque $c): array => [
                    'date' => $c->cheque_date?->toDateString() ?? '',
                    'text' => "{$c->cheque_date?->toDateString()} cheque ".($c->cheque_no ?: '(no number)')." (API id {$c->id}) — refund of ".$this->chequeRefundSource($c).': '.$this->moneyIn((int) $c->amount_cents, $c->currency_code),
                ]);
            $refunds = $refunds->concat($cheques);
        }

        $out[] = '';
        $out[] = $refunds->isEmpty() ? 'Refunds paid out: none' : 'Refunds paid out:';
        foreach ($refunds->sortBy('date') as $refund) {
            $out[] = '- '.$refund['text'];
        }

        $creditHeld = 0;
        $seesCredits = $this->requirePermission(Activity::CreditMemos) === null;

        if ($seesCredits) {
            $memos = $this->inWindow(CreditMemo::query(), 'credit_memo_date', $start, $end)
                ->where('contact_id', $customer->id)
                ->where('status', CreditMemoStatus::Posted->value)
                ->orderBy('credit_memo_date')
                ->get();

            $out[] = '';
            $out[] = $memos->isEmpty() ? 'Credit memos: none' : 'Credit memos:';
            foreach ($memos as $memo) {
                $refunded = $memo->refundedCents();
                $out[] = "- {$memo->credit_memo_date?->toDateString()} {$memo->credit_memo_no} (API id {$memo->id}): ".$this->moneyIn((int) $memo->total_cents, $memo->currency_code)
                    .($refunded > 0 ? ', refunded '.$this->moneyIn($refunded, $memo->currency_code) : '')
                    .', credit remaining '.$this->moneyIn($memo->remainingRefundableCents(), $memo->currency_code);
            }
        }

        // What the customer holds right now — every posted payment and credit
        // memo, whatever the date window above.
        $unappliedPayments = CustomerReceipt::query()
            ->where('contact_id', $customer->id)
            ->where('status', ReceiptStatus::Posted->value)
            ->whereNull('credit_memo_id')
            ->whereNull('refunded_receipt_id')
            ->with('applications')
            ->get()
            ->sum(fn (CustomerReceipt $r): int => max(0, $r->unappliedCents()));

        if ($seesCredits) {
            $creditHeld = (int) CreditMemo::query()
                ->where('contact_id', $customer->id)
                ->where('status', CreditMemoStatus::Posted->value)
                ->get()
                ->sum(fn (CreditMemo $m): int => $m->remainingRefundableCents());
        }

        $currency = $customer->currency_code;
        $out[] = '';
        $out[] = 'Unapplied credit the customer holds now: '.$this->moneyIn($unappliedPayments, $currency).' in unapplied payments'
            .($seesCredits ? ' and '.$this->moneyIn($creditHeld, $currency).' in credit memos' : '')
            .'.';

        return Response::text(implode("\n", $out));
    }

    /**
     * @return list<string>
     */
    private function receiptLines(CustomerReceipt $receipt): array
    {
        $currency = $receipt->currency_code;
        $method = $receipt->paymentMethod->name ?? 'no method';
        $reference = filled($receipt->reference) ? " · ref {$receipt->reference}" : '';

        $lines = ["- {$receipt->receipt_date?->toDateString()} {$receipt->receipt_no} (API id {$receipt->id}) · {$method}{$reference}: ".$this->moneyIn((int) $receipt->amount_cents, $currency)];

        foreach ($receipt->applications as $application) {
            /** @var ReceiptApplication $application */
            $lines[] = '    applied to '.($application->invoice->invoice_no ?? 'an invoice')." (API id {$application->invoice_id}): ".$this->moneyIn((int) $application->amount_cents, $currency);
        }

        $unapplied = $receipt->unappliedCents();
        if ($unapplied !== 0) {
            $lines[] = '    unapplied (held as credit): '.$this->moneyIn($unapplied, $currency);
        }

        return $lines;
    }

    private function refundSource(CustomerReceipt $refund): string
    {
        if ($refund->creditMemo !== null) {
            return "credit memo {$refund->creditMemo->credit_memo_no}";
        }

        return $refund->refundedReceipt !== null ? "payment {$refund->refundedReceipt->receipt_no}" : 'a credit';
    }

    private function chequeRefundSource(Cheque $cheque): string
    {
        if ($cheque->creditMemo !== null) {
            return "credit memo {$cheque->creditMemo->credit_memo_no}";
        }

        return $cheque->refundedReceipt !== null ? "payment {$cheque->refundedReceipt->receipt_no}" : 'a credit';
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function inWindow(Builder $query, string $column, ?string $start, ?string $end): Builder
    {
        return $query
            ->when($start !== null, fn (Builder $q) => $q->where($column, '>=', $start))
            ->when($end !== null, fn (Builder $q) => $q->where($column, '<=', $end));
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toDateString() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'customer' => $schema->string()
                ->description('The customer: a name, a distinctive fragment of one (e.g. a file number in the name), or its numeric API id. Required.'),
            'start' => $schema->string()
                ->description('Optional first date to include (ISO, YYYY-MM-DD).'),
            'end' => $schema->string()
                ->description('Optional last date to include (ISO, YYYY-MM-DD).'),
        ];
    }
}
