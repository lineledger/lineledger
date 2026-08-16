<?php

namespace App\Services\Banking\Import\DTO;

/**
 * How a tabular (CSV / Excel) statement's columns map to a transaction. Columns are
 * referenced by their normalized header label. Two amount shapes are supported:
 *
 *  - 'single':       one signed amount column ({@see $amountColumn}). By convention a
 *                    positive value is money INTO the account; set {@see $flipSign}
 *                    when the bank's column is positive-for-withdrawals.
 *  - 'debit_credit': separate money-out ({@see $debitColumn}) and money-in
 *                    ({@see $creditColumn}) columns. amount = credit - debit.
 */
final readonly class ColumnMapping
{
    /**
     * @param  list<string>  $descriptionColumns  concatenated, in order, into the description
     */
    public function __construct(
        public string $amountMode,
        public ?string $dateColumn,
        public array $descriptionColumns = [],
        public ?string $amountColumn = null,
        public ?string $debitColumn = null,
        public ?string $creditColumn = null,
        public ?string $balanceColumn = null,
        public ?string $checkNumberColumn = null,
        public string $dateFormat = 'Y-m-d',
        public string $decimalSeparator = '.',
        public bool $flipSign = false,
    ) {}

    public function isComplete(): bool
    {
        if ($this->dateColumn === null) {
            return false;
        }

        return $this->amountMode === 'debit_credit'
            ? ($this->debitColumn !== null || $this->creditColumn !== null)
            : $this->amountColumn !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amountMode' => $this->amountMode,
            'dateColumn' => $this->dateColumn,
            'descriptionColumns' => $this->descriptionColumns,
            'amountColumn' => $this->amountColumn,
            'debitColumn' => $this->debitColumn,
            'creditColumn' => $this->creditColumn,
            'balanceColumn' => $this->balanceColumn,
            'checkNumberColumn' => $this->checkNumberColumn,
            'dateFormat' => $this->dateFormat,
            'decimalSeparator' => $this->decimalSeparator,
            'flipSign' => $this->flipSign,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            amountMode: $data['amountMode'] ?? 'single',
            dateColumn: $data['dateColumn'] ?? null,
            descriptionColumns: array_values(array_filter((array) ($data['descriptionColumns'] ?? []))),
            amountColumn: $data['amountColumn'] ?? null,
            debitColumn: $data['debitColumn'] ?? null,
            creditColumn: $data['creditColumn'] ?? null,
            balanceColumn: $data['balanceColumn'] ?? null,
            checkNumberColumn: $data['checkNumberColumn'] ?? null,
            dateFormat: $data['dateFormat'] ?? 'Y-m-d',
            decimalSeparator: $data['decimalSeparator'] ?? '.',
            flipSign: (bool) ($data['flipSign'] ?? false),
        );
    }
}
