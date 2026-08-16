<?php

use App\Enums\ApiAbility;
use App\Enums\ApiResource;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Services\Audit\SecurityLogRecorder;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public string $label = '';

    /**
     * Selected scopes — either coarse `{domain}:{action}` or fine
     * `{resource}:{action}`. Empty = full access.
     *
     * @var array<int, string>
     */
    public array $abilities = [];

    /** Expiry choice for a new key: '' (never), or a number of days ('30','90','365'). */
    public string $expiresIn = '';

    public bool $showKeyModal = false;

    /**
     * Set while editing an existing key's label/scopes in place; null when the
     * modal is minting a new key.
     */
    #[Locked]
    public ?int $editingKeyId = null;

    #[Locked]
    public ?string $plaintext = null;

    #[Locked]
    public string $rotatedFromLabel = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $keys = [];

    public bool $canManage = false;

    public function mount(): void
    {
        $this->loadKeys();
    }

    protected function company(): ?Company
    {
        return auth()->user()?->currentCompany;
    }

    /**
     * Available scopes grouped by domain for the create modal.
     *
     * @return array<string, array<int, ApiAbility>>
     */
    public function getAbilityGroupsProperty(): array
    {
        $groups = [];
        foreach (ApiAbility::cases() as $ability) {
            $groups[$ability->domain()][] = $ability;
        }

        return $groups;
    }

    /**
     * Resources grouped by their parent domain, for the optional per-resource
     * refinement under each domain's read/write checkboxes.
     *
     * @return array<string, array<int, ApiResource>>
     */
    public function getResourceGroupsProperty(): array
    {
        $groups = [];
        foreach (ApiResource::cases() as $resource) {
            $groups[$resource->domain()][] = $resource;
        }

        return $groups;
    }

    protected function refreshPermissions(): void
    {
        $company = $this->company();
        $role = $company ? auth()->user()->companyRole($company) : null;

        $this->canManage = $role !== null && $role->isAtLeast(\App\Enums\CompanyRole::Admin);
    }

    public function loadKeys(): void
    {
        $this->refreshPermissions();
        $company = $this->company();

        if (! $company) {
            $this->keys = [];

            return;
        }

        $this->keys = CompanyApiKey::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CompanyApiKey $key) => [
                'id' => $key->id,
                'label' => $key->label,
                'prefix' => $key->prefix,
                'last_four' => $key->last_four,
                'last_used_at_diff' => $key->last_used_at?->diffForHumans(),
                'created_at_diff' => $key->created_at?->diffForHumans(),
                'revoked' => $key->revoked_at !== null,
                'abilities' => $key->abilities ?? [],
                'expires_at' => $key->expires_at?->format('Y-m-d'),
                'expired' => $key->isExpired(),
                'expires_soon' => $key->expires_at !== null
                    && ! $key->isExpired()
                    && $key->expires_at->isBefore(now()->addDays(7)),
            ])
            ->toArray();
    }

    public function openCreateModal(): void
    {
        $this->refreshPermissions();
        if (! $this->canManage) {
            return;
        }

        $this->resetForm();
        $this->showKeyModal = true;
    }

    public function openEditModal(int $keyId): void
    {
        $this->refreshPermissions();

        if (! $this->canManage) {
            return;
        }

        $key = $this->findKey($keyId);

        if (! $key || ! $key->isActive()) {
            return;
        }

        $this->resetForm();
        $this->editingKeyId = $key->id;
        $this->label = $key->label;
        $this->abilities = $key->abilities ?? [];
        $this->showKeyModal = true;
    }

    public function closeKeyModal(): void
    {
        $this->showKeyModal = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->editingKeyId = null;
        $this->plaintext = null;
        $this->rotatedFromLabel = '';
        $this->label = '';
        $this->abilities = [];
        $this->expiresIn = '';
    }

    /**
     * Resolve the expiry choice to a concrete timestamp (or null for never).
     */
    protected function expiryFromChoice(): ?\Carbon\CarbonInterface
    {
        return in_array($this->expiresIn, ['30', '90', '365'], true)
            ? now()->addDays((int) $this->expiresIn)
            : null;
    }

    protected function findKey(int $keyId): ?CompanyApiKey
    {
        $company = $this->company();

        if (! $company) {
            return null;
        }

        return CompanyApiKey::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('id', $keyId)
            ->first();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function formRules(): array
    {
        return [
            'label' => ['required', 'string', 'max:80'],
            'abilities' => ['array'],
            'abilities.*' => ['string', \Illuminate\Validation\Rule::in([...ApiAbility::values(), ...ApiResource::scopeValues()])],
            'expiresIn' => ['string', \Illuminate\Validation\Rule::in(['', '30', '90', '365'])],
        ];
    }

    public function create(SecurityLogRecorder $recorder): void
    {
        $this->refreshPermissions();
        $company = $this->company();

        if (! $this->canManage || ! $company) {
            return;
        }

        $validated = $this->validate($this->formRules());

        $result = CompanyApiKey::mint($company, $validated['label'], auth()->id(), $validated['abilities'] ?? [], $this->expiryFromChoice());
        $this->plaintext = $result['plaintext'];

        $recorder->record(SecurityEvent::ApiKeyCreated, auth()->user(), metadata: [
            'company_id' => $company->id,
            'api_key_id' => $result['key']->id,
            'label' => $result['key']->label,
            'expires_at' => $result['key']->expires_at?->toIso8601String(),
        ]);

        $this->loadKeys();
        $this->reset('label');

        Flux::toast(variant: 'success', text: __('API key created.'));
    }

    /**
     * Re-scope an existing key in place. The token is untouched — callers keep
     * working with the same secret, only the granted abilities change.
     */
    public function update(SecurityLogRecorder $recorder): void
    {
        $this->refreshPermissions();
        $company = $this->company();

        if (! $this->canManage || ! $company || $this->editingKeyId === null) {
            return;
        }

        $key = $this->findKey($this->editingKeyId);

        if (! $key || ! $key->isActive()) {
            return;
        }

        $validated = $this->validate($this->formRules());

        $previousAbilities = $key->abilities;

        $key->forceFill([
            'label' => $validated['label'],
            'abilities' => ($validated['abilities'] ?? []) === []
                ? null
                : array_values(array_unique($validated['abilities'])),
        ])->save();

        $recorder->record(SecurityEvent::ApiKeyUpdated, auth()->user(), metadata: [
            'company_id' => $company->id,
            'api_key_id' => $key->id,
            'label' => $key->label,
            'previous_abilities' => $previousAbilities,
            'abilities' => $key->abilities,
        ]);

        $this->loadKeys();
        $this->closeKeyModal();

        Flux::toast(variant: 'success', text: __('API key updated.'));
    }

    public function rotate(int $keyId, SecurityLogRecorder $recorder): void
    {
        $this->refreshPermissions();
        $company = $this->company();

        if (! $this->canManage || ! $company) {
            return;
        }

        $old = CompanyApiKey::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('id', $keyId)
            ->firstOrFail();

        if (! $old->isActive()) {
            return;
        }

        $result = CompanyApiKey::mint($company, $old->label, auth()->id(), $old->abilities ?? []);
        $old->revoke();

        $this->resetForm();
        $this->plaintext = $result['plaintext'];
        $this->rotatedFromLabel = $old->label;
        $this->showKeyModal = true;

        $recorder->record(SecurityEvent::ApiKeyRotated, auth()->user(), metadata: [
            'company_id' => $company->id,
            'old_api_key_id' => $old->id,
            'new_api_key_id' => $result['key']->id,
            'label' => $result['key']->label,
        ]);

        $this->loadKeys();

        Flux::toast(variant: 'success', text: __('API key rotated.'));
    }

    public function revoke(int $keyId, SecurityLogRecorder $recorder): void
    {
        $this->refreshPermissions();
        $company = $this->company();

        if (! $this->canManage || ! $company) {
            return;
        }

        $key = CompanyApiKey::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('id', $keyId)
            ->firstOrFail();

        if (! $key->isActive()) {
            return;
        }

        $key->revoke();

        $recorder->record(SecurityEvent::ApiKeyRevoked, auth()->user(), metadata: [
            'company_id' => $company->id,
            'api_key_id' => $key->id,
            'label' => $key->label,
        ]);

        $this->loadKeys();

        Flux::toast(variant: 'success', text: __('API key revoked.'));
    }
}; ?>

<section class="mt-12" wire:cloak>
    <flux:heading>{{ __('API keys') }}</flux:heading>
    <flux:subheading>
        @if ($this->company())
            {{ __('Manage API keys for :company. Keys authenticate the /api/v1 REST API; scope each key to the domains it needs.', ['company' => $this->company()->name]) }}
            <a href="{{ route('docs.api') }}" class="underline" wire:navigate>{{ __('Read the API docs') }}</a>.
        @else
            {{ __('Pick a company to manage API keys.') }}
        @endif
    </flux:subheading>

    @if ($this->company())
        <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm">
            @if ($canManage)
                <div class="flex justify-start">
                    <flux:button
                        variant="primary"
                        size="sm"
                        wire:click="openCreateModal"
                        data-test="api-key-create"
                    >
                        {{ __('Create API key') }}
                    </flux:button>
                </div>
            @else
                <flux:text variant="subtle">{{ __('Only company owners and admins can manage API keys.') }}</flux:text>
            @endif

            <div class="border rounded-lg border-border overflow-hidden">
                @forelse ($keys as $key)
                    <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-border' : '' }}">
                        <div class="flex items-center gap-4">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                <flux:icon.key class="size-5 text-muted-foreground" />
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center gap-2.5">
                                    <p class="font-medium tracking-tight">{{ $key['label'] }}</p>
                                    @if ($key['revoked'])
                                        <flux:badge size="sm" color="zinc">{{ __('Revoked') }}</flux:badge>
                                    @elseif ($key['expired'])
                                        <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="emerald">{{ __('Active') }}</flux:badge>
                                        @if ($key['expires_soon'])
                                            <flux:badge size="sm" color="amber">{{ __('Expires soon') }}</flux:badge>
                                        @endif
                                    @endif
                                </div>
                                <p class="text-muted-foreground text-xs font-mono">
                                    {{ $key['prefix'] }}_…{{ $key['last_four'] }}
                                </p>
                                <div class="flex flex-wrap items-center gap-1">
                                    @if (empty($key['abilities']))
                                        <flux:badge size="sm" color="amber">{{ __('Full access') }}</flux:badge>
                                    @else
                                        @foreach ($key['abilities'] as $ability)
                                            <flux:badge size="sm" color="zinc">{{ $ability }}</flux:badge>
                                        @endforeach
                                    @endif
                                </div>
                                <p class="text-muted-foreground text-xs">
                                    {{ __('Created :time', ['time' => $key['created_at_diff']]) }}
                                    @if ($key['last_used_at_diff'])
                                        <span class="opacity-50 mx-1">/</span>
                                        {{ __('Last used :time', ['time' => $key['last_used_at_diff']]) }}
                                    @else
                                        <span class="opacity-50 mx-1">/</span>
                                        {{ __('Never used') }}
                                    @endif
                                    <span class="opacity-50 mx-1">/</span>
                                    @if ($key['expires_at'])
                                        {{ __('Expires :date', ['date' => $key['expires_at']]) }}
                                    @else
                                        {{ __('No expiry') }}
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($canManage && ! $key['revoked'])
                            <div class="flex items-center gap-2">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    wire:click="openEditModal({{ $key['id'] }})"
                                    data-test="api-key-edit"
                                >
                                    {{ __('Edit') }}
                                </flux:button>
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    wire:click="rotate({{ $key['id'] }})"
                                    wire:confirm="{{ __('Rotate this API key? The current value will stop working immediately.') }}"
                                >
                                    {{ __('Rotate') }}
                                </flux:button>
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="revoke({{ $key['id'] }})"
                                    wire:confirm="{{ __('Revoke this API key? Calls using it will start failing immediately.') }}"
                                    class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                />
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-muted">
                            <flux:icon.key class="size-7 text-muted-foreground" />
                        </div>
                        <p class="font-medium">{{ __('No API keys yet') }}</p>
                        <flux:text class="mt-1">{{ __('Create a key to grant external systems create access.') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    <flux:modal
        name="api-key-modal"
        class="max-w-md md:min-w-md"
        @close="closeKeyModal"
        wire:model="showKeyModal"
    >
        @if ($plaintext)
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">
                        @if ($rotatedFromLabel)
                            {{ __('Key rotated') }}
                        @else
                            {{ __('Copy your new API key') }}
                        @endif
                    </flux:heading>
                    <flux:text>
                        {{ __('This is the only time the key will be shown. Store it somewhere safe — you won\'t be able to see it again.') }}
                    </flux:text>
                </div>

                <div class="p-3 rounded-md bg-muted border border-border font-mono text-xs break-all select-all" data-test="api-key-plaintext">
                    {{ $plaintext }}
                </div>

                <div class="flex justify-end">
                    <flux:button variant="primary" wire:click="closeKeyModal">
                        {{ __('Done') }}
                    </flux:button>
                </div>
            </div>
        @else
            <form wire:submit="{{ $editingKeyId ? 'update' : 'create' }}" class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">
                        {{ $editingKeyId ? __('Edit API key') : __('Create API key') }}
                    </flux:heading>
                    <flux:text>
                        @if ($editingKeyId)
                            {{ __('Change the label or scopes for this key. The key value itself stays the same, so anything already using it keeps working.') }}
                        @else
                            {{ __('Give your key a label so you can identify it later.') }}
                        @endif
                    </flux:text>
                </div>

                <flux:input
                    wire:model="label"
                    :label="__('Label')"
                    placeholder="Storefront sync"
                    required
                    autofocus
                    data-test="api-key-label"
                />

                @unless ($editingKeyId)
                    <flux:select wire:model="expiresIn" :label="__('Expires')" data-test="api-key-expiry">
                        <flux:select.option value="">{{ __('Never') }}</flux:select.option>
                        <flux:select.option value="30">{{ __('In 30 days') }}</flux:select.option>
                        <flux:select.option value="90">{{ __('In 90 days') }}</flux:select.option>
                        <flux:select.option value="365">{{ __('In 1 year') }}</flux:select.option>
                    </flux:select>
                @endunless

                <div class="space-y-2">
                    <flux:label>{{ __('Scopes') }}</flux:label>
                    <flux:text size="sm" variant="subtle">
                        {{ __('Leave all unchecked for full access. A write scope also grants read. A domain scope grants every resource under it; refine per resource for narrower keys.') }}
                    </flux:text>
                    <div class="mt-2 grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                        @foreach ($this->abilityGroups as $domain => $abilities)
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $domain }}</p>
                                @foreach ($abilities as $ability)
                                    <flux:checkbox
                                        wire:model="abilities"
                                        value="{{ $ability->value }}"
                                        :label="$ability->isWrite() ? __('write') : __('read')"
                                    />
                                @endforeach

                                @if (! empty($this->resourceGroups[$domain]))
                                    <details class="mt-1">
                                        <summary class="cursor-pointer text-xs text-muted-foreground hover:text-foreground">
                                            {{ __('Refine by resource') }}
                                        </summary>
                                        <div class="mt-1 space-y-1 border-l border-border pl-3">
                                            @foreach ($this->resourceGroups[$domain] as $resource)
                                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                                    <span class="text-xs text-muted-foreground">{{ $resource->label() }}</span>
                                                    <flux:checkbox
                                                        wire:model="abilities"
                                                        value="{{ $resource->value }}:read"
                                                        :label="__('read')"
                                                    />
                                                    <flux:checkbox
                                                        wire:model="abilities"
                                                        value="{{ $resource->value }}:write"
                                                        :label="__('write')"
                                                    />
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex gap-3 justify-end">
                    <flux:button variant="outline" type="button" wire:click="closeKeyModal">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button variant="primary" type="submit" data-test="api-key-submit">
                        {{ $editingKeyId ? __('Save changes') : __('Create') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</section>
