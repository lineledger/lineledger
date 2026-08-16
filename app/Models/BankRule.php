<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\BankRuleMatchType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An auto-categorization rule for imported bank statement lines. When a line's
 * description matches the pattern, the rule's account becomes the suggested
 * contra account for adding that line to the ledger.
 *
 * @property BankRuleMatchType $match_type
 */
#[Fillable([
    'company_id', 'name', 'match_type', 'match_pattern', 'action_account_id', 'is_active', 'priority',
])]
class BankRule extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function actionAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'action_account_id');
    }

    /**
     * Whether this rule's pattern matches the given statement-line description.
     */
    public function matchesDescription(?string $description): bool
    {
        return $this->match_type->matches($description ?? '', (string) $this->match_pattern);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_type' => BankRuleMatchType::class,
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }
}
