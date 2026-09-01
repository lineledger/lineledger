<?php

namespace App\Actions\Accounting;

use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Accounting\OpeningBalanceAccountResolver;
use App\Services\Migration\Importers\TrialBalanceImporter;
use App\Services\Posting\JournalPoster;
use Illuminate\Validation\ValidationException;

/**
 * Posts a two-line opening-balance journal entry for a freshly created
 * balance-sheet account: the account itself on its normal-balance side and
 * Opening Balance Equity on the counter side, so the books stay balanced.
 *
 * The amount is a positive magnitude — the account's normal_balance decides
 * the debit/credit direction. Negative amounts are rejected rather than
 * silently flipping sides: a "negative opening balance" is ambiguous (is it a
 * contra balance or a typo?) and is better entered as an explicit journal
 * entry where the user picks the side.
 *
 * Locating Opening Balance Equity mirrors
 * {@see TrialBalanceImporter}: the
 * company-scoped Equity-subtype account named exactly 'Opening Balance Equity'.
 */
final class PostAccountOpeningBalance
{
    public function handle(Account $account, int $amountCents, string $asOfDate): JournalEntry
    {
        if ($amountCents <= 0) {
            throw ValidationException::withMessages([
                'opening_balance' => __('The opening balance must be a positive amount; use a journal entry for contra balances.'),
            ]);
        }

        $obe = app(OpeningBalanceAccountResolver::class)->resolve((int) $account->company_id);

        if (! $obe) {
            throw ValidationException::withMessages([
                'opening_balance' => __("The 'Opening Balance Equity' account is missing, so an opening balance cannot be posted. Recreate it (an Equity account named 'Opening Balance Equity') or record the balance with a journal entry."),
            ]);
        }

        $debitNormal = $account->normal_balance === NormalBalance::Debit;

        $entry = app(SaveJournalEntry::class)->handle([
            'entry_date' => $asOfDate,
            'memo' => 'Opening balance',
            'lines' => [
                [
                    'account_id' => $account->id,
                    'debit_cents' => $debitNormal ? $amountCents : 0,
                    'credit_cents' => $debitNormal ? 0 : $amountCents,
                    'memo' => "Opening balance — {$account->code} {$account->name}",
                ],
                [
                    'account_id' => $obe->id,
                    'debit_cents' => $debitNormal ? 0 : $amountCents,
                    'credit_cents' => $debitNormal ? $amountCents : 0,
                    'memo' => 'Opening Balance Equity',
                ],
            ],
        ]);

        return app(JournalPoster::class)->post($entry);
    }
}
