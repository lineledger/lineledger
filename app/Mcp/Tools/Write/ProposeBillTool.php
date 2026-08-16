<?php

namespace App\Mcp\Tools\Write;

use App\Enums\Section;
use App\Mcp\Concerns\ProposesWrites;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Proposes a vendor bill (accounts payable). Validates the vendor, line accounts,
 * and amounts read-only, stages the payload, and returns a token — writing
 * NOTHING. Confirm to create (and, unless post=false, post) the bill through the
 * real SaveBill action + BillPoster.
 */
class ProposeBillTool extends Tool
{
    use ProposesWrites;

    protected string $title = 'Propose Bill';

    protected string $description = 'Stage a new vendor bill for confirmation. Provide a "vendor" (name or id), a "bill_date" (YYYY-MM-DD), and "lines" (each with an expense/asset "account" name/code, a "quantity", and a "unit_price" in dollars; optional "description" and "tax_code_id"). Optional: "vendor_reference", "due_date", "memo", and "post" (default true — false creates a draft). This tool only proposes: it returns a token and a preview and writes nothing. Call the confirm-proposal tool with the token to commit. Line accounts must be ordinary expense/asset accounts — Accounts Payable and other system/control accounts are rejected.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAgenticWritesEnabled()) {
            return $denied;
        }
        if ($denied = $this->requireAbility('bills:write')) {
            return $denied;
        }
        if ($denied = $this->requireSection(Section::Vendors)) {
            return $denied;
        }

        $contact = $this->resolveContact($request->get('contact_id') ?? $request->get('vendor'));
        if ($contact === null) {
            return Response::error('No matching vendor was found. Provide an existing vendor name or id.');
        }

        $billDate = $request->get('bill_date');
        if (! is_string($billDate) || $billDate === '') {
            return Response::error('A "bill_date" (YYYY-MM-DD) is required.');
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
            'bill_type' => 'vendor',
            'bill_date' => CarbonImmutable::parse($billDate)->toDateString(),
            'vendor_reference' => $request->get('vendor_reference') ?: null,
            'due_date' => $request->get('due_date') ?: null,
            'memo' => $request->get('memo') ?: null,
            'lines' => $dataLines,
            '_post' => $post,
        ];

        $preview = [
            'PROPOSED BILL'.($post ? ' (will be posted on confirm)' : ' (draft only)'),
            "Vendor: {$contact->display_name}",
            "Bill date: {$payload['bill_date']}",
            'Lines:',
            ...$previewRows,
            'Subtotal (before tax): '.$this->money($subtotal),
            'Taxes and the final total are computed exactly when you confirm.',
        ];

        if ($post && ($warning = $this->lockWarning(CarbonImmutable::parse($billDate)))) {
            $preview[] = $warning;
        }

        return $this->stageProposal('bill', $payload, $preview);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vendor' => $schema->string()->description('The vendor name or id to bill.'),
            'contact_id' => $schema->integer()->description('The vendor contact id (alternative to "vendor").'),
            'bill_date' => $schema->string()->description('Bill date, ISO YYYY-MM-DD.'),
            'vendor_reference' => $schema->string()->description("Optional vendor's invoice/reference number."),
            'due_date' => $schema->string()->description('Optional due date, ISO YYYY-MM-DD.'),
            'memo' => $schema->string()->description('Optional internal memo.'),
            'post' => $schema->boolean()->description('Post the bill to the ledger on confirm (default true). False creates a draft.'),
            'lines' => $schema->array()->items(
                $schema->object([
                    'account' => $schema->string()->description('Expense/asset account name or code for this line.'),
                    'description' => $schema->string()->description('Line description.'),
                    'quantity' => $schema->number()->description('Quantity (default 1).'),
                    'unit_price' => $schema->number()->description('Unit price in dollars.'),
                    'tax_code_id' => $schema->integer()->description('Optional tax code id.'),
                ])
            )->description('The bill line items.'),
        ];
    }
}
