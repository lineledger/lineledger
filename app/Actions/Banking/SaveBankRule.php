<?php

namespace App\Actions\Banking;

use App\Models\BankRule;

/**
 * Creates or updates a bank rule (statement-line auto-categorization). Shared by
 * the Livewire settings page and the API.
 *
 * Expected $data shape:
 *   name:               string
 *   match_type:         string  (contains|starts_with|equals|regex|merchant_key)
 *   match_pattern:      string
 *   action_account_id:  int
 *   action_contact_id:  ?int    (payee to record matching outflows against)
 *   priority:           ?int
 *   is_active:          ?bool
 */
final class SaveBankRule
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?BankRule $rule = null): BankRule
    {
        $attributes = [
            'name' => $data['name'],
            'match_type' => $data['match_type'],
            'match_pattern' => $data['match_pattern'],
            'action_account_id' => $data['action_account_id'],
            'priority' => (int) ($data['priority'] ?? 0),
        ];

        if (array_key_exists('action_contact_id', $data)) {
            $attributes['action_contact_id'] = $data['action_contact_id'] !== null && $data['action_contact_id'] !== ''
                ? (int) $data['action_contact_id']
                : null;
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        if ($rule && $rule->exists) {
            $rule->update($attributes);

            return $rule;
        }

        return BankRule::create($attributes + ['is_active' => $data['is_active'] ?? true]);
    }
}
