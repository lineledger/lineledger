/**
 * Drag-to-resize columns on the line-item tables (cheques, bills, invoices, …),
 * remembered per browser.
 *
 *   <table x-resizable-columns="'cheque-lines'" wire:ignore.self …>
 *     <thead><tr>
 *       <th data-col="account" data-col-min="120" wire:ignore.self class="relative w-72 …">
 *         Account <x-col-resize-handle />
 *       </th>
 *       <th data-col-flex>Description</th>
 *       <th data-col="amount" data-col-min="88" wire:ignore.self class="relative w-28 …">
 *         <x-col-resize-handle edge="start" /> Amount
 *       </th>
 *       …
 *
 *  - Desktop only (min-width: 1024px). Below that the line tables turn into
 *    cards, so the directive steps back and clears every inline width it set.
 *  - While active the table uses `table-layout: fixed`, and every [data-col]
 *    header gets an inline px width (fitColumns):
 *      · a column the user has dragged keeps exactly that width;
 *      · the rest start at their Tailwind width class (their default) and, when
 *        the container is too narrow for all of them, shrink in proportion —
 *        never below data-col-min — the way the browser's auto layout used to
 *        squeeze them;
 *      · the [data-col-flex] column (Description) takes whatever is left. The
 *        others shrink before it drops below FLEX_MIN_WIDTH; once they're all
 *        at their minimums it gives way down to FLEX_FLOOR_WIDTH, and past
 *        that the table's min-width grows and the overflow-x-auto wrapper
 *        scrolls.
 *    A container resize (window, sidebar) refits; so does every Livewire morph
 *    of the component, which is how a column the morph adds (toggling an
 *    invoice column on) gets its width.
 *  - The handles are server markup (<x-col-resize-handle>), so morphs keep
 *    them; pointer events are delegated from the table. A handle on a column's
 *    right edge (edge="end", columns left of the flexible one) widens it to the
 *    right; one on the left edge (edge="start", columns right of it) widens it
 *    to the left — so the border under the pointer follows the pointer while
 *    the flexible column absorbs the difference. Double-clicking a handle
 *    resets that column to its default.
 *  - Widths persist in localStorage['ll:col-widths:<key>'] as { "<data-col>": px }.
 *    Every storage access is guarded; with storage blocked the widths still
 *    hold for the life of the page.
 *  - Morph survival: `wire:ignore.self` on the table and on each resizable <th>
 *    makes Livewire skip their attributes (so the inline styles are never
 *    stripped) while still morphing their children.
 */

export const STORAGE_PREFIX = 'll:col-widths:';
export const DESKTOP_QUERY = '(min-width: 1024px)';
export const DEFAULT_MIN_WIDTH = 64;
export const DEFAULT_MAX_WIDTH = 1200;
export const FLEX_MIN_WIDTH = 160;
export const FLEX_FLOOR_WIDTH = 96;

const RESERVED_NAMES = new Set(['__proto__', 'constructor', 'prototype']);
const COLUMN_NAME = /^[A-Za-z0-9_-]{1,64}$/;
const DEFAULT_WIDTH = Symbol('llColDefaultWidth');

/** The localStorage key for one table's widths. */
export function storageKeyFor(key) {
    return STORAGE_PREFIX + String(key ?? '');
}

/** Is this a usable column name (a data-col value)? */
export function isColumnName(name) {
    return typeof name === 'string' && COLUMN_NAME.test(name) && !RESERVED_NAMES.has(name);
}

/**
 * A width in whole px, held between the column's minimum and maximum. A
 * missing or nonsense width falls back to the minimum.
 *
 * @param {unknown} width
 * @param {number} [min]
 * @param {number} [max]
 * @returns {number}
 */
export function clampWidth(width, min = DEFAULT_MIN_WIDTH, max = DEFAULT_MAX_WIDTH) {
    const lo = Number.isFinite(min) && min > 0 ? min : DEFAULT_MIN_WIDTH;
    const hi = Number.isFinite(max) && max >= lo ? max : Math.max(lo, DEFAULT_MAX_WIDTH);
    const value = typeof width === 'number' ? width : Number.parseFloat(String(width ?? ''));

    if (!Number.isFinite(value)) {
        return Math.round(lo);
    }

    return Math.round(Math.min(hi, Math.max(lo, value)));
}

/**
 * The width a drag produces: a handle on the column's right edge ('end')
 * grows it as the pointer moves right, one on its left edge ('start') as the
 * pointer moves left.
 *
 * @param {number} startWidth
 * @param {number} delta  pointer movement in px since the drag began (clientX)
 * @param {'start'|'end'} [edge]
 * @param {number} [min]
 * @param {number} [max]
 * @returns {number}
 */
export function resizedWidth(startWidth, delta, edge = 'end', min = DEFAULT_MIN_WIDTH, max = DEFAULT_MAX_WIDTH) {
    const d = Number.isFinite(delta) ? delta : 0;

    return clampWidth(startWidth + (edge === 'start' ? -d : d), min, max);
}

/**
 * Keep only well-formed entries: a column name mapped to a positive, finite
 * number of px (rounded). Anything else — wrong types, arrays, prototype keys —
 * is dropped.
 *
 * @param {unknown} data
 * @returns {Record<string, number>}
 */
export function sanitizeWidths(data) {
    const out = {};

    if (data === null || typeof data !== 'object' || Array.isArray(data)) {
        return out;
    }

    for (const [name, value] of Object.entries(data)) {
        if (!isColumnName(name) || typeof value !== 'number' || !Number.isFinite(value) || value <= 0) {
            continue;
        }

        out[name] = Math.round(value);
    }

    return out;
}

/** Parse a stored payload; anything unreadable is an empty set of widths. */
export function parseWidths(raw) {
    if (typeof raw !== 'string' || raw === '') {
        return {};
    }

    try {
        return sanitizeWidths(JSON.parse(raw));
    } catch {
        return {};
    }
}

/** The stored payload for a set of widths. */
export function serializeWidths(widths) {
    return JSON.stringify(sanitizeWidths(widths));
}

/**
 * A copy of the widths with one column set — or removed, when `width` is null
 * (reset to its default).
 *
 * @param {Record<string, number>} widths
 * @param {string} name
 * @param {number|null} width
 * @returns {Record<string, number>}
 */
export function withWidth(widths, name, width) {
    const next = sanitizeWidths(widths);

    if (!isColumnName(name)) {
        return next;
    }

    if (width === null || width === undefined) {
        delete next[name];
    } else {
        Object.assign(next, sanitizeWidths({ [name]: width }));
    }

    return next;
}

/**
 * Merge the saved widths with the columns the table has right now, and fit
 * them into the room available.
 *
 *  - A column with a saved width gets exactly that (clamped to its bounds).
 *  - Every other column gets its default; when those defaults don't fit in
 *    what's left of `available`, they shrink in proportion, each stopping at
 *    its minimum (and the rest sharing what remains).
 *
 * Saved widths for columns the table doesn't show right now (an invoice column
 * toggled off) are ignored here — they stay in storage for when it's back.
 *
 * @param {Record<string, number>} saved
 * @param {Array<{ name: string, base: number, min?: number, max?: number }>} columns
 *        base = the column's default width (its Tailwind width class)
 * @param {number} available  px for these columns: container − fixed non-resizable columns − the flexible column's minimum
 * @returns {Record<string, number>} px per column name
 */
export function fitColumns(saved, columns, available) {
    const pinned = sanitizeWidths(saved);
    const out = {};
    let room = Number.isFinite(available) ? available : Number.POSITIVE_INFINITY;
    let soft = [];

    for (const column of columns ?? []) {
        const name = column?.name;
        if (!isColumnName(name) || Object.hasOwn(out, name)) {
            continue;
        }

        const min = Number.isFinite(column.min) && column.min > 0 ? column.min : DEFAULT_MIN_WIDTH;
        const max = Math.max(min, Number.isFinite(column.max) ? column.max : DEFAULT_MAX_WIDTH);

        if (Object.hasOwn(pinned, name)) {
            out[name] = clampWidth(pinned[name], min, max);
            room -= out[name];
        } else {
            const base = Number.isFinite(column.base) && column.base > 0 ? column.base : min;
            soft.push({ name, min, base: Math.min(max, Math.max(min, base)) });
            out[name] = 0;
        }
    }

    const total = soft.reduce((sum, c) => sum + c.base, 0);
    if (total <= room) {
        for (const c of soft) {
            out[c.name] = Math.round(c.base);
        }

        return out;
    }

    // Proportional shrink; a column that would drop below its minimum stops
    // there and the others share what's left.
    while (soft.length > 0) {
        const sum = soft.reduce((s, c) => s + c.base, 0);
        const scale = Math.max(0, room) / sum;
        const floored = soft.filter((c) => c.base * scale < c.min);

        if (floored.length === 0) {
            for (const c of soft) {
                out[c.name] = Math.floor(c.base * scale);
            }

            break;
        }

        for (const c of floored) {
            out[c.name] = Math.round(c.min);
            room -= c.min;
        }

        soft = soft.filter((c) => !floored.includes(c));
    }

    return out;
}

/**
 * The table's minimum width: every fixed column at its width plus room for the
 * flexible column.
 *
 * @param {number[]} fixedWidths
 * @param {number} [flexMin]
 * @returns {number}
 */
export function minTableWidth(fixedWidths, flexMin = FLEX_MIN_WIDTH) {
    const sum = (fixedWidths ?? []).reduce((total, w) => total + (Number.isFinite(w) && w > 0 ? w : 0), 0);

    return Math.ceil(sum + (Number.isFinite(flexMin) && flexMin > 0 ? flexMin : 0));
}

/** Read a table's saved widths; a throwing or missing storage reads as none. */
export function readWidths(storage, key) {
    try {
        return parseWidths(storage?.getItem(storageKeyFor(key)) ?? '');
    } catch {
        return {};
    }
}

/**
 * Save a table's widths (an empty set removes the entry). Returns whether the
 * write went through; a throwing storage (private mode, quota, blocked site
 * data) just returns false.
 */
export function writeWidths(storage, key, widths) {
    try {
        if (!storage) {
            return false;
        }

        const clean = sanitizeWidths(widths);

        if (Object.keys(clean).length === 0) {
            storage.removeItem(storageKeyFor(key));
        } else {
            storage.setItem(storageKeyFor(key), JSON.stringify(clean));
        }

        return true;
    } catch {
        return false;
    }
}

function browserStorage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function numberAttr(value, fallback) {
    const n = Number.parseFloat(value ?? '');

    return Number.isFinite(n) && n > 0 ? n : fallback;
}

/**
 * The Alpine directive: x-resizable-columns="'<storage-key>'" on a <table>.
 * Registered in resources/js/app.js.
 */
export function resizableColumnsDirective(el, { expression }, { evaluate, cleanup }) {
    const table = el;
    const key = String((expression ? evaluate(expression) : '') ?? '');
    const storage = browserStorage();
    const media = typeof window.matchMedia === 'function' ? window.matchMedia(DESKTOP_QUERY) : null;

    let widths = readWidths(storage, key);
    let active = false;
    let drag = null;
    let containerWidth = -1;

    const headers = () => Array.from(table.tHead?.rows?.[0]?.cells ?? []);
    const isResizable = (th) => isColumnName(th.dataset.col);
    const isFlex = (th) => th.hasAttribute('data-col-flex');
    const bounds = (th) => {
        const min = numberAttr(th.dataset.colMin, DEFAULT_MIN_WIDTH);

        return { min, max: Math.max(min, numberAttr(th.dataset.colMax, DEFAULT_MAX_WIDTH)) };
    };

    function setWidth(th, px) {
        const value = `${px}px`;
        if (th.style.width !== value) {
            th.style.width = value;
        }
    }

    function setStyle(prop, value) {
        if (table.style.getPropertyValue(prop) !== value) {
            table.style.setProperty(prop, value);
        }
    }

    // A column's default is whatever its width class gives it under the fixed
    // layout; measured once per header element (morph-added headers are new
    // elements, so they get measured when they arrive).
    function measureDefaults(columns) {
        const fresh = columns.filter((th) => !(th[DEFAULT_WIDTH] > 0));
        if (fresh.length === 0) {
            return;
        }

        for (const th of fresh) {
            th.style.removeProperty('width');
        }

        for (const th of fresh) {
            th[DEFAULT_WIDTH] = th.getBoundingClientRect().width;
        }
    }

    function apply() {
        if (!active) {
            return;
        }

        setStyle('table-layout', 'fixed');

        const all = headers();
        const columns = all.filter(isResizable);
        measureDefaults(columns);

        const pinned = drag?.moved ? withWidth(widths, drag.th.dataset.col, drag.px) : widths;

        // Without a flexible column the browser spreads any slack over every
        // column, so there's nothing to fit against: just honour saved widths.
        if (!all.some(isFlex)) {
            for (const th of columns) {
                const name = th.dataset.col;
                if (Object.hasOwn(pinned, name)) {
                    const { min, max } = bounds(th);
                    setWidth(th, clampWidth(pinned[name], min, max));
                } else {
                    th.style.removeProperty('width');
                }
            }
            table.style.removeProperty('min-width');

            return;
        }

        const fixed = all
            .filter((th) => !isFlex(th) && !isResizable(th))
            .reduce((sum, th) => sum + th.getBoundingClientRect().width, 0);
        containerWidth = table.parentElement?.clientWidth ?? 0;

        const px = fitColumns(
            pinned,
            columns.map((th) => ({ name: th.dataset.col, base: th[DEFAULT_WIDTH], ...bounds(th) })),
            containerWidth - fixed - FLEX_MIN_WIDTH,
        );

        for (const th of columns) {
            setWidth(th, px[th.dataset.col]);
        }

        setStyle('min-width', `${minTableWidth([...Object.values(px), fixed], FLEX_FLOOR_WIDTH)}px`);
    }

    function clear() {
        table.style.removeProperty('table-layout');
        table.style.removeProperty('min-width');

        for (const th of headers()) {
            th.style.removeProperty('width');
        }
    }

    function persist(name, px) {
        widths = withWidth(widths, name, px);
        writeWidths(storage, key, widths);
    }

    function handleFor(event) {
        const handle = event.target?.closest?.('[data-col-resize-handle]');
        if (!handle || !table.contains(handle)) {
            return null;
        }

        const th = handle.closest('th');

        return th && isResizable(th) && th.closest('table') === table ? { handle, th } : null;
    }

    function endDrag() {
        if (!drag) {
            return;
        }

        const { handle, th, pointerId, moved, px, onMove, onEnd, restore } = drag;
        drag = null;

        handle.removeEventListener('pointermove', onMove);
        handle.removeEventListener('pointerup', onEnd);
        handle.removeEventListener('pointercancel', onEnd);
        handle.removeEventListener('lostpointercapture', onEnd);
        handle.removeAttribute('data-resizing');
        restore();

        try {
            if (handle.hasPointerCapture?.(pointerId)) {
                handle.releasePointerCapture(pointerId);
            }
        } catch {
            /* the pointer is already gone */
        }

        if (moved) {
            persist(th.dataset.col, px);
        }

        apply();
    }

    function onPointerDown(event) {
        if (!active || event.button !== 0 || drag) {
            return;
        }

        const hit = handleFor(event);
        if (!hit) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const { handle, th } = hit;
        const { min, max } = bounds(th);
        const edge = handle.dataset.colResizeHandle === 'start' ? 'start' : 'end';
        const startX = event.clientX;
        const startWidth = th.getBoundingClientRect().width;

        const body = document.body.style;
        const previous = { cursor: body.cursor, userSelect: body.userSelect };
        body.cursor = 'col-resize';
        body.userSelect = 'none';

        const onMove = (e) => {
            if (!drag) {
                return;
            }

            const px = resizedWidth(startWidth, e.clientX - startX, edge, min, max);
            if (px !== drag.px) {
                drag.moved = true;
                drag.px = px;
                apply();
            }
        };
        const onEnd = () => endDrag();

        drag = {
            handle,
            th,
            pointerId: event.pointerId,
            moved: false,
            px: Math.round(startWidth),
            onMove,
            onEnd,
            restore: () => {
                body.cursor = previous.cursor;
                body.userSelect = previous.userSelect;
            },
        };

        handle.setAttribute('data-resizing', '');
        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onEnd);
        handle.addEventListener('pointercancel', onEnd);
        handle.addEventListener('lostpointercapture', onEnd);

        try {
            handle.setPointerCapture(event.pointerId);
        } catch {
            /* synthetic or already-released pointer: move/up still reach the handle while it's under the pointer */
        }
    }

    function onDoubleClick(event) {
        if (!active) {
            return;
        }

        const hit = handleFor(event);
        if (!hit) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        persist(hit.th.dataset.col, null);
        apply();
    }

    function sync() {
        if (!media || media.matches) {
            active = true;
            apply();
        } else {
            active = false;
            endDrag();
            clear();
        }
    }

    table.addEventListener('pointerdown', onPointerDown);
    table.addEventListener('dblclick', onDoubleClick);

    if (media?.addEventListener) {
        media.addEventListener('change', sync);
    } else if (media?.addListener) {
        media.addListener(sync);
    }

    // Refit when the room changes (window resize, sidebar toggle). Only the
    // width matters; a height change (rows wrapping) is ignored.
    const resizeObserver = typeof ResizeObserver === 'function' && table.parentElement
        ? new ResizeObserver(() => {
            if (active && table.parentElement && table.parentElement.clientWidth !== containerWidth) {
                apply();
            }
        })
        : null;
    resizeObserver?.observe(table.parentElement);

    // A morph can add or drop a column (toggling an invoice column); refit
    // once it settles. wire:ignore.self keeps the existing inline widths.
    const stopMorphed = window.Livewire?.hook?.('morphed', ({ el: root }) => {
        if (active && root?.contains?.(table)) {
            apply();
        }
    });

    sync();

    cleanup(() => {
        endDrag();
        table.removeEventListener('pointerdown', onPointerDown);
        table.removeEventListener('dblclick', onDoubleClick);
        resizeObserver?.disconnect();

        if (media?.removeEventListener) {
            media.removeEventListener('change', sync);
        } else if (media?.removeListener) {
            media.removeListener(sync);
        }

        if (typeof stopMorphed === 'function') {
            stopMorphed();
        }
    });
}
