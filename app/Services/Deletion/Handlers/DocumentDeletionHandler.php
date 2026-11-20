<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\SlipType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PayrollSlipFiling;
use App\Services\Deletion\DeletionBlocker;
use App\Services\Deletion\DeletionHandler;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\LineSnapshot;
use App\Services\Deletion\SnapshotValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The common shape of a document's delete: a header row and a line table.
 * A subclass names its line table and adds what is particular to it (who may
 * point at it, what else to reset); the summary, snapshot, tax codes and the
 * removal of the lines and the row are shared.
 */
abstract class DocumentDeletionHandler implements DeletionHandler
{
    /**
     * The line table and its foreign key to the document, or null for a
     * document without lines.
     *
     * @return array{0: string, 1: string}|null
     */
    abstract protected function lines(): ?array;

    /** The document's contact column, if it has one. */
    protected function contactColumn(): ?string
    {
        return 'contact_id';
    }

    protected function totalColumn(): string
    {
        return 'total_cents';
    }

    public function blockers(Model $document): array
    {
        return [];
    }

    public function warnings(Model $document): array
    {
        return [];
    }

    public function taxCodeIds(Model $document): array
    {
        $this->own($document);
        $lines = $this->lines();

        return $lines === null ? [] : LineSnapshot::taxCodeIds($lines[0], $lines[1], (int) $document->getKey());
    }

    public function summary(Model $document): array
    {
        $this->own($document);
        $type = $this->type();

        return [
            'document_number' => $this->stringOrNull($document->getAttribute($type->numberColumn())),
            'document_date' => SnapshotValue::date($document->getAttribute($type->dateColumn())),
            'contact_name' => $this->contactName($document),
            'total_cents' => (int) $document->getAttribute($this->totalColumn()),
            'currency_code' => $this->stringOrNull($document->getAttribute('currency_code')),
        ];
    }

    public function snapshot(Model $document): array
    {
        $this->own($document);
        $type = $this->type();
        $lines = $this->lines();
        $status = $document->getAttribute('status');

        return [
            'number' => $this->stringOrNull($document->getAttribute($type->numberColumn())),
            'date' => SnapshotValue::date($document->getAttribute($type->dateColumn())),
            'status' => $status instanceof \BackedEnum ? $status->value : $this->stringOrNull($status),
            'contact_name' => $this->contactName($document),
            'memo' => $this->stringOrNull($document->getAttribute('memo')),
            'currency_code' => $this->stringOrNull($document->getAttribute('currency_code')),
            'total_cents' => (int) $document->getAttribute($this->totalColumn()),
            'posted_at' => SnapshotValue::stamp($document->getAttribute('posted_at')),
            'voided_at' => SnapshotValue::stamp($document->getAttribute('voided_at')),
            'lines' => $lines === null ? [] : LineSnapshot::fromTable($lines[0], $lines[1], (int) $document->getKey()),
            ...$this->extraSnapshot($document),
        ];
    }

    public function purge(Model $document, DeletionWork $work): void
    {
        $this->own($document);

        $contact = $this->contactColumn();

        if ($contact !== null && $document->getAttribute($contact) !== null) {
            $work->contact((int) $document->getAttribute($contact));
        }

        $this->beforePurge($document, $work);

        $lines = $this->lines();

        if ($lines !== null) {
            DB::table($lines[0])->where($lines[1], $document->getKey())->delete();
        }

        // forceDelete() is a plain delete on a model without SoftDeletes.
        $document->forceDelete();
    }

    public function afterPurge(DeletionWork $work, int $companyId): void {}

    /**
     * What else to reset or remove before the lines and the row go.
     */
    protected function beforePurge(Model $document, DeletionWork $work): void {}

    /**
     * Anything else the snapshot should keep.
     *
     * @return array<string, mixed>
     */
    protected function extraSnapshot(Model $document): array
    {
        return [];
    }

    /**
     * A blocker naming another record, with a link when it can still be
     * opened (a soft-deleted one can't).
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function blocker(Model $document, string $message, string $route, array $parameters, mixed $deletedAt = null): DeletionBlocker
    {
        $slug = (string) Company::query()->whereKey($document->getAttribute('company_id'))->value('slug');

        return new DeletionBlocker($message, $deletedAt === null ? route($route, ['company' => $slug, ...$parameters]) : null);
    }

    /**
     * The year-end slips for $year are finalized: a payment that feeds them
     * (T4A for vendor payments) can't leave the books until the year is
     * unfinalized.
     *
     * @return list<DeletionBlocker>
     */
    protected function finalizedSlipBlocker(Model $document, SlipType $slip, mixed $date): array
    {
        $year = SnapshotValue::date($date);

        if ($year === null) {
            return [];
        }

        $finalized = PayrollSlipFiling::withoutGlobalScopes()
            ->where('company_id', $document->getAttribute('company_id'))
            ->where('slip_type', $slip->value)
            ->where('year', CarbonImmutable::parse($year)->year)
            ->exists();

        return $finalized
            ? [new DeletionBlocker(__('The :year :slip slips are finalized, and this payment is part of them. Unfinalize the year first.', ['year' => CarbonImmutable::parse($year)->year, 'slip' => strtoupper($slip->value)]))]
            : [];
    }

    protected function contactName(Model $document): ?string
    {
        $column = $this->contactColumn();
        $id = $column !== null ? $document->getAttribute($column) : null;

        $name = $id !== null ? Contact::withoutGlobalScopes()->whereKey($id)->value('display_name') : null;

        return $this->stringOrNull($name ?? $document->getAttribute('payee_name'));
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    protected function own(Model $document): Model
    {
        $class = $this->type()->modelClass();

        if (! $document instanceof $class) {
            throw new InvalidArgumentException('Expected a '.class_basename($class).', got '.$document::class.'.');
        }

        return $document;
    }
}
