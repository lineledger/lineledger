@props([
    'index' => 0,
    'items' => [],
    'dataTest' => 'line-item',
])

{{--
    Per-row item picker for invoice / credit-memo lines. Free Flux has no
    combobox, so this mirrors the contact-combo pattern: type to filter,
    scroll/click or arrow-keys to select. The displayed label is derived
    reactively from $wire (not held in Alpine state) so removing or reordering
    rows can never leave a stale name behind. Picking an item writes through
    $wire.set(), which runs the server-side updatedLines() prefill.
--}}
<div
    x-data="{
        index: {{ (int) $index }},
        items: @js($items),
        open: false,
        editing: false,
        dirty: false,
        active: 0,
        query: '',
        get currentName() {
            const id = this.$wire.lines?.[this.index]?.item_id;
            if (! id) {
                return '';
            }
            const found = this.items.find((i) => String(i.id) === String(id));
            return found ? found.name : '';
        },
        get value() {
            return this.editing ? this.query : this.currentName;
        },
        get matches() {
            const q = this.query.trim().toLowerCase();
            if (q === '') {
                return this.items;
            }
            return this.items.filter((i) => (i.name + ' ' + (i.sku ?? '') + ' ' + (i.category ?? '')).toLowerCase().includes(q));
        },
        startEdit(el) {
            this.editing = true;
            this.dirty = false;
            this.query = this.currentName;
            this.open = true;
            this.active = 0;
            this.$nextTick(() => el.select());
        },
        onInput(el) {
            this.editing = true;
            this.dirty = true;
            this.open = true;
            this.active = 0;
            this.query = el.value;
        },
        move(delta) {
            this.dirty = true;
            this.open = true;
            const n = this.matches.length;
            if (n === 0) {
                return;
            }
            this.active = (this.active + delta + n) % n;
        },
        pick(item) {
            this.editing = false;
            this.open = false;
            this.query = '';
            this.$wire.set('lines.' + this.index + '.item_id', item.id);
        },
        enter() {
            const m = this.matches;
            if (this.open && m.length) {
                this.pick(m[this.active] ?? m[0]);
            }
        },
        commit() {
            // Type/arrow → Tab: commit the highlighted match so focus advances
            // naturally. A plain tab-through (dirty === false) is a no-op so it
            // never re-picks the current item and clobbers an edited description.
            const m = this.matches;
            if (this.dirty && this.query.trim() !== '' && m.length) {
                this.pick(m[this.active] ?? m[0]);
            }
            this.editing = false;
            this.open = false;
            this.dirty = false;
        },
        cancel() {
            this.editing = false;
            this.open = false;
            this.query = '';
        },
    }"
    @click.outside="cancel()"
    class="relative"
>
    <flux:input
        type="text"
        autocomplete="off"
        placeholder="—"
        :data-test="$dataTest"
        x-bind:value="value"
        x-on:focus="startEdit($el)"
        x-on:input="onInput($el)"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.enter.prevent="enter()"
        x-on:keydown.tab="commit()"
        x-on:keydown.escape.prevent="cancel(); $el.blur()"
        x-bind:data-item-input="index"
    />

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-auto rounded-md border border-border bg-card py-1 shadow-lg"
    >
        <template x-for="(item, idx) in matches" :key="item.id">
            <button
                type="button"
                tabindex="-1"
                x-on:click="pick(item)"
                x-on:mouseenter="active = idx"
                x-bind:class="active === idx ? 'bg-muted' : ''"
                class="block w-full px-3 py-2 text-left text-sm"
            >
                <span x-text="item.name"></span>
                <template x-if="item.category">
                    <span class="ml-2 text-xs text-muted-foreground" x-text="item.category"></span>
                </template>
            </button>
        </template>
        <template x-if="matches.length === 0">
            <div class="px-3 py-2 text-sm text-muted-foreground">{{ __('No matching items.') }}</div>
        </template>
    </div>
</div>
