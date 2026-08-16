<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounting\SaveAccount;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\UpdateAccountRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends ApiController
{
    use AppliesApiListFilters;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Account::query();

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'search' => ['code', 'name'],
            'sortable' => ['code', 'name', 'balance_cents', 'id'],
            'default_sort' => ['code', 'asc'],
        ]);

        return AccountResource::collection($this->paginateApi($query, $request));
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = app(SaveAccount::class)->handle($request->validated());

        return (new AccountResource($account))->response()->setStatusCode(201);
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $data = $request->validated();

        // System accounts keep their type fixed; reject subtype changes. Code is editable.
        if ($account->is_system && $data['subtype'] !== $account->subtype?->value) {
            abort(422, 'A system account\'s type cannot be changed.');
        }

        // Once an account carries journal lines (draft or posted), its type is
        // frozen — retyping would rewrite every report the lines appear on.
        if ($data['subtype'] !== $account->subtype?->value && $account->journalLines()->exists()) {
            abort(422, 'An account with transactions cannot change its type.');
        }

        $account = app(SaveAccount::class)->handle($data, $account);

        return new AccountResource($account);
    }

    public function destroy(Account $account): JsonResponse
    {
        if ($account->journalLines()->exists()) {
            $this->conflict('Account has transactions; deactivate instead.');
        }

        $account->delete();

        return response()->json(null, 204);
    }
}
