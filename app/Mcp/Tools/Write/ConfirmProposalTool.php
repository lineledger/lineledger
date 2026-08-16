<?php

namespace App\Mcp\Tools\Write;

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Purchasing\SaveBill;
use App\Actions\Sales\SaveInvoice;
use App\Enums\McpProposalStatus;
use App\Enums\Section;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Mcp\Concerns\ProposesWrites;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\McpWriteProposal;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Commits a previously staged write proposal by its token. Re-validates the
 * proposal, then replays its payload through the SAME framework-agnostic Save
 * action (and matching Poster for "post" variants) the LineLedger UI uses — so
 * the lock-date check and the audit trail apply automatically.
 *
 * The commit (action + poster + the status flip to confirmed) runs inside ONE
 * database transaction, so a posting failure (e.g. a locked period) rolls back the
 * draft too: the proposal is never a partial write. Confirming an already-confirmed
 * proposal is an idempotent no-op that returns the prior result.
 */
class ConfirmProposalTool extends Tool
{
    use ProposesWrites;

    protected string $title = 'Confirm Proposal';

    protected string $description = 'Commit a staged write proposal by its token (returned by a propose-* tool). This is the only tool that actually writes to the ledger. It re-validates the proposal and runs it through the real save + post pipeline. Confirming the same token twice is safe — the second call is a no-op that returns the original result. Provide the "token" you were given.';

    public function handle(Request $request): Response
    {
        $token = (string) $request->get('token', '');
        $proposal = $this->loadProposal($token);

        if ($proposal === null) {
            return Response::error('No proposal was found for that token in this organization. Propose the write again to get a fresh token.');
        }

        // Idempotent replay: an already-committed proposal returns its prior result
        // and posts nothing. This is checked before the opt-in/RBAC gates so a replay
        // is always a clean no-op.
        if ($proposal->status === McpProposalStatus::Confirmed) {
            return Response::text($this->confirmedSummary($proposal, replay: true));
        }

        if ($proposal->status === McpProposalStatus::Rejected) {
            return Response::error('This proposal was rejected and cannot be confirmed.');
        }

        if ($proposal->isExpired()) {
            $proposal->forceFill(['status' => McpProposalStatus::Expired])->save();

            return Response::error('This proposal has expired. Propose the write again to get a fresh token.');
        }

        // Re-apply the doubly-opt-in and the RBAC gates the originating tool applied,
        // keyed off the proposal's tool type.
        if ($denied = $this->requireAgenticWritesEnabled()) {
            return $denied;
        }
        [$ability, $section] = $this->gatesFor($proposal->tool);
        if ($denied = $this->requireAbility($ability)) {
            return $denied;
        }
        if ($denied = $this->requireSection($section)) {
            return $denied;
        }

        // Re-validate the system/control-account boundary at commit time, in case the
        // chart changed since the proposal was staged.
        if ($denied = $this->revalidateAccounts($proposal)) {
            return $denied;
        }

        $payload = Arr::except($proposal->payload, ['_post']);
        $post = (bool) ($proposal->payload['_post'] ?? true);

        try {
            $journalEntry = DB::transaction(function () use ($proposal, $payload, $post): ?JournalEntry {
                $journalEntry = $this->commit($proposal->tool, $payload, $post);

                $proposal->forceFill([
                    'status' => McpProposalStatus::Confirmed,
                    'confirmed_journal_entry_id' => $journalEntry?->id,
                ])->save();

                return $journalEntry;
            });
        } catch (PeriodLockedException $e) {
            return Response::error('The books are locked for that date, so the proposal could not be posted. Nothing was written. '.$e->getMessage());
        } catch (UnbalancedJournalException $e) {
            return Response::error('The entry did not balance and was not posted. Nothing was written. '.$e->getMessage());
        } catch (Throwable $e) {
            return Response::error('The proposal could not be committed and nothing was written: '.$e->getMessage());
        }

        $proposal->refresh();

        return Response::text($this->confirmedSummary($proposal, replay: false));
    }

    /**
     * Run the matching Save action and, for a "post" variant, the matching Poster,
     * returning the posted JournalEntry (or null for a draft-only commit).
     *
     * @param  array<string, mixed>  $payload
     */
    private function commit(string $tool, array $payload, bool $post): ?JournalEntry
    {
        return match ($tool) {
            'invoice' => $this->commitInvoice($payload, $post),
            'bill' => $this->commitBill($payload, $post),
            'journal_entry', 'expense' => $this->commitJournalEntry($payload, $post),
            default => throw new \RuntimeException("Unknown proposal type [{$tool}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function commitInvoice(array $payload, bool $post): ?JournalEntry
    {
        /** @var Invoice $invoice */
        $invoice = app(SaveInvoice::class)->handle($payload);

        return $post ? app(InvoicePoster::class)->post($invoice) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function commitBill(array $payload, bool $post): ?JournalEntry
    {
        /** @var Bill $bill */
        $bill = app(SaveBill::class)->handle($payload);

        return $post ? app(BillPoster::class)->post($bill) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function commitJournalEntry(array $payload, bool $post): ?JournalEntry
    {
        $entry = app(SaveJournalEntry::class)->handle($payload);

        // A draft-only commit returns null so confirmed_journal_entry_id stays empty
        // (it records a POSTED entry); the draft itself is still persisted.
        return $post ? app(JournalPoster::class)->post($entry) : null;
    }

    /**
     * The ability + section gate the originating propose tool applied, by tool type.
     *
     * @return array{0: string, 1: Section}
     */
    private function gatesFor(string $tool): array
    {
        return match ($tool) {
            'invoice' => ['invoices:write', Section::Customers],
            'bill' => ['bills:write', Section::Vendors],
            default => ['journal-entries:write', Section::Accounting],
        };
    }

    /**
     * Re-run the system/control-account rejection against the stored payload lines.
     */
    private function revalidateAccounts(McpWriteProposal $proposal): ?Response
    {
        $accounts = [];
        foreach (array_values((array) ($proposal->payload['lines'] ?? [])) as $i => $line) {
            $accounts[$i + 1] = isset($line['account_id'])
                ? $this->resolveAccount((int) $line['account_id'])
                : null;
        }

        return $accounts === [] ? null : $this->rejectSystemAccounts($accounts);
    }

    private function confirmedSummary(McpWriteProposal $proposal, bool $replay): string
    {
        $prefix = $replay
            ? 'This proposal was already confirmed; no changes were made.'
            : 'Confirmed.';

        $lines = [$prefix, '', $proposal->preview];

        if ($proposal->confirmed_journal_entry_id !== null) {
            $entry = JournalEntry::query()->find($proposal->confirmed_journal_entry_id);
            $lines[] = '';
            $lines[] = $entry !== null
                ? "Posted to the ledger as journal entry {$entry->entry_no}."
                : 'Posted to the ledger.';
        } else {
            $lines[] = '';
            $lines[] = 'Saved as a draft (not posted).';
        }

        return implode("\n", $lines);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'token' => $schema->string()->description('The proposal token returned by a propose-* tool.'),
        ];
    }
}
