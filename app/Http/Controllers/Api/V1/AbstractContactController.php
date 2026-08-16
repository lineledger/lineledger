<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contacts\SaveContact;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreContactRequest;
use App\Http\Requests\Api\V1\UpdateContactRequest;
use App\Http\Resources\Api\V1\ContactResource;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Shared CRUD for contacts in a single role. Subclasses set the role flag
 * (is_customer / is_vendor / is_employee), which both filters the listing and
 * tags newly created/updated contacts.
 */
abstract class AbstractContactController extends ApiController
{
    use AppliesApiListFilters;

    /** @var 'is_customer'|'is_vendor'|'is_employee' */
    protected string $role;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Contact::query()->where($this->role, true);

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'search' => ['display_name', 'company_name', 'email'],
            'sortable' => ['display_name', 'created_at', 'id'],
            'default_sort' => ['display_name', 'asc'],
        ]);

        return ContactResource::collection($this->paginateApi($query, $request));
    }

    public function show(Contact $contact): ContactResource
    {
        abort_unless($contact->{$this->role}, 404);

        return new ContactResource($contact);
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $contact = app(SaveContact::class)->handle($request->validated(), $this->role);

        return (new ContactResource($contact))->response()->setStatusCode(201);
    }

    public function update(UpdateContactRequest $request, Contact $contact): ContactResource
    {
        abort_unless($contact->{$this->role}, 404);

        $contact = app(SaveContact::class)->handle($request->validated(), $this->role, $contact);

        return new ContactResource($contact);
    }

    public function destroy(Contact $contact): JsonResponse
    {
        abort_unless($contact->{$this->role}, 404);

        $contact->delete();

        return response()->json(null, 204);
    }
}
