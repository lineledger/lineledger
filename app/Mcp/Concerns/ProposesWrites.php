<?php

namespace App\Mcp\Concerns;

use App\Enums\AccountSubtype;
use App\Enums\McpProposalStatus;
use App\Models\Account;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\McpWriteProposal;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;

/**
 * Shared machinery for the agentic (write-enabled) MCP tools. It layers the
 * propose -> confirm safety model on top of the read-only authorization helpers in
 * {@see AnswersBusinessQuestions} (which this trait pulls in), and centralizes the
 * three things every write tool must do BEFORE anything is committed:
 *
 *   1. confirm agentic writes are switched on (operator config flag AND the
 *      per-company opt-in — the same doubly-opt-in pattern as the AI features);
 *   2. refuse any line that targets a system / control account (AR, AP, Retained
 *      Earnings, or anything flagged is_system) — posters resolve those themselves,
 *      so a caller pointing a line at one would double-count or corrupt AR/AP;
 *   3. stage the validated, cents-normalized payload as an {@see McpWriteProposal}
 *      and hand back a token — writing NOTHING to the ledger.
 *
 * The matching ConfirmProposal tool then replays the payload through the real Save
 * action (+ Poster), inheriting the lock-date check and the audit trail from that
 * pipeline. This trait never posts.
 */
trait ProposesWrites
{
    use AnswersBusinessQuestions;

    /**
     * The subtypes a write tool must never let a caller post a line to. AR/AP and
     * Undeposited Funds are control accounts the posters resolve themselves (a
     * direct line double-counts and breaks the GL-driven AR/AP reports); Retained
     * Earnings is a closing account that must not receive ad-hoc transactional
     * lines. This mirrors Account::scopeSelectableForItemAccount, which is the
     * canonical "what may a line post to" gate.
     *
     * Note we key off SUBTYPE, not the is_system flag: this app marks several
     * legitimate posting accounts (e.g. Cost of Goods Sold, default income/tax
     * accounts) is_system as seeded-default protection, so is_system would
     * over-reject. The subtype list is the precise control-account boundary.
     *
     * @return list<AccountSubtype>
     */
    protected function blockedSubtypes(): array
    {
        return [
            AccountSubtype::AccountsReceivable,
            AccountSubtype::AccountsPayable,
            AccountSubtype::UndepositedFunds,
            AccountSubtype::RetainedEarnings,
        ];
    }

    /**
     * The doubly-opt-in gate. Writes are refused unless the operator has enabled
     * the agentic server (`config('mcp.write_enabled')`, default off) AND this
     * specific company has opted in (`settings.mcp.agentic_writes`, default off).
     * Returns an error Response when either is missing, or null when permitted.
     *
     * Mirror of Company::insightsAiNarrationEnabled — the per-company flag is read
     * through Company::agenticWritesEnabled(), itself a thin wrapper over the
     * settings JSON.
     */
    protected function requireAgenticWritesEnabled(): ?Response
    {
        if (! (bool) config('mcp.write_enabled', false)) {
            return Response::error(
                'Agentic writes are disabled on this server. An operator must enable them (MCP_WRITE_ENABLED).'
            );
        }

        if (! $this->company()->agenticWritesEnabled()) {
            return Response::error(
                'This organization has not enabled agentic writes. Turn them on in the LineLedger settings first.'
            );
        }

        return null;
    }

    /**
     * Resolve a caller-supplied account reference (numeric id, exact code, or a
     * name) to a tenant account. Returns null when nothing matches — the global
     * CompanyScope keeps the lookup inside the current company automatically.
     */
    protected function resolveAccount(mixed $ref): ?Account
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            $byId = Account::query()->whereKey((int) $ref)->first();
            if ($byId !== null) {
                return $byId;
            }
        }

        $value = trim((string) $ref);

        return Account::query()
            ->where(function ($where) use ($value) {
                $where->where('code', $value)->orWhere('name', 'like', "%{$value}%");
            })
            ->orderBy('code')
            ->first();
    }

    /**
     * Resolve a caller-supplied contact reference (numeric id or display name) to a
     * tenant contact, scoped by CompanyScope. Returns null when nothing matches.
     */
    protected function resolveContact(mixed $ref): ?Contact
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            $byId = Contact::query()->whereKey((int) $ref)->first();
            if ($byId !== null) {
                return $byId;
            }
        }

        $value = trim((string) $ref);

        return Contact::query()
            ->where('display_name', 'like', "%{$value}%")
            ->orderBy('display_name')
            ->first();
    }

    /**
     * Reject the whole proposal if any referenced account is a system/control
     * account (or simply unknown). Pass the Account models already resolved by the
     * tool. Returns an error Response to surface, or null when every account is a
     * safe, selectable posting target.
     *
     * @param  array<int, Account|null>  $accounts  keyed by the human line number (1-based)
     */
    protected function rejectSystemAccounts(array $accounts): ?Response
    {
        // Compare on the backing string values: this project's static analysis
        // types enum-cast columns as their backing type, so we read the raw stored
        // subtype (a string) rather than the cast enum to keep the check both
        // runtime-correct and analyzable.
        $blocked = array_map(static fn (AccountSubtype $s): string => $s->value, $this->blockedSubtypes());

        foreach ($accounts as $lineNo => $account) {
            if (! $account instanceof Account) {
                return Response::error("Line {$lineNo}: no matching account was found. Use an exact account code or name.");
            }

            if (in_array($account->getRawOriginal('subtype'), $blocked, true)) {
                return Response::error(
                    "Line {$lineNo}: \"{$account->code} — {$account->name}\" is a system/control account "
                    .'(Accounts Receivable, Accounts Payable, Undeposited Funds, or Retained Earnings) and cannot '
                    .'be posted to directly. Choose an income, expense, asset, or liability account instead.'
                );
            }
        }

        return null;
    }

    /**
     * Convert a caller-supplied amount (a decimal string/number of dollars, or an
     * explicit `*_cents` integer) into integer cents, defensively. Returns null on
     * unparseable input so the caller can reject the line.
     */
    protected function toCents(mixed $dollars, mixed $cents = null): ?int
    {
        if ($cents !== null && $cents !== '' && (is_int($cents) || (is_string($cents) && ctype_digit(ltrim((string) $cents, '-'))))) {
            return (int) $cents;
        }

        if ($dollars === null || $dollars === '') {
            return null;
        }

        return Money::tryFromString((string) $dollars, $this->company()->currency_code)?->cents;
    }

    /**
     * A one-line "period is locked" warning for a proposal preview when the target
     * date falls in a closed period, or null when the period is open. Proposing is
     * still allowed — the actual block fires in the poster at confirm time — but the
     * caller is told up front that confirming will fail.
     */
    protected function lockWarning(CarbonImmutable $date): ?string
    {
        $company = $this->company();

        if (! $company->isLockedFor($date)) {
            return null;
        }

        $lock = CarbonImmutable::parse($company->lock_date)->toDateString();

        return "WARNING: {$date->toDateString()} is on or before the books lock date ({$lock}). "
            .'Confirming this proposal will be rejected as a locked period until the date or lock is changed.';
    }

    /**
     * Stage a validated payload as a pending proposal and return the token+preview
     * Response. Writes the proposal row only — never the ledger.
     *
     * @param  array<string, mixed>  $payload  the cents-normalized $data for the Save action
     * @param  array<int, string>  $previewLines
     */
    protected function stageProposal(string $tool, array $payload, array $previewLines): Response
    {
        $key = app()->bound('current_api_key') ? app('current_api_key') : null;
        $user = Auth::guard('api')->user();

        $proposal = McpWriteProposal::create([
            'company_api_key_id' => $key instanceof CompanyApiKey ? $key->id : null,
            'user_id' => $user instanceof User ? $user->id : null,
            'tool' => $tool,
            'payload' => $payload,
            'preview' => implode("\n", $previewLines),
            'idempotency_key' => (string) Str::ulid(),
            'status' => McpProposalStatus::Pending,
            'expires_at' => CarbonImmutable::now()->addHours(24),
        ]);

        $lines = $previewLines;
        $lines[] = '';
        $lines[] = 'Nothing has been written yet. This is a proposal only.';
        $lines[] = "To commit it, call the confirm-proposal tool with token: {$proposal->idempotency_key}";
        $lines[] = "The proposal expires at {$proposal->expires_at->toDateTimeString()}.";

        return Response::text(implode("\n", $lines));
    }

    /**
     * Load a pending-or-resolved proposal by its token, scoped to the current
     * company by the global CompanyScope (so a token minted for another tenant is
     * simply not found here). Returns null when no such proposal exists.
     */
    protected function loadProposal(string $token): ?McpWriteProposal
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return McpWriteProposal::query()
            ->where('idempotency_key', $token)
            ->first();
    }
}
