<?php

namespace App\Mcp\Tools\Write;

use App\Enums\Section;
use App\Mcp\Concerns\ProposesWrites;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Proposes a customer invoice. Validates the customer, every line account, and the
 * amounts read-only, then stages the cents-normalized payload as a proposal and
 * returns a token — it writes NOTHING. Confirm the returned token to actually
 * create (and, unless post=false, post) the invoice through the real SaveInvoice
 * action + InvoicePoster.
 */
class ProposeInvoiceTool extends Tool
{
    use ProposesWrites;

    protected string $title = 'Propose Invoice';

    protected string $description = 'Stage a new customer invoice for confirmation. Provide a "customer" (name or id), an "invoice_date" (YYYY-MM-DD), and "lines" (each with an income "account" name/code, a "quantity", and a "unit_price" in dollars; optional "description" and "tax_code_id"). Optional: "due_date", "memo", "customer_message", and "post" (default true — set false to create a draft only). This tool only proposes: it returns a token and a preview and writes nothing. Call the confirm-proposal tool with the token to commit. Line accounts must be ordinary income/asset accounts — Accounts Receivable and other system/control accounts are rejected.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAgenticWritesEnabled()) {
            return $denied;
        }
        if ($denied = $this->requireAbility('invoices:write')) {
            return $denied;
        }
        if ($denied = $this->requireSection(Section::Customers)) {
            return $denied;
        }

        $contact = $this->resolveContact($request->get('contact_id') ?? $request->get('customer'));
        if ($contact === null) {
            return Response::error('No matching customer was found. Provide an existing customer name or id.');
        }

        $invoiceDate = $request->get('invoice_date');
        if (! is_string($invoiceDate) || $invoiceDate === '') {
            return Response::error('An "invoice_date" (YYYY-MM-DD) is required.');
        }

        $rawLines = $request->get('lines');
        if (! is_array($rawLines) || $rawLines === []) {
            return Response::error('At least one line is required.');
        }

        $accounts = [];
        $dataLines = [];
        $subtotal = 0;
        $previewRows = [];

        foreach (array_values($rawLines) as $i => $line) {
            $lineNo = $i + 1;
            $account = $this->resolveAccount($line['account'] ?? $line['account_id'] ?? null);
            $accounts[$lineNo] = $account;

            $qty = (string) ($line['quantity'] ?? 1);
            $unitCents = $this->toCents($line['unit_price'] ?? null, $line['unit_price_cents'] ?? null);
            if ($unitCents === null) {
                return Response::error("Line {$lineNo}: a numeric \"unit_price\" is required.");
            }

            $dataLines[] = [
                'account_id' => $account?->id,
                'description' => $line['description'] ?? null,
                'quantity' => $qty,
                'unit_price_cents' => $unitCents,
                'tax_code_id' => $line['tax_code_id'] ?? null,
            ];

            $lineSubtotal = (int) round((float) $qty * $unitCents);
            $subtotal += $lineSubtotal;
            $desc = $line['description'] ?? 'Line item';
            $previewRows[] = "  - {$desc}: {$qty} x ".$this->money($unitCents).' = '.$this->money($lineSubtotal)
                .($account !== null ? " [{$account->code} {$account->name}]" : '');
        }

        if ($denied = $this->rejectSystemAccounts($accounts)) {
            return $denied;
        }

        $post = $request->get('post', true) !== false;

        $payload = [
            'contact_id' => $contact->id,
            'invoice_date' => CarbonImmutable::parse($invoiceDate)->toDateString(),
            'due_date' => $request->get('due_date') ?: null,
            'memo' => $request->get('memo') ?: null,
            'customer_message' => $request->get('customer_message') ?: null,
            'lines' => $dataLines,
            '_post' => $post,
        ];

        $preview = [
            'PROPOSED INVOICE'.($post ? ' (will be posted on confirm)' : ' (draft only)'),
            "Customer: {$contact->display_name}",
            "Invoice date: {$payload['invoice_date']}",
            'Lines:',
            ...$previewRows,
            'Subtotal (before tax): '.$this->money($subtotal),
            'Taxes and the final total are computed exactly when you confirm.',
        ];

        if ($post && ($warning = $this->lockWarning(CarbonImmutable::parse($invoiceDate)))) {
            $preview[] = $warning;
        }

        return $this->stageProposal('invoice', $payload, $preview);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'customer' => $schema->string()->description('The customer name or id to invoice.'),
            'contact_id' => $schema->integer()->description('The customer contact id (alternative to "customer").'),
            'invoice_date' => $schema->string()->description('Invoice date, ISO YYYY-MM-DD.'),
            'due_date' => $schema->string()->description('Optional due date, ISO YYYY-MM-DD.'),
            'memo' => $schema->string()->description('Optional internal memo.'),
            'customer_message' => $schema->string()->description('Optional message shown to the customer.'),
            'post' => $schema->boolean()->description('Post the invoice to the ledger on confirm (default true). False creates a draft.'),
            'lines' => $schema->array()->items(
                $schema->object([
                    'account' => $schema->string()->description('Income/asset account name or code for this line.'),
                    'description' => $schema->string()->description('Line description.'),
                    'quantity' => $schema->number()->description('Quantity (default 1).'),
                    'unit_price' => $schema->number()->description('Unit price in dollars.'),
                    'tax_code_id' => $schema->integer()->description('Optional tax code id.'),
                ])
            )->description('The invoice line items.'),
        ];
    }
}
