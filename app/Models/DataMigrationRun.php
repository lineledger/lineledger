<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'company_id', 'status', 'mode', 'conversion_date', 'history_start_date', 'current_step', 'step_results',
    'open_invoices_use_original_date', 'open_bills_use_original_date',
    'auto_create_accounts', 'link_contact_names', 'reconstruct_documents',
    'started_at', 'completed_at',
])]
class DataMigrationRun extends Model
{
    use BelongsToCompany;

    /**
     * The opening-balance step map. Kept as a constant for backwards compatibility;
     * prefer steps() / stepKey(), which are mode-aware.
     */
    public const STEPS = [
        1 => 'setup',
        2 => 'chart_of_accounts',
        3 => 'confirm_control_accounts',
        4 => 'customers',
        5 => 'vendors',
        6 => 'items',
        7 => 'open_invoices',
        8 => 'open_bills',
        9 => 'inventory_opening_balance',
        10 => 'fixed_assets',
        11 => 'trial_balance',
        12 => 'review',
    ];

    /**
     * The ordered step map for this run's mode (step number => step key).
     *
     * @return array<int, string>
     */
    public function steps(): array
    {
        return $this->modeEnum()->steps();
    }

    public function modeEnum(): DataMigrationMode
    {
        return $this->mode ?? DataMigrationMode::OpeningBalance;
    }

    public function lastStep(): int
    {
        return array_key_last($this->steps());
    }

    public function stepKey(): string
    {
        return $this->steps()[(int) $this->current_step] ?? 'unknown';
    }

    /**
     * The step number of the first step (in the current mode map) that is not
     * yet complete — i.e. where the user should resume. Completion is keyed by
     * step KEY, so this is stable across step-map renumbering: a run started
     * before a step was inserted resumes at the correct place rather than at a
     * now-shifted number. Returns the last step if everything is complete.
     */
    public function resolveCurrentStepByKey(): int
    {
        foreach ($this->steps() as $num => $key) {
            if (! $this->isStepComplete($key)) {
                return $num;
            }
        }

        return $this->lastStep();
    }

    public function isStepComplete(string $stepKey): bool
    {
        $results = $this->step_results ?? [];

        return isset($results[$stepKey]['committed_at']);
    }

    /**
     * Persist the outcome of a step into step_results.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordStepResult(string $stepKey, array $payload): void
    {
        $results = $this->step_results ?? [];
        $results[$stepKey] = array_merge($payload, ['committed_at' => now()->toIso8601String()]);
        $this->forceFill(['step_results' => $results])->save();
    }

    /**
     * step_results aggregates import diagnostics, including messages derived from
     * source files that may contain malformed (non-UTF-8) bytes. Encode with
     * JSON_INVALID_UTF8_SUBSTITUTE so a stray bad byte can never throw a
     * JsonEncodingException and abort a long-running import job mid-stream.
     */
    protected function stepResults(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : (json_decode($value, true) ?: []),
            set: fn (?array $value): string => json_encode($value ?? [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DataMigrationStatus::class,
            'mode' => DataMigrationMode::class,
            'conversion_date' => 'date:Y-m-d',
            'history_start_date' => 'date:Y-m-d',
            'current_step' => 'integer',
            'open_invoices_use_original_date' => 'boolean',
            'open_bills_use_original_date' => 'boolean',
            'auto_create_accounts' => 'boolean',
            'link_contact_names' => 'boolean',
            'reconstruct_documents' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
