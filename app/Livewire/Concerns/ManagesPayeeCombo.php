<?php

namespace App\Livewire\Concerns;

use App\Actions\Contacts\SaveContact;
use App\Models\Contact;
use App\Support\Contacts\ContactLinkResolver;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Host side of the `<x-payee-combo>` picker on the cheque and expense forms:
 * search every payable contact (vendor, employee, customer, other name), pick
 * one, quick-add an Other name inline, or clear back to search.
 *
 * The host component declares the fields the picker writes through to —
 * `public Company $company`, `public ?int $payee_contact_id` and
 * `public string $payee_name` — and must NOT define its own
 * updatedPayeeContactId() or payeeOptions() (a class method silently wins over
 * the trait's). Anything to do once a payee lands (memo defaults, …) goes in an
 * afterPayeeSelected() override.
 *
 * Selecting a contact sets both payee_contact_id and payee_name: the id is the
 * link the GL legs carry, the name is what prints on the cheque and what a
 * legacy free-text draft still has. Creating a vendor / customer / employee
 * happens on that entity's own page (a `?new=` deep link in a new tab); only
 * Other names — one-off payees with no directory record — are minted here.
 */
trait ManagesPayeeCombo
{
    public string $payee_query = '';

    public bool $payee_creating = false;

    public string $new_payee_name = '';

    /**
     * Every active payable contact, filtered by the typed query. The first 50
     * appear on focus (like the bills form), so a small company sees its whole
     * list without typing.
     *
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function payeeOptions(): Collection
    {
        $query = trim($this->payee_query);

        return $this->payeeQuery()
            ->when($query !== '', fn ($q) => $q->where('display_name', 'like', '%'.$query.'%'))
            ->orderBy('display_name')
            ->limit(50)
            ->get(['id', 'display_name', 'is_customer', 'is_vendor', 'is_employee', 'is_other_name']);
    }

    #[Computed]
    public function selectedPayee(): ?Contact
    {
        return $this->payee_contact_id ? Contact::find($this->payee_contact_id) : null;
    }

    /**
     * Role badges (label + Flux colour) for the selected payee, from the resolver.
     *
     * @return list<array{label: string, color: string}>
     */
    public function selectedPayeeRoles(): array
    {
        $contact = $this->selectedPayee;

        return $contact ? app(ContactLinkResolver::class)->roleLabels($contact) : [];
    }

    /**
     * "Create … as a new vendor / customer / employee" targets, one per
     * directory page the viewer can reach. Each is the index page's `?new=`
     * deep link, which opens the create form with the typed name prefilled.
     *
     * @return list<array{label: string, url: string, dataTest: string}>
     */
    #[Computed]
    public function payeeCreateLinks(): array
    {
        $viewer = Auth::user();
        $resolver = app(ContactLinkResolver::class);
        $name = trim($this->payee_query);
        $links = [];

        // Same gate the link's destination page applies (section enabled for
        // the company AND held by the viewer), memoised across the render.
        foreach ([
            ['vendors.index', __('vendor'), 'create-vendor'],
            ['customers.index', __('customer'), 'create-customer'],
            ['employees.index', __('employee'), 'create-employee'],
        ] as [$routeName, $label, $dataTest]) {
            if (! $resolver->viewerCanReach($routeName, $this->company, $viewer)) {
                continue;
            }

            $links[] = [
                'label' => $label,
                'url' => route($routeName, ['company' => $this->company->slug, 'new' => $name]),
                'dataTest' => $dataTest,
            ];
        }

        return $links;
    }

    /**
     * Livewire hook: a directly-set payee_contact_id (the API-style path the
     * older tests use) still fills the name and runs the host's follow-ups.
     */
    public function updatedPayeeContactId(?int $value): void
    {
        if ($value && $contact = Contact::find($value)) {
            $this->applyPayee($contact);
        }
    }

    /**
     * Runs once a payee has been set. Hosts override to default the memo etc.
     */
    protected function afterPayeeSelected(Contact $contact): void {}

    public function selectPayee(int $id): void
    {
        $contact = $this->payeeQuery()->find($id);

        if ($contact === null) {
            return;
        }

        $this->payee_contact_id = (int) $contact->id;
        $this->resetPayeeComboState();
        $this->applyPayee($contact);
    }

    public function startNewOtherName(): void
    {
        $name = trim($this->payee_query);

        $this->payee_contact_id = null;
        $this->payee_name = '';
        $this->resetPayeeComboState();
        $this->new_payee_name = $name;
        $this->payee_creating = true;
    }

    /**
     * Quick-add the typed name as an Other name and select it. The Enter key
     * passes the input's live value so the debounced model cannot race the
     * click; the ✓ button relies on the model. An existing active contact of
     * any role with the same name (case-insensitively) is selected instead —
     * never a duplicate.
     */
    public function createOtherName(?string $typed = null): void
    {
        $this->new_payee_name = trim($typed ?? $this->new_payee_name);

        $this->validate(
            ['new_payee_name' => ['required', 'string', 'max:255']],
            [],
            ['new_payee_name' => __('other name')],
        );

        $name = $this->new_payee_name;

        if ($existing = $this->findPayeeByName($name)) {
            $this->selectPayee((int) $existing->id);

            return;
        }

        $contact = app(SaveContact::class)->handle(['display_name' => $name], 'is_other_name');

        $this->selectPayee((int) $contact->id);

        Flux::toast(variant: 'success', text: __('":name" added to Other names.', ['name' => $contact->display_name]));
    }

    public function clearPayee(): void
    {
        $this->payee_contact_id = null;
        $this->payee_name = '';
        $this->resetPayeeComboState();
    }

    protected function applyPayee(Contact $contact): void
    {
        $this->payee_name = (string) $contact->display_name;

        $this->afterPayeeSelected($contact);
    }

    protected function resetPayeeComboState(): void
    {
        $this->payee_query = '';
        $this->payee_creating = false;
        $this->new_payee_name = '';
        $this->resetErrorBag(['payee_contact_id', 'payee_name', 'new_payee_name']);

        unset($this->selectedPayee, $this->payeeOptions, $this->payeeCreateLinks);
    }

    /**
     * Active contacts in any payable role — the same population the search
     * shows, so "already exists" and "selectable" can never disagree.
     *
     * @return Builder<Contact>
     */
    protected function payeeQuery()
    {
        return Contact::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('is_vendor', true)
                ->orWhere('is_employee', true)
                ->orWhere('is_customer', true)
                ->orWhere('is_other_name', true));
    }

    /**
     * Case-insensitive exact-name lookup among payable contacts. The SQL
     * predicate only narrows the candidates (`=` is case-insensitive on
     * MySQL's collation, `like` on SQLite); PHP decides equality so both
     * databases agree.
     */
    protected function findPayeeByName(string $name): ?Contact
    {
        $needle = mb_strtolower($name);

        return $this->payeeQuery()
            ->where(fn ($q) => $q->where('display_name', $name)->orWhere('display_name', 'like', $name))
            ->get(['id', 'display_name'])
            ->first(fn (Contact $c) => mb_strtolower((string) $c->display_name) === $needle);
    }
}
