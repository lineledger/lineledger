<?php

namespace App\Jobs\Inbox;

use App\Enums\InboxItemStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InboxItem;
use App\Providers\InboxServiceProvider;
use App\Services\Classification\CategorySuggester;
use App\Services\Classification\Contracts\TransactionClassifier;
use App\Services\Inbox\OCR\Contracts\ReceiptIntelligence;
use App\Services\Inbox\OCR\ReceiptTaxMapper;
use App\Support\Storage\TemporaryLocalFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs OCR over a staged inbox document off the request cycle (rendering a PDF
 * or image through Anthropic is slow). Binds `current_company` so tenant scoping
 * behaves exactly as in a request. The inbox UI polls the item's status and
 * surfaces it for review when it reaches NeedsReview (or Failed).
 *
 * OCR is doubly opt-in: the operator switch + key select the real service in
 * {@see InboxServiceProvider}, and this job additionally checks
 * the per-company `inbox.ocr_enabled` toggle before sending anything out. When
 * either gate is off the document still lands in NeedsReview for manual entry.
 */
class ProcessInboxItem implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $inboxItemId) {}

    public function handle(ReceiptIntelligence $intelligence): void
    {
        $item = InboxItem::withoutGlobalScopes()->findOrFail($this->inboxItemId);
        $company = Company::query()->findOrFail($item->company_id);

        $previous = app()->bound('current_company') ? app('current_company') : null;
        app()->instance('current_company', $company);

        try {
            $item->forceFill(['status' => InboxItemStatus::Processing->value])->save();

            $extracted = $this->runOcr($intelligence, $company, $item);

            if ($extracted !== null) {
                $extracted = app(ReceiptTaxMapper::class)->map($extracted);
                $item->extracted = $extracted;
                $this->applySuggestions($item, $extracted);
                $this->resolveCategory($company, $item, $extracted);
            }

            $item->status = InboxItemStatus::NeedsReview;
            $item->suggested_document_type ??= 'bill';
            $item->ocr_error = null;
            $item->save();
        } finally {
            if ($previous !== null) {
                app()->instance('current_company', $previous);
            } else {
                app()->forgetInstance('current_company');
            }
        }
    }

    /**
     * Run OCR when both gates are open and a file exists; on a service-level
     * failure record the error but still leave the item reviewable. Returns the
     * extracted payload, or null when nothing was extracted.
     *
     * @return array<string, mixed>|null
     */
    private function runOcr(ReceiptIntelligence $intelligence, Company $company, InboxItem $item): ?array
    {
        if (! $intelligence->isEnabled() || ! $this->companyOcrEnabled($company)) {
            return null;
        }

        $attachment = $item->attachment;

        if ($attachment === null) {
            return null;
        }

        // The OCR client reads the receipt off a filesystem path, so on object
        // storage the blob is streamed to scratch space for the call's duration.
        $extracted = TemporaryLocalFile::with(
            $attachment->disk,
            $attachment->path,
            fn (string $absolutePath) => $intelligence->extract(
                $absolutePath,
                (string) ($item->mime ?? $attachment->mime_type),
            ),
        );

        if ($extracted === null && $intelligence->lastError() !== null) {
            $item->ocr_error = $intelligence->lastError();
        }

        return $extracted;
    }

    /**
     * Per-company opt-in, mirroring the insights narration toggle. The operator
     * gate already lives in the provider binding; this is the tenant gate.
     */
    private function companyOcrEnabled(Company $company): bool
    {
        return (bool) data_get($company->settings, 'inbox.ocr_enabled', false);
    }

    /**
     * Best-effort vendor match + default document type from the OCR output.
     *
     * @param  array<string, mixed>  $extracted
     */
    private function applySuggestions(InboxItem $item, array $extracted): void
    {
        $vendor = isset($extracted['vendor']) ? trim((string) $extracted['vendor']) : '';

        if ($vendor !== '') {
            $contact = Contact::query()
                ->where(function ($q) use ($vendor) {
                    $q->where('display_name', 'like', '%'.$vendor.'%')
                        ->orWhere('company_name', 'like', '%'.$vendor.'%');
                })
                ->orderBy('id')
                ->first();

            if ($contact !== null) {
                $item->suggested_contact_id = $contact->id;
            }
        }

        $item->suggested_document_type = 'bill';
    }

    /**
     * Pre-resolve a category for the review screen: deterministic history first
     * (the matched vendor's prior bills/expenses), then — for an unseen vendor,
     * behind the same gate that ran OCR — a single AI guess. The result is stashed
     * in the item's `extracted` JSON so the review page is fast and no AI call
     * happens on the request cycle. OCR-extracted tax is left untouched.
     *
     * @param  array<string, mixed>  $extracted
     */
    private function resolveCategory(Company $company, InboxItem $item, array $extracted): void
    {
        $vendor = isset($extracted['vendor']) ? trim((string) $extracted['vendor']) : '';

        $suggestion = app(CategorySuggester::class)->suggest(
            $company->id,
            $item->suggested_contact_id,
            $vendor !== '' ? $vendor : null,
        );

        if ($suggestion !== null) {
            $extracted['suggested_account_id'] = $suggestion->accountId;
            $extracted['suggested_account_reason'] = $suggestion->reason;
            $item->extracted = $extracted;

            return;
        }

        // AI fallback for a vendor with no history (same gate that ran OCR).
        $classifier = app(TransactionClassifier::class);

        if ($vendor === '' || ! $classifier->isEnabled() || ! $this->companyOcrEnabled($company)) {
            return;
        }

        $accounts = $this->selectableAccounts($company->id);

        if ($accounts === []) {
            return;
        }

        $code = $classifier->classify([$vendor], array_map(
            fn (array $a): array => ['code' => $a['code'], 'name' => $a['name']],
            $accounts,
        ))[$vendor] ?? null;

        foreach ($accounts as $account) {
            if ($account['code'] === $code) {
                $extracted['suggested_account_id'] = $account['id'];
                $extracted['suggested_account_reason'] = __('Suggested by AI — please confirm.');
                $item->extracted = $extracted;

                return;
            }
        }
    }

    /**
     * The company's selectable, active line-item accounts.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function selectableAccounts(int $companyId): array
    {
        return Account::query()
            ->where('company_id', $companyId)
            ->selectableForItemAccount()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a): array => ['id' => (int) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name])
            ->all();
    }

    public function failed(Throwable $e): void
    {
        InboxItem::withoutGlobalScopes()
            ->whereKey($this->inboxItemId)
            ->update([
                'status' => InboxItemStatus::Failed->value,
                'ocr_error' => $e->getMessage(),
            ]);
    }
}
