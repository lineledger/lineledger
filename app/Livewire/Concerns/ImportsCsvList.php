<?php

namespace App\Livewire\Concerns;

use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\ImportResult;
use Flux\Flux;
use Illuminate\Validation\Rules\File;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;

/**
 * Shared "Download template + upload → preview → commit" CSV import behaviour
 * for the list pages (Items, Item categories, …). Pairs with the
 * <x-csv-import-modal> Blade partial.
 *
 * The host component must use {@see WithFileUploads} and expose a
 * `public Company $company`, plus implement the three small hooks below.
 */
trait ImportsCsvList
{
    /** Uploaded CSV for the import flow. */
    public mixed $importFile = null;

    /** @var ?list<array<string, mixed>> Dry-run preview rows; null until previewed. */
    public ?array $importPreviewRows = null;

    /** @var list<array{row:int, message:string}> */
    public array $importErrors = [];

    /** @var array<string, mixed> */
    public array $importSummary = [];

    /** The importer that powers this list's CSV import. */
    abstract protected function csvImporter(): CompanyCsvImporter;

    /** The Flux modal name used for this list's import dialog. */
    abstract protected function csvImportModalName(): string;

    /** Plural noun for toasts, e.g. "items", "categories". */
    abstract protected function csvImportLabelPlural(): string;

    public function openImport(): void
    {
        $this->resetImport();
        Flux::modal($this->csvImportModalName())->show();
    }

    /** Re-previewing is required whenever the chosen file changes. */
    public function updatedImportFile(): void
    {
        $this->importPreviewRows = null;
        $this->importErrors = [];
        $this->importSummary = [];
    }

    public function previewImport(): void
    {
        $this->validate($this->importRules());

        $this->applyImportResult(
            $this->csvImporter()->previewForCompany($this->importFile->getRealPath(), $this->company),
        );
    }

    public function runImport(): void
    {
        $this->validate($this->importRules());

        $importer = $this->csvImporter();

        // Re-validate the file before writing and refuse a partial import: if any
        // row is invalid we surface the errors and create nothing.
        $preview = $importer->previewForCompany($this->importFile->getRealPath(), $this->company);

        if ($preview->hasErrors()) {
            $this->applyImportResult($preview);
            Flux::toast(variant: 'danger', text: __('Fix the errors below before importing.'));

            return;
        }

        $result = $importer->commitForCompany($this->importFile->getRealPath(), $this->company);
        $this->applyImportResult($result);

        // The commit runs in one transaction: any failure rolls back every row, so
        // a result with errors means nothing was saved — don't claim success.
        if ($result->hasErrors()) {
            Flux::toast(variant: 'danger', text: __('Import failed — no :items were saved. See the errors below.', ['items' => $this->csvImportLabelPlural()]));

            return;
        }

        Flux::modal($this->csvImportModalName())->close();
        $this->resetImport();

        Flux::toast(variant: 'success', text: __(':count new :items imported.', [
            'count' => $result->summary['created'] ?? 0,
            'items' => $this->csvImportLabelPlural(),
        ]));
    }

    /**
     * How many previewed rows would actually be created. Importers only fill
     * summary['created'] on commit, so the preview count is derived from the
     * rows flagged 'create' (everything else is a skip of an existing row).
     */
    #[Computed]
    public function importCreatableCount(): int
    {
        return collect($this->importPreviewRows ?? [])
            ->where('action', 'create')
            ->count();
    }

    protected function applyImportResult(ImportResult $result): void
    {
        $this->importPreviewRows = $result->previewRows;
        $this->importErrors = $result->errors;
        $this->importSummary = $result->summary;
    }

    /**
     * @return array<string, mixed>
     */
    protected function importRules(): array
    {
        return [
            'importFile' => ['required', 'file', File::types(['csv', 'txt'])->max(2048)],
        ];
    }

    protected function resetImport(): void
    {
        $this->reset(['importFile', 'importPreviewRows', 'importErrors', 'importSummary']);
    }
}
