<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounting\SaveJournalEntry;
use App\Exceptions\Posting\LinkedJournalEntryException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreJournalEntryRequest;
use App\Http\Requests\Api\V1\UpdateJournalEntryRequest;
use App\Http\Resources\Api\V1\JournalEntryResource;
use App\Models\JournalEntry;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalEntryController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected JournalPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = JournalEntry::query()->with('lines');

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'date_column' => 'entry_date',
            'search' => ['entry_no', 'memo'],
            'sortable' => ['entry_date', 'entry_no', 'id'],
            'default_sort' => ['entry_date', 'desc'],
        ]);

        return JournalEntryResource::collection($this->paginateApi($query, $request));
    }

    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        return new JournalEntryResource($journalEntry->load('lines'));
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $entry = $this->posting(function () use ($data, $request): JournalEntry {
            $entry = app(SaveJournalEntry::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($entry);
            }

            return $entry->fresh(['lines']);
        });

        return (new JournalEntryResource($entry))->response()->setStatusCode(201);
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JournalEntryResource
    {
        if ($journalEntry->isVoided()) {
            $this->conflict('A voided journal entry cannot be edited.');
        }

        if (! $journalEntry->isManualEntry()) {
            $this->conflict(LinkedJournalEntryException::for($journalEntry)->getMessage());
        }

        $data = $request->validated();
        $wasPosted = $journalEntry->isPosted();

        $entry = $this->posting(function () use ($data, $journalEntry, $wasPosted): JournalEntry {
            if ($wasPosted) {
                return $this->updatePosted($data, $journalEntry);
            }

            return app(SaveJournalEntry::class)->handle($data, $journalEntry)->fresh(['lines']);
        });

        return new JournalEntryResource($entry);
    }

    /**
     * Post a draft entry. Already-posted entries have no repost path.
     */
    public function post(JournalEntry $journalEntry): JournalEntryResource
    {
        if ($journalEntry->isVoided()) {
            $this->conflict('A voided journal entry cannot be posted.');
        }

        if ($journalEntry->isPosted()) {
            $this->conflict('This entry is already posted.');
        }

        $entry = $this->posting(function () use ($journalEntry): JournalEntry {
            $this->poster->post($journalEntry);

            return $journalEntry->fresh(['lines']);
        });

        return new JournalEntryResource($entry);
    }

    /**
     * DELETE: `?permanent=true` deletes the entry as if never entered (a manual,
     * bank-feed or imported entry; a voided one with its reversal). Without
     * it a draft is deleted and a posted entry voided (deprecated: POST
     * …/void).
     *
     * Plain DELETE acts on manual entries only: an entry another document
     * created (an invoice's, a filed return's adjustment) is voided through
     * that document, and the reversal a void wrote belongs to that void (422,
     * as for a linked draft).
     */
    public function destroy(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        if ($request->boolean('permanent')) {
            return $this->deletePermanently($request, $journalEntry);
        }

        if ($journalEntry->isVoided()) {
            $this->conflict('Journal entry is already voided.');
        }

        if (! $journalEntry->isManualEntry()) {
            throw LinkedJournalEntryException::for($journalEntry);
        }

        if ($journalEntry->isPosted()) {
            $this->posting(fn () => $this->poster->void($journalEntry));

            return (new JournalEntryResource($journalEntry->fresh(['lines'])))->response()->setStatusCode(200)->withHeaders([
                'Deprecation' => 'true',
                'Link' => '<'.$request->url().'/void>; rel="successor-version"',
            ]);
        }

        return $this->deletePermanently($request, $journalEntry);
    }

    /**
     * POST …/void: void a posted manual entry with a reversing entry.
     */
    public function void(JournalEntry $journalEntry): JsonResponse
    {
        if ($journalEntry->isVoided()) {
            $this->conflict('Journal entry is already voided.');
        }

        if (! $journalEntry->isManualEntry()) {
            throw LinkedJournalEntryException::for($journalEntry);
        }

        if (! $journalEntry->isPosted()) {
            $this->conflict('A draft has nothing to reverse; delete it instead.');
        }

        $this->posting(fn () => $this->poster->void($journalEntry));

        return (new JournalEntryResource($journalEntry->fresh(['lines'])))->response()->setStatusCode(200);
    }

    /**
     * Overwrite a posted entry in place (mirrors the Livewire saveChanges path).
     * The entry stays posted, so it must remain balanced, out of any locked
     * period (old and new date) and free of reconciled lines; affected account
     * balances are recomputed across old and new lines.
     *
     * @param  array<string, mixed>  $data
     */
    protected function updatePosted(array $data, JournalEntry $journalEntry): JournalEntry
    {
        // Balance on the home amounts the lines will actually carry: a line on
        // a foreign-currency account may send only its foreign amount.
        $lines = app(SaveJournalEntry::class)->resolveLines($data['lines'], $data['entry_date']);
        $debits = array_sum(array_column($lines, 'debit_cents'));
        $credits = array_sum(array_column($lines, 'credit_cents'));

        if ($debits !== $credits || $debits === 0) {
            throw UnbalancedJournalException::from($debits, $credits);
        }

        $this->poster->ensurePostedEntryEditable($journalEntry, CarbonImmutable::parse($data['entry_date']));

        $affectedAccountIds = $journalEntry->lines->pluck('account_id')->all();

        $journalEntry = app(SaveJournalEntry::class)->handle($data, $journalEntry);

        $affectedAccountIds = array_merge($affectedAccountIds, $journalEntry->lines->pluck('account_id')->all());
        $this->poster->recomputeAccounts($affectedAccountIds);

        return $journalEntry->fresh(['lines']);
    }
}
