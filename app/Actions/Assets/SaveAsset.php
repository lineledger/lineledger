<?php

namespace App\Actions\Assets;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\Posting\DocumentNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a fixed-asset record. Shared by the Livewire form and the
 * API. Pure record-keeping — there is no depreciation poster, so this never
 * touches the GL.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   asset_no:    ?string  (null → auto-generated)
 *   name:        string   description: ?string
 *   asset_category_id: ?int
 *   asset_account_id:  int  (required)
 *   accumulated_depreciation_account_id: ?int
 *   depreciation_expense_account_id:     ?int
 *   serial_number: ?string  location: ?string
 *   acquired_date: string   in_service_date: ?string
 *   cost_cents: int  salvage_value_cents: ?int  useful_life_months: ?int
 *   auto_depreciate: ?bool (default false; opts into generated monthly drafts)
 *   status: ?string (AssetStatus, default in-service)
 *   disposed_at: ?string  disposal_notes: ?string
 *   notes: ?string  is_active: ?bool
 *   source_type: ?string  source_id: ?int
 */
final class SaveAsset
{
    public function __construct(protected DocumentNumberGenerator $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Asset $asset = null): Asset
    {
        return DB::transaction(function () use ($data, $asset): Asset {
            $company = app('current_company');

            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'asset_category_id' => $data['asset_category_id'] ?? null,
                'asset_account_id' => $data['asset_account_id'],
                'accumulated_depreciation_account_id' => $data['accumulated_depreciation_account_id'] ?? null,
                'depreciation_expense_account_id' => $data['depreciation_expense_account_id'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'location' => $data['location'] ?? null,
                'acquired_date' => CarbonImmutable::parse($data['acquired_date'])->toDateString(),
                'in_service_date' => ! empty($data['in_service_date'])
                    ? CarbonImmutable::parse($data['in_service_date'])->toDateString()
                    : null,
                'cost_cents' => (int) $data['cost_cents'],
                'salvage_value_cents' => (int) ($data['salvage_value_cents'] ?? 0),
                'useful_life_months' => $data['useful_life_months'] ?? null,
                'status' => $data['status'] ?? AssetStatus::InService->value,
                'disposed_at' => ! empty($data['disposed_at'])
                    ? CarbonImmutable::parse($data['disposed_at'])->toDateString()
                    : null,
                'disposal_notes' => $data['disposal_notes'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
            ];

            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = (bool) $data['is_active'];
            }

            if (array_key_exists('auto_depreciate', $data)) {
                $attributes['auto_depreciate'] = (bool) $data['auto_depreciate'];
            }

            if ($asset && $asset->exists) {
                $asset->update($attributes);

                return $asset;
            }

            return Asset::create($attributes + [
                'asset_no' => $data['asset_no']
                    ?? $this->numbers->next($company, Asset::class, 'asset_no', 'AST'),
                'is_active' => $data['is_active'] ?? true,
                'auto_depreciate' => $data['auto_depreciate'] ?? false,
            ]);
        });
    }
}
