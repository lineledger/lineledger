<?php

namespace App\Mcp\Resources;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\PaymentMethodsPresenter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class PaymentMethodsResource extends Resource
{
    use AnswersBusinessQuestions;

    protected string $uri = 'lineledger://lists/payment-methods';

    protected string $mimeType = 'text/plain';

    protected string $title = 'Payment methods';

    protected string $description = 'The configured payment methods (Cash, Cheque, Credit card, …): name, whether the method is a cheque method, active status, and the numeric API id (the payment_method_id the REST API expects on receipts and bill payments). Read-only.';

    public function handle(Request $request): Response
    {
        // Payment methods live under Settings → Lists, and /api/v1/payment-methods
        // is scoped to the `settings` ability domain — mirror both here.
        if ($denied = $this->requireAbility('settings:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Lists)) {
            return $denied;
        }

        return Response::text(app(PaymentMethodsPresenter::class)->render($this->company()));
    }
}
