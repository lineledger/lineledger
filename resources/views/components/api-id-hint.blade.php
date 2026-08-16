@props(['id', 'field'])

{{--
    The record's surrogate primary key, shown while editing so an integrator can
    read the value their API calls and configs hardcode. Deliberately absent on
    create (there is no id yet) — pass a falsy :id and this renders nothing.

    `field` names the API parameter this id populates (e.g. `tax_code_id`), because
    every one of these lists also has a user-facing code or name, and only the id is
    stable. See docs/api-v1.md §8.

    Laid out as inline flow rather than a flex row on purpose: the separating
    spaces come from the markup itself, so the line reads correctly even if a
    gap-* utility never made it into the compiled stylesheet.
--}}
@if ($id)
    <p class="-mt-3 text-xs text-muted-foreground" data-test="api-id-hint">
        {{ __('API id') }}
        <span class="select-all font-mono text-sm text-zinc-700 dark:text-zinc-300" data-test="api-id-value">{{ $id }}</span>
        — {{ __('pass as :field to the REST API', ['field' => $field]) }}
    </p>
@endif
