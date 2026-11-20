<?php

namespace App\Console\Commands;

use App\Actions\Lists\DeleteListRecord;
use App\Console\Concerns\ResolvesCompanyArgument;
use App\Enums\DeletableDocumentType;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Services\Deletion\DeletableDocuments;
use App\Services\Deletion\TransactionDeleter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * Before 1.5, deleting a draft (or a list record through the API) only hid it
 * with a soft delete: the row, and for a sales receipt its lines, stayed in
 * the database, its number blocked nothing and nothing recorded the delete.
 * This finishes those deletes the way Delete works now (issue #241):
 *
 * - A soft-deleted transaction that never posted goes through
 *   TransactionDeleter: it leaves a Draft (earlier release) record on the
 *   Voided/Deleted Transactions report, keeps its number from being handed
 *   out again, and writes an audit row. One that holds a journal entry, or a
 *   Stripe payment (the webhook dedupe keys on it), is listed and left alone,
 *   as is one something still links to.
 * - A soft-deleted list record nothing uses is deleted for good, with an
 *   audit row. One still in use (the contact API used to hide contacts with
 *   history) is brought back as inactive, so the documents naming it open.
 *
 * Idempotent; part of `app:upgrade`.
 */
class PurgeSoftDeletedCommand extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'deletions:purge-soft-deleted
        {company? : Company ID or slug; all companies when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Finish deletes that releases before 1.5 only hid: purge soft-deleted drafts and unused list records, restore used ones as inactive.';

    /** @var list<class-string<Model>> */
    private const LIST_MODELS = [Account::class, AssetCategory::class, Contact::class, Item::class];

    public function __construct(
        private readonly TransactionDeleter $deleter,
        private readonly DeleteListRecord $lists,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $arg = $this->argument('company');
        $dryRun = (bool) $this->option('dry-run');

        $companies = $arg !== null
            ? $this->whereCompanyArgument(Company::query()->withoutGlobalScopes(), $arg)->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            if ($arg === null) {
                $this->info('No companies yet; nothing to purge.');

                return self::SUCCESS;
            }

            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            app()->instance('current_company', $company);

            try {
                $this->purgeCompany($company, $dryRun);
            } finally {
                app()->forgetInstance('current_company');
            }
        }

        return self::SUCCESS;
    }

    private function purgeCompany(Company $company, bool $dryRun): void
    {
        $counts = ['purged' => 0, 'kept' => 0, 'deleted' => 0, 'restored' => 0];

        foreach (DeletableDocumentType::cases() as $type) {
            $class = $type->modelClass();

            if (! $this->softDeletes($class)) {
                continue;
            }

            $table = (new $class)->getTable();
            $entryColumn = $type === DeletableDocumentType::Grant ? 'award_journal_entry_id' : 'journal_entry_id';
            $hasEntry = Schema::hasColumn($table, $entryColumn);
            $hasStripe = Schema::hasColumn($table, 'stripe_payment_intent_id');

            $rows = $class::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereNotNull('deleted_at')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $label = sprintf('%s %s (id %d)', $type->label(), (string) ($row->getAttribute($type->numberColumn()) ?? '—'), $row->getKey());

                if ($hasEntry && $row->getAttribute($entryColumn) !== null) {
                    $counts['kept']++;
                    $this->line("  kept {$label}: it holds a journal entry; void or delete it from a restored copy by hand.");

                    continue;
                }

                if ($hasStripe && $row->getAttribute('stripe_payment_intent_id') !== null) {
                    $counts['kept']++;
                    $this->line("  kept {$label}: a Stripe payment, whose webhook dedupe keys on it.");

                    continue;
                }

                if (! DeletableDocuments::supports($row)) {
                    continue;
                }

                if ($dryRun) {
                    $counts['purged']++;

                    continue;
                }

                $hiddenOn = CarbonImmutable::parse((string) $row->getAttribute('deleted_at'))->toDateString();

                try {
                    $this->deleter->delete($row, sprintf('Deleted on %s by an earlier release, which only hid it.', $hiddenOn), null, 'upgrade');
                    $counts['purged']++;
                } catch (DeletionBlockedException $e) {
                    $counts['kept']++;
                    $this->line("  kept {$label}: ".($e->blockers[0]->message ?? $e->getMessage()));
                }
            }
        }

        foreach (self::LIST_MODELS as $class) {
            if (! $this->softDeletes($class)) {
                continue;
            }

            $rows = $class::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->whereNotNull('deleted_at')
                ->orderBy('id')
                ->get();

            foreach ($rows as $row) {
                $unused = $this->lists->blockers($row, requireInactive: false) === [];

                if ($unused) {
                    $counts['deleted']++;

                    if (! $dryRun) {
                        $this->lists->handle($row, null, requireInactive: false, legacy: true);
                    }

                    continue;
                }

                $counts['restored']++;

                if (! $dryRun) {
                    $row->forceFill(['is_active' => false]);
                    $row->restore();
                }
            }
        }

        $this->line(sprintf(
            'Company %s — %s %d soft-deleted transaction(s), kept %d; %s %d unused list record(s), %s %d still in use as inactive.',
            $company->slug,
            $dryRun ? 'would purge' : 'purged',
            $counts['purged'],
            $counts['kept'],
            $dryRun ? 'would delete' : 'deleted',
            $counts['deleted'],
            $dryRun ? 'would restore' : 'restored',
            $counts['restored'],
        ));
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function softDeletes(string $class): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($class), true);
    }
}
