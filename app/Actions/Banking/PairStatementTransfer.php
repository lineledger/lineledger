<?php

namespace App\Actions\Banking;

use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\BankStatementLine;
use App\Services\Posting\TransferPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records the two legs of an inter-account transfer as a single posted Transfer,
 * clearing the parked "money in transit". The outflow line names the source
 * account, the inflow line the destination; both are then matched to the
 * transfer's bank legs and drop out of the review feed.
 */
final class PairStatementTransfer
{
    public function __construct(
        private readonly SaveTransfer $saveTransfer,
        private readonly TransferPoster $poster,
    ) {}

    public function handle(BankStatementLine $outLine, BankStatementLine $inLine): void
    {
        if ($outLine->created_journal_entry_id !== null || $inLine->created_journal_entry_id !== null) {
            throw new PostingValidationException(__('One of these transactions has already been categorized.'));
        }

        if ($outLine->account_id === $inLine->account_id) {
            throw new PostingValidationException(__('A transfer must move between two different accounts.'));
        }

        if ((int) $inLine->amount_cents !== -(int) $outLine->amount_cents) {
            throw new PostingValidationException(__('The two transfer legs must be equal and opposite.'));
        }

        DB::transaction(function () use ($outLine, $inLine): void {
            $fromAccount = $outLine->account()->firstOrFail();
            $toAccount = $inLine->account()->firstOrFail();

            $transfer = $this->saveTransfer->handle([
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'transfer_no' => null,
                'transfer_date' => CarbonImmutable::parse($outLine->txn_date)->toDateString(),
                'from_amount_cents' => abs((int) $outLine->amount_cents),
                'to_amount_cents' => abs((int) $inLine->amount_cents),
                'memo' => $outLine->description ?: $inLine->description ?: null,
            ]);

            $entry = $this->poster->post($transfer);

            $fromLeg = $entry->lines()->where('account_id', $fromAccount->id)->firstOrFail();
            $toLeg = $entry->lines()->where('account_id', $toAccount->id)->firstOrFail();

            $outLine->forceFill([
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $fromLeg->id,
                'match_status' => StatementLineMatchStatus::Matched->value,
            ])->save();

            $inLine->forceFill([
                'created_journal_entry_id' => $entry->id,
                'matched_journal_line_id' => $toLeg->id,
                'match_status' => StatementLineMatchStatus::Matched->value,
            ])->save();
        });
    }
}
