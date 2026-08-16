<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Updates the set of marked (cleared-candidate) lines on an in-progress
 * reconciliation. Provide the full replacement set in `marked_line_ids`.
 * Every id must be a journal line on the reconciliation's account.
 */
class UpdateBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app()->bound('current_api_key');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // journal_lines has no company_id column; tenancy is enforced by
        // requiring each line to belong to the bound reconciliation's account
        // (the reconciliation itself is already tenant-scoped via route binding).
        $accountId = $this->route('bankReconciliation')?->account_id;

        return [
            'marked_line_ids' => ['present', 'array', 'max:100000'],
            'marked_line_ids.*' => [
                'integer',
                Rule::exists('journal_lines', 'id')->where('account_id', $accountId),
            ],
        ];
    }
}
