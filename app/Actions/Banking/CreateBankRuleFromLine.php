<?php

namespace App\Actions\Banking;

use App\Enums\BankRuleMatchType;
use App\Enums\StatementSuggestionSource;
use App\Models\BankRule;
use App\Models\BankStatementLine;
use App\Models\Contact;
use App\Services\Banking\Import\BankRuleEngine;
use App\Services\Classification\Support\MerchantKey;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Always do this": turn one categorized statement line into a durable bank
 * rule — a merchant-key match on the payee part of its description (so next
 * month's reference number does not matter) that assigns the same account and
 * vendor. Re-running for the same payee updates the existing rule instead of
 * stacking duplicates, and the rest of the uncommitted import is re-scanned so
 * sibling lines pick it up immediately.
 */
final class CreateBankRuleFromLine
{
    public function __construct(
        private readonly SaveBankRule $save,
        private readonly BankRuleEngine $engine,
    ) {}

    /**
     * The active rule that already covers this line's description, if any —
     * the "Rule exists" state on review.
     */
    public function existingFor(BankStatementLine $line): ?BankRule
    {
        return $this->engine->firstMatchingFor((int) $line->company_id, $line->description);
    }

    /**
     * @throws ValidationException when the description is too generic to key a rule on
     */
    public function handle(BankStatementLine $line, int $accountId, ?int $contactId = null, bool $applyToImport = true): BankRule
    {
        $key = MerchantKey::from($line->description);

        if (! MerchantKey::isUsable($key)) {
            throw ValidationException::withMessages(['rule' => __('This description is too short to build a rule from.')]);
        }

        $contact = $contactId !== null
            ? Contact::query()->where('company_id', $line->company_id)->whereKey($contactId)->first()
            : null;

        $existing = BankRule::query()
            ->where('company_id', $line->company_id)
            ->where('match_type', BankRuleMatchType::MerchantKey->value)
            ->where('match_pattern', $key)
            ->first();

        $rule = $this->save->handle([
            'name' => Str::limit($contact->display_name ?? Str::title($key), 255, ''),
            'match_type' => BankRuleMatchType::MerchantKey->value,
            'match_pattern' => $key,
            'action_account_id' => $accountId,
            'action_contact_id' => $contact?->id,
            'priority' => $existing->priority ?? 0,
            'is_active' => true,
        ], $existing);

        if ($line->created_journal_entry_id === null) {
            $line->forceFill([
                'suggested_account_id' => $accountId,
                'suggested_contact_id' => $contact->id ?? $line->suggested_contact_id,
                'suggestion_source' => StatementSuggestionSource::Rule->value,
                'match_reason' => __('Categorized by rule ":name".', ['name' => $rule->name]),
            ])->save();
        }

        if ($applyToImport) {
            $import = $line->import()->first();

            if ($import !== null && ! $import->isCommitted()) {
                $this->engine->apply($import);
            }
        }

        return $rule;
    }
}
