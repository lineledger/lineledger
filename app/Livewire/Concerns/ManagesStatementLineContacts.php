<?php

namespace App\Livewire\Concerns;

use App\Actions\Contacts\SaveContact;
use App\Models\BankStatementLine;
use App\Models\Contact;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Host side of the per-row `<x-statement-contact-combo>` picker used by the
 * import wizard and the For Review feed: search, pick, inline-create and clear
 * a payee for one statement line, persisting the choice on the line's
 * `suggested_contact_id` so a reload (or the commit) sees it.
 *
 * The page supplies how to find a line and what to do once its payee changes
 * (pre-fill the category from the vendor's default, refresh bill offers…).
 */
trait ManagesStatementLineContacts
{
    /** @var array<int, array{id: ?int, query: string, creating: bool, new_name: string}> keyed by line id */
    public array $lineContact = [];

    /** @var array<int, string> per-row helper text (vendor default pre-filled / will be remembered) */
    public array $lineHint = [];

    abstract protected function statementLineForContact(int $lineId): ?BankStatementLine;

    abstract protected function afterLineContactChanged(BankStatementLine $line, ?Contact $contact): void;

    protected function seedLineContact(BankStatementLine $line): void
    {
        $this->lineContact[$line->id] = [
            'id' => $line->suggested_contact_id !== null ? (int) $line->suggested_contact_id : null,
            'query' => '',
            'creating' => false,
            'new_name' => '',
        ];
    }

    /**
     * @return array{id: ?int, query: string, creating: bool, new_name: string}
     */
    public function lineContactState(int $lineId): array
    {
        $state = $this->lineContact[$lineId] ?? [];

        return [
            'id' => isset($state['id']) && $state['id'] !== '' ? (int) $state['id'] : null,
            'query' => (string) ($state['query'] ?? ''),
            'creating' => (bool) ($state['creating'] ?? false),
            'new_name' => (string) ($state['new_name'] ?? ''),
        ];
    }

    /**
     * The payee currently chosen for a line: the picker's state when the row has
     * been touched, else what the pipeline suggested.
     */
    public function lineContactId(BankStatementLine $line): ?int
    {
        if (array_key_exists($line->id, $this->lineContact)) {
            return $this->lineContactState($line->id)['id'];
        }

        return $line->suggested_contact_id !== null ? (int) $line->suggested_contact_id : null;
    }

    /**
     * Search results for a row's picker: active contacts matching the typed
     * text — vendors, then employees (expense-claim reimbursements), for money
     * out; customers first for money in.
     *
     * @return Collection<int, Contact>
     */
    public function lineContactOptions(int $lineId): Collection
    {
        $query = trim($this->lineContactState($lineId)['query']);

        if ($query === '') {
            return collect();
        }

        $line = $this->statementLineForContact($lineId);
        $inflow = $line !== null && $line->isInflow();

        return Contact::query()
            ->where('is_active', true)
            ->where('display_name', 'like', '%'.$query.'%')
            ->when($inflow, fn ($q) => $q->orderByDesc('is_customer'))
            ->unless($inflow, fn ($q) => $q->orderByDesc('is_vendor')->orderByDesc('is_employee'))
            ->orderBy('display_name')
            ->limit(50)
            ->get(['id', 'display_name', 'is_vendor', 'is_employee', 'is_customer']);
    }

    /**
     * display_name for every payee shown on the page, one query.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function contactNames(): array
    {
        $ids = collect($this->lineContact)
            ->map(fn (array $state) => isset($state['id']) && $state['id'] !== '' ? (int) $state['id'] : null)
            ->merge($this->suggestedContactIdsOnPage())
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Contact::query()
            ->whereIn('id', $ids)
            ->pluck('display_name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * Contact ids suggested on lines the page shows but the user has not touched.
     *
     * @return Collection<int, int>
     */
    abstract protected function suggestedContactIdsOnPage(): Collection;

    public function selectLineContact(int $lineId, int $contactId): void
    {
        $line = $this->statementLineForContact($lineId);
        $contact = Contact::query()->where('is_active', true)->find($contactId);

        if ($line === null || $contact === null) {
            return;
        }

        $this->lineContact[$lineId] = ['id' => (int) $contact->id, 'query' => '', 'creating' => false, 'new_name' => ''];
        $line->forceFill(['suggested_contact_id' => $contact->id])->save();

        unset($this->contactNames);

        $this->afterLineContactChanged($line, $contact);
    }

    public function startNewLineContact(int $lineId): void
    {
        $state = $this->lineContactState($lineId);

        $this->lineContact[$lineId] = ['id' => null, 'query' => '', 'creating' => true, 'new_name' => trim($state['query'])];
    }

    public function createLineContact(int $lineId): void
    {
        $line = $this->statementLineForContact($lineId);
        $name = trim($this->lineContactState($lineId)['new_name']);

        if ($line === null || $name === '') {
            return;
        }

        $contact = app(SaveContact::class)->handle(
            ['display_name' => $name],
            $line->isInflow() ? 'is_customer' : 'is_vendor',
        );

        $this->selectLineContact($lineId, (int) $contact->id);
    }

    public function clearLineContact(int $lineId): void
    {
        $line = $this->statementLineForContact($lineId);

        $this->lineContact[$lineId] = ['id' => null, 'query' => '', 'creating' => false, 'new_name' => ''];
        unset($this->lineHint[$lineId], $this->contactNames);

        if ($line !== null) {
            $line->forceFill(['suggested_contact_id' => null, 'suggested_bill_id' => null, 'suggested_bill_allocations' => null])->save();
            $this->afterLineContactChanged($line, null);
        }
    }
}
