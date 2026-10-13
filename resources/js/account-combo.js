/**
 * Typeable account picker for every line "Account" column (<x-account-combo>).
 *
 * Free Flux has no combobox, so a native <select> was the only option — and a
 * select can't be typed into by GL number. One text box here searches both the
 * account code and the name:
 *
 *  - "24" or "2400" lists the accounts whose code starts with it first;
 *  - "client" or "disb" finds "2400 — Client Disbursements", with accounts
 *    where a word starts with the query ranked above a match mid-word;
 *  - several words match in any order ("bmo usd", "chequing op");
 *  - case, accents, punctuation and dashes are ignored.
 *
 * The chart is emitted once per page by <x-account-combo.options> as a
 * <script type="application/json" data-account-options="key"> payload of
 * [{id, code, name}], which every row's combo reads by key — a large chart
 * repeated per row would bloat the page. The parsed payload is cached per
 * element and re-read whenever its text changes (a Livewire re-render can add
 * an account already coded on a line).
 *
 * The pure helpers (label, ranking, highlighting) are exported for the unit
 * tests in tests/js/account-combo.test.js; accountCombo() is the Alpine
 * component, registered in ./app.js.
 */

const LABEL_SEPARATOR = ' — ';
const NON_WORD = /[^\p{L}\p{N}]+/gu;
const COMBINING_MARKS = /[\u0300-\u036f]/g;

/** "2400 — Client Disbursements", or just the name for an account with no code. */
export function accountLabel(option) {
    if (!option) {
        return '';
    }

    const code = String(option.code ?? '').trim();
    const name = String(option.name ?? '');

    return code === '' ? name : code + LABEL_SEPARATOR + name;
}

/**
 * Lower-case and strip accents one UTF-16 unit at a time, so the result lines
 * up index-for-index with the input (the highlighter relies on that).
 */
export function foldText(text) {
    let out = '';

    for (const unit of String(text ?? '').split('')) {
        const lower = unit.normalize('NFD').charAt(0).toLowerCase();
        out += lower.length === 1 ? lower : lower.charAt(0);
    }

    return out;
}

/** Folded text with every run of punctuation, dashes and spaces collapsed to one space. */
export function normalizeSearch(text) {
    return foldText(text).replace(COMBINING_MARKS, '').replace(NON_WORD, ' ').trim();
}

/** The words of a query, normalized: "BMO - USD" → ["bmo", "usd"]. */
export function queryTokens(query) {
    const normalized = normalizeSearch(query);

    return normalized === '' ? [] : normalized.split(' ');
}

const searchIndex = new WeakMap();

function indexFor(option) {
    let entry = searchIndex.get(option);

    if (!entry) {
        const code = normalizeSearch(option.code);
        const name = normalizeSearch(option.name);
        const haystack = code === '' ? name : (name === '' ? code : code + ' ' + name);
        entry = { code, haystack, words: haystack.split(' ') };
        searchIndex.set(option, entry);
    }

    return entry;
}

/**
 * The options matching a query, best first. Every query word must appear in
 * the code or name (any order). Ranking, stable within each tier so the chart's
 * own order (by code) breaks ties:
 *
 *  0. the code is exactly the query ("2400");
 *  1. a numeric query that prefixes the code ("24" → 2400, 2410, …);
 *  2. every word starts a word of the code or name ("disb", "chequing op");
 *  3. any other match (mid-word).
 *
 * An empty query returns every option, in order.
 */
export function rankAccounts(options, query) {
    const list = Array.isArray(options) ? options : [];
    const tokens = queryTokens(query);

    if (tokens.length === 0) {
        return list.slice();
    }

    const whole = tokens.join(' ');
    const numeric = /^\p{N}/u.test(tokens[0]);
    const tiers = [[], [], [], []];

    for (const option of list) {
        if (!option || typeof option !== 'object') {
            continue;
        }

        const { code, haystack, words } = indexFor(option);

        if (!tokens.every((token) => haystack.includes(token))) {
            continue;
        }

        let tier = 3;

        if (code !== '' && code === whole) {
            tier = 0;
        } else if (numeric && code !== '' && code.startsWith(tokens[0])) {
            tier = 1;
        } else if (tokens.every((token) => words.some((word) => word.startsWith(token)))) {
            tier = 2;
        }

        tiers[tier].push(option);
    }

    return tiers.flat();
}

const WORD_CHAR = /[\p{L}\p{N}]/u;

/** Where a query word matches in folded text: the first word-start hit, else the first hit. */
function matchIndex(folded, token) {
    let at = folded.indexOf(token);
    const first = at;

    while (at !== -1) {
        if (at === 0 || !WORD_CHAR.test(folded[at - 1])) {
            return at;
        }
        at = folded.indexOf(token, at + 1);
    }

    return first;
}

/**
 * Split text into [{text, match}] runs, marking where each query word matched
 * (case-, accent- and punctuation-insensitive) for highlighting. One hit per
 * word, preferring the start of a word, so "1" in "1010" marks the leading 1.
 */
export function highlightSegments(text, query) {
    const source = String(text ?? '');
    const tokens = queryTokens(query);

    if (source === '' || tokens.length === 0) {
        return [{ text: source, match: false }];
    }

    const folded = foldText(source);
    const marks = new Array(source.length).fill(false);

    for (const token of tokens) {
        const at = matchIndex(folded, token);

        if (at !== -1) {
            marks.fill(true, at, at + token.length);
        }
    }

    const segments = [];

    for (let i = 0; i < source.length; i++) {
        const last = segments[segments.length - 1];

        if (last && last.match === marks[i]) {
            last.text += source[i];
        } else {
            segments.push({ text: source[i], match: marks[i] });
        }
    }

    return segments;
}

const EMPTY_CATALOG = Object.freeze({ raw: '', options: Object.freeze([]), byId: new Map() });
const catalogs = new WeakMap();

/**
 * The parsed options payload of a <script data-account-options> element,
 * cached until its text changes. Anything unparseable reads as an empty list.
 */
export function readAccountOptions(el) {
    if (!el) {
        return EMPTY_CATALOG;
    }

    const raw = String(el.textContent ?? '');
    const cached = catalogs.get(el);

    if (cached && cached.raw === raw) {
        return cached;
    }

    let options = [];

    try {
        const parsed = JSON.parse(raw === '' ? '[]' : raw);
        options = Array.isArray(parsed) ? parsed.filter((o) => o && typeof o === 'object') : [];
    } catch (e) {
        options = [];
    }

    const entry = { raw, options, byId: new Map(options.map((o) => [String(o.id), o])) };
    catalogs.set(el, entry);

    return entry;
}

/**
 * Bumped when a Livewire morph changes an options payload, so every combo's
 * label (a getter over the payload) re-renders. Made reactive by
 * installAccountCombo(); a plain object until then.
 */
let optionsVersion = { value: 0 };

const OPTIONS_SELECTOR = 'script[data-account-options]';

/** Register the Alpine component and the re-render hook. Call inside `alpine:init`. */
export function installAccountCombo(Alpine) {
    optionsVersion = Alpine.reactive({ value: 0 });
    Alpine.data('accountCombo', accountCombo);

    const watchMorphs = () => window.Livewire.hook('morphed', ({ el }) => {
        const scripts = el && typeof el.querySelectorAll === 'function' ? el.querySelectorAll(OPTIONS_SELECTOR) : [];
        const changed = [...scripts].some((script) => catalogs.get(script)?.raw !== String(script.textContent ?? ''));

        if (changed) {
            optionsVersion.value++;
        }
    });

    if (window.Livewire?.hook) {
        watchMorphs();
    } else {
        document.addEventListener('livewire:init', watchMorphs, { once: true });
    }
}

const CLEAR_KEY = 'clear';
const VIEWPORT_MARGIN = 8;
const GAP = 4;
const MIN_WIDTH = 288;
const MAX_HEIGHT = 288;

/**
 * The Alpine component behind <x-account-combo>. Its configuration rides data-*
 * attributes on the root (data-model, data-options, data-live,
 * data-placeholder) rather than x-data arguments, so the x-data expression is
 * identical on every row and a morph that renumbers rows just updates the
 * attributes (watched below) instead of re-initialising the component.
 *
 * The shown label is derived from $wire on every render, never held here, so
 * removing or reordering rows can't leave a stale account behind. Picking
 * writes through $wire.$set() so the server's updated*() hooks still run.
 */
export function accountCombo() {
    return {
        model: '',
        optionsKey: '',
        live: true,
        placeholder: '',
        uid: '',

        open: false,
        editing: false,
        // The user typed: the list filters by the query.
        typed: false,
        // Typed or arrowed: Tab commits the highlighted option.
        dirty: false,
        query: '',
        active: 0,
        justFocused: false,

        init() {
            this.uid = this.$id('account-combo');
            this.readConfig();

            this.observer = new MutationObserver(() => this.readConfig());
            this.observer.observe(this.$root, {
                attributes: true,
                attributeFilter: ['data-model', 'data-options', 'data-live', 'data-placeholder'],
            });

            // The list is position:fixed (computed from the input) so it escapes
            // the line table's overflow-x-auto clip; keep it pinned while open.
            // Capture phase so scrolls inside the overflow container count too.
            this.reposition = () => {
                if (this.open) {
                    this.position();
                }
            };
            window.addEventListener('scroll', this.reposition, true);
            window.addEventListener('resize', this.reposition);
        },

        destroy() {
            this.observer?.disconnect();
            window.removeEventListener('scroll', this.reposition, true);
            window.removeEventListener('resize', this.reposition);
        },

        readConfig() {
            const data = this.$root.dataset;
            this.model = data.model ?? '';
            this.optionsKey = data.options ?? '';
            this.live = data.live !== 'false';
            this.placeholder = data.placeholder ?? '';
        },

        // --- state derived from Livewire + the options payload -------------------

        get catalog() {
            void optionsVersion.value;

            const selector = `script[data-account-options="${cssEscape(this.optionsKey)}"]`;
            const scope = this.$root.closest('[wire\\:id]') ?? document;

            return readAccountOptions(scope.querySelector(selector) ?? document.querySelector(selector));
        },

        get selectedId() {
            const value = this.model === '' ? null : this.$wire.$get(this.model);

            return value === null || value === undefined ? '' : String(value);
        },

        get selected() {
            return this.selectedId === '' ? null : (this.catalog.byId.get(this.selectedId) ?? null);
        },

        get currentLabel() {
            return accountLabel(this.selected);
        },

        get inputValue() {
            return this.editing ? this.query : this.currentLabel;
        },

        get filtering() {
            return this.typed && this.query.trim() !== '';
        },

        /** Options in list order; the unfiltered list leads with the "—" clear choice. */
        get items() {
            const options = this.catalog.options;

            if (this.filtering) {
                return rankAccounts(options, this.query);
            }

            return [{ id: '', code: '', name: this.placeholder || '—', clear: true }, ...options];
        },

        /** What the open list renders (nothing while closed, so idle rows stay light). */
        get rendered() {
            if (!this.open) {
                return [];
            }

            const query = this.filtering ? this.query : '';
            const selectedId = this.selectedId;

            return this.items.map((item) => ({
                key: item.clear ? CLEAR_KEY : 'a' + item.id,
                item,
                clear: Boolean(item.clear),
                selected: String(item.id ?? '') === selectedId,
                code: String(item.code ?? ''),
                codeParts: highlightSegments(item.code, query),
                nameParts: highlightSegments(item.name, query),
            }));
        },

        get listId() {
            return this.uid + '-list';
        },

        optionId(idx) {
            return this.uid + '-option-' + idx;
        },

        // --- bindings for the <input> (x-bind="comboInput") ----------------------

        comboInput: {
            [':value']() {
                return this.inputValue;
            },
            [':title']() {
                return this.currentLabel || null;
            },
            [':aria-expanded']() {
                return this.open ? 'true' : 'false';
            },
            [':aria-controls']() {
                return this.listId;
            },
            [':aria-activedescendant']() {
                return this.open && this.items.length ? this.optionId(this.active) : null;
            },
            ['@focus']() {
                this.startEdit();
            },
            ['@pointerdown']() {
                this.justFocused = document.activeElement !== this.$refs.input;
            },
            ['@mouseup'](event) {
                // Keep the select-all from focus: the mouseup of the click that
                // focused the field would otherwise collapse it to a caret.
                if (this.justFocused) {
                    event.preventDefault();
                    this.justFocused = false;
                }
            },
            ['@click']() {
                if (!this.open) {
                    this.startEdit();
                }
            },
            ['@input'](event) {
                this.onInput(event);
            },
            ['@keydown'](event) {
                this.onKeydown(event);
            },
            ['@focusout'](event) {
                if (!this.$root.contains(event.relatedTarget)) {
                    this.close();
                }
            },
        },

        // --- behaviour -------------------------------------------------------------

        isDisabled() {
            const input = this.$refs.input;

            return !input || input.disabled || input.readOnly || input.matches(':disabled');
        },

        startEdit() {
            if (this.isDisabled()) {
                return;
            }

            this.editing = true;
            this.typed = false;
            this.dirty = false;
            this.query = this.currentLabel;
            this.active = this.indexOfSelected();
            this.openList();
            this.$nextTick(() => this.$refs.input?.select());
        },

        openList() {
            this.open = true;
            this.$nextTick(() => {
                this.position();
                this.scrollActiveIntoView();
            });
        },

        close() {
            this.open = false;
            this.editing = false;
            this.typed = false;
            this.dirty = false;
            this.query = '';
        },

        indexOfSelected() {
            const id = this.selectedId;
            const index = this.items.findIndex((item) => String(item.id ?? '') === id);

            return index < 0 ? 0 : index;
        },

        onInput(event) {
            if (this.isDisabled()) {
                return;
            }

            this.editing = true;
            this.typed = true;
            this.dirty = true;
            this.query = event.target.value;
            this.active = 0;

            if (this.open) {
                this.$nextTick(() => this.scrollActiveIntoView());
            } else {
                this.openList();
            }
        },

        onKeydown(event) {
            switch (event.key) {
                case 'ArrowDown':
                case 'ArrowUp':
                    event.preventDefault();
                    if (!this.open) {
                        this.startEdit();
                    } else {
                        this.move(event.key === 'ArrowDown' ? 1 : -1);
                    }
                    break;
                case 'Enter':
                    // Never let Enter submit the surrounding form.
                    event.preventDefault();
                    if (event.isComposing) {
                        break;
                    }
                    if (!this.open) {
                        this.startEdit();
                    } else {
                        this.commitActive();
                    }
                    break;
                case 'Tab':
                    // Typed or arrowed → Tab commits the highlighted match and
                    // focus moves on naturally. A plain tab-through changes nothing.
                    if (this.open && this.dirty) {
                        this.commitActive({ refocus: false });
                    }
                    this.close();
                    break;
                case 'Escape':
                    if (this.open) {
                        event.preventDefault();
                        this.close();
                    }
                    break;
                default:
                    break;
            }
        },

        move(delta) {
            const count = this.items.length;
            if (count === 0) {
                return;
            }

            this.dirty = true;
            this.active = (this.active + delta + count) % count;
            this.$nextTick(() => this.scrollActiveIntoView());
        },

        commitActive({ refocus = true } = {}) {
            const items = this.items;
            const item = items[this.active] ?? items[0];

            if (item) {
                this.pick(item, { refocus });
            } else {
                this.close();
            }
        },

        pick(item, { refocus = true } = {}) {
            const value = item && !item.clear && item.id !== undefined && item.id !== null ? String(item.id) : '';
            const changed = value !== this.selectedId;

            this.close();

            if (!changed) {
                return;
            }

            this.$wire.$set(this.model, value, this.live);

            const input = this.$refs.input;
            if (input) {
                // A picked account is an edit, like changing a <select>: let
                // listeners such as the escape-back unsaved-changes guard see it.
                input.dispatchEvent(new Event('change', { bubbles: true }));

                if (refocus && document.activeElement === input) {
                    this.$nextTick(() => input.select());
                }
            }
        },

        // --- list layout -------------------------------------------------------------

        position() {
            const input = this.$refs.input;
            const list = this.$refs.list;
            if (!input || !list) {
                return;
            }

            const rect = input.getBoundingClientRect();
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;

            const width = Math.min(Math.max(rect.width, MIN_WIDTH), viewportWidth - VIEWPORT_MARGIN * 2);
            const left = Math.max(VIEWPORT_MARGIN, Math.min(rect.left, viewportWidth - VIEWPORT_MARGIN - width));

            const spaceBelow = viewportHeight - rect.bottom - GAP - VIEWPORT_MARGIN;
            const spaceAbove = rect.top - GAP - VIEWPORT_MARGIN;
            const above = spaceBelow < Math.min(MAX_HEIGHT, 160) && spaceAbove > spaceBelow;
            const maxHeight = Math.max(96, Math.min(MAX_HEIGHT, above ? spaceAbove : spaceBelow));

            list.style.width = `${width}px`;
            list.style.maxHeight = `${maxHeight}px`;
            list.style.left = `${left}px`;
            list.style.top = '0px';

            const height = list.offsetHeight;
            const top = above ? rect.top - GAP - height : rect.bottom + GAP;
            list.style.top = `${top}px`;

            // A transformed ancestor (an animating modal, say) becomes the
            // containing block for position:fixed. Measure and correct for it.
            const placed = list.getBoundingClientRect();
            const dx = left - placed.left;
            const dy = top - placed.top;
            if (Math.abs(dx) > 0.5) {
                list.style.left = `${left + dx}px`;
            }
            if (Math.abs(dy) > 0.5) {
                list.style.top = `${top + dy}px`;
            }
        },

        scrollActiveIntoView() {
            const list = this.$refs.list;
            const el = list?.querySelector('[data-active]');
            if (!el) {
                return;
            }

            if (el.offsetTop < list.scrollTop) {
                list.scrollTop = el.offsetTop;
            } else if (el.offsetTop + el.offsetHeight > list.scrollTop + list.clientHeight) {
                list.scrollTop = el.offsetTop + el.offsetHeight - list.clientHeight;
            }
        },
    };
}

function cssEscape(value) {
    if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        return CSS.escape(value);
    }

    return String(value).replace(/["\\]/g, '\\$&');
}
