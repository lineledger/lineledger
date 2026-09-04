<?php

namespace App\Livewire\Concerns;

use App\Models\BankStatementLine;
use App\Models\TaxCode;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Host side of the per-row purchase-tax picker on the import wizard and the
 * For Review feed: up to two active purchase codes per outflow (first =
 * primary), persisted on the line's suggested_tax_code_id /
 * suggested_secondary_tax_code_id so a reload (or the commit) sees them. The
 * statement amount is treated as tax-inclusive when the line is recorded.
 */
trait ManagesStatementLineTax
{
    /** @var array<int, array<int, int|string>> lineId => chosen tax code ids, first = primary, max two */
    public array $lineTax = [];

    abstract protected function statementLineForTax(int $lineId): ?BankStatementLine;

    /**
     * @return Collection<int, TaxCode>
     */
    #[Computed]
    public function purchaseTaxCodes(): Collection
    {
        return TaxCode::query()->usableForPurchases()->orderBy('code')->get(['id', 'code', 'name']);
    }

    protected function seedLineTax(BankStatementLine $line): void
    {
        $this->lineTax[$line->id] = $this->normaliseTaxIds(array_filter($line->suggestedTaxCodeIds()));
    }

    /**
     * The row's tax codes: the picker's state when touched, else what the line
     * suggests — both filtered to codes still usable for purchases.
     *
     * @return array{0: int|null, 1: int|null} primary, secondary
     */
    public function lineTaxIds(BankStatementLine $line): array
    {
        $ids = array_key_exists($line->id, $this->lineTax)
            ? $this->normaliseTaxIds((array) $this->lineTax[$line->id])
            : $this->normaliseTaxIds(array_filter($line->suggestedTaxCodeIds()));

        return [$ids[0] ?? null, $ids[1] ?? null];
    }

    /** "GST, QST" — or '' when the row carries no tax. */
    public function lineTaxLabel(BankStatementLine $line): string
    {
        $ids = array_values(array_filter($this->lineTaxIds($line)));

        if ($ids === []) {
            return '';
        }

        return $this->purchaseTaxCodes->whereIn('id', $ids)->pluck('code')->implode(', ');
    }

    public function updatedLineTax(mixed $value, int|string $key): void
    {
        $lineId = (int) explode('.', (string) $key)[0];
        $ids = $this->normaliseTaxIds((array) ($this->lineTax[$lineId] ?? []));
        $this->lineTax[$lineId] = $ids;

        $line = $this->statementLineForTax($lineId);

        if ($line === null) {
            return;
        }

        $line->forceFill([
            'suggested_tax_code_id' => $ids[0] ?? null,
            'suggested_secondary_tax_code_id' => $ids[1] ?? null,
        ])->save();

        $this->afterLineTaxChanged($line);
    }

    /** Pages refresh whatever they cache off the line here. */
    protected function afterLineTaxChanged(BankStatementLine $line): void {}

    /**
     * @param  array<int, mixed>  $raw
     * @return list<int>
     */
    private function normaliseTaxIds(array $raw): array
    {
        $usable = $this->purchaseTaxCodes->pluck('id')->map(fn ($id): int => (int) $id)->flip();
        $ids = [];

        foreach ($raw as $id) {
            $id = (int) $id;

            if ($id > 0 && $usable->has($id) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return array_slice($ids, 0, 2);
    }
}
