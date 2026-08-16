<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\PaymentMethodsPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * The tool half of the payment-methods listing; see {@see ItemsCatalogTool} for
 * why these reference listings are offered as both a tool and a resource.
 */
class PaymentMethodsTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Payment methods';

    protected string $description = 'The configured payment methods (Cash, Cheque, Credit card, …) with each method\'s name, whether it is a cheque method, active status, and numeric API id. Use this to look up the "API id" (the payment_method_id the REST API expects on receipts and bill payments) for a method you know by name. Read-only and never modifies data.';

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

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
