import './charts.js';

document.addEventListener('alpine:init', () => {
    /**
     * Guest country-switcher banner (books.lineledger.com vs .ca). Mirrors the
     * marketing site's GeoBanner, but the trigger is the REFERRER rather than
     * the browser timezone: it offers to switch to the sibling-country app
     * unless the visitor already arrived from a lineledger domain (so they
     * chose their country on the marketing site) or has already decided here.
     *
     * The choice is remembered in localStorage['ll_region'] (single origin per
     * country, never sent to the server) so we never nag twice. The element
     * lives in an @persist island, so init() runs once and the decision sticks
     * across wire:navigate morphs between login and register.
     */
    window.Alpine.data('geoBanner', (config = {}) => ({
        show: false,
        current: config.current,    // 'ca' | 'us' — the site they're viewing
        other: config.other,        // 'ca' | 'us' — the site we'd switch them to
        otherUrl: config.otherUrl,  // sibling app origin, e.g. https://books.lineledger.ca

        KEY: 'll_region',

        init() {
            // Logging in or registering counts as choosing this country's app —
            // record it so a later visit (after logout) doesn't prompt again.
            // Delegated + capture so it survives wire:navigate morphs and fires
            // before the form's own POST unloads the page.
            this.onSubmit = (event) => {
                const form = event.target;
                if (
                    form instanceof HTMLFormElement &&
                    form.matches('form[action*="/login"], form[action*="/register"]')
                ) {
                    this.remember(this.current);
                }
            };
            document.addEventListener('submit', this.onSubmit, true);

            // 1. Honor an explicit ?ll= choice carried across the domain switch.
            const params = new URLSearchParams(window.location.search);
            const ll = params.get('ll');
            if (ll === 'ca' || ll === 'us') {
                this.remember(ll);
                params.delete('ll');
                const qs = params.toString();
                window.history.replaceState(
                    null,
                    '',
                    window.location.pathname + (qs ? '?' + qs : '') + window.location.hash,
                );
                return;
            }

            // 2. A stored preference means they've already decided — never nag.
            if (this.stored()) {
                return;
            }

            // 3. Arriving from our own ecosystem (marketing or app) means the
            //    country was already chosen there — stay quiet.
            if (this.fromLineLedger()) {
                return;
            }

            // 4. Direct hit / external referrer (e.g. Google) on this country's
            //    app: offer to switch.
            this.show = true;
        },

        destroy() {
            document.removeEventListener('submit', this.onSubmit, true);
        },

        stored() {
            try {
                return window.localStorage.getItem(this.KEY);
            } catch (e) {
                return null;
            }
        },

        remember(region) {
            try {
                window.localStorage.setItem(this.KEY, region);
            } catch (e) {
                /* storage unavailable — ignore */
            }
        },

        /** Did the visitor arrive from a lineledger.com / .ca page (any subdomain)? */
        fromLineLedger() {
            try {
                if (!document.referrer) {
                    return false;
                }
                const host = new URL(document.referrer).hostname;

                return /(^|\.)lineledger\.(com|ca)$/i.test(host);
            } catch (e) {
                return false;
            }
        },

        /** The sibling app, on the same page, carrying the explicit ?ll choice. */
        switchHref() {
            return this.otherUrl + window.location.pathname + '?ll=' + this.other;
        },

        /** "Go to <country>": remember the switch, then leave for the sibling app. */
        go() {
            this.remember(this.other);
            window.location.assign(this.switchHref());
        },

        /** "Stay here": record this country and dismiss for good. */
        stay() {
            this.remember(this.current);
            this.show = false;
        },
    }));


    /**
     * In-cell money calculator for amount fields. Typing an expression such as
     * "1050+52.50" or "+1102.50-25" surfaces a running "tape" of each
     * addition/subtraction; pressing Enter (or blurring) collapses it to the
     * final decimal value and syncs that to the bound Livewire property. Plain
     * amounts behave like a normal input. All math is done in integer cents to
     * avoid float drift, mirroring App\Support\Money.
     */
    window.Alpine.data('amountCalculator', () => ({
        showTape: false,
        steps: [],
        result: '',

        /**
         * Listeners are bound here rather than as `x-on:*` attributes on the
         * <flux:input>: a tall stack of attributes on a Flux component tag
         * trips catastrophic backtracking in Blade's component-tag compiler,
         * leaving the tag uncompiled. Keeping the tag lean avoids that.
         */
        init() {
            const input = this.$refs.input;

            input.addEventListener('input', (event) => this.onInput(event));
            input.addEventListener('blur', () => this.commit());
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    this.onEnter(event);
                } else if (event.key === 'Escape') {
                    this.showTape = false;
                }
            });

            // The tape is position:fixed (computed from the input) so it escapes
            // the line-items table's overflow-x-auto clip. Keep it pinned to the
            // input while open as the page or table scrolls. Capture phase so
            // scrolls inside the overflow container are caught too.
            this.reposition = () => {
                if (this.showTape) {
                    this.position();
                }
            };
            window.addEventListener('scroll', this.reposition, true);
            window.addEventListener('resize', this.reposition);
        },

        destroy() {
            window.removeEventListener('scroll', this.reposition, true);
            window.removeEventListener('resize', this.reposition);
        },

        position() {
            const input = this.$refs.input;
            const tape = this.$refs.tape;
            if (!input || !tape) {
                return;
            }
            const rect = input.getBoundingClientRect();
            tape.style.top = `${rect.bottom + 4}px`;
            tape.style.left = 'auto';
            tape.style.right = `${Math.max(8, window.innerWidth - rect.right)}px`;
            tape.style.minWidth = `${rect.width}px`;
        },

        isCalc(value) {
            const s = (value ?? '').toString().trim();
            if (s === '') {
                return false;
            }
            if (s[0] === '+') {
                return true;
            }
            return /[\d.][+\-]/.test(s.replace(/[,\s]/g, ''));
        },

        evaluate(value) {
            const s = (value ?? '').toString().replace(/[,\s]/g, '');
            const terms = s.match(/[+\-]?(?:\d+\.?\d*|\.\d+)/g);
            if (!terms) {
                return null;
            }

            let totalCents = 0;
            const steps = [];
            for (const term of terms) {
                const num = parseFloat(term);
                if (isNaN(num)) {
                    continue;
                }
                const cents = Math.round(num * 100);
                const negative = cents < 0;
                totalCents += cents;
                steps.push({
                    op: steps.length === 0 ? (negative ? '−' : '') : (negative ? '−' : '+'),
                    value: Math.abs(cents / 100).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    }),
                });
            }

            if (steps.length === 0) {
                return null;
            }

            return { totalCents, steps };
        },

        format(cents) {
            return (cents / 100).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        onInput(event) {
            if (this.isCalc(event.target.value)) {
                const r = this.evaluate(event.target.value);
                if (r) {
                    this.steps = r.steps;
                    this.result = this.format(r.totalCents);
                    this.showTape = true;
                    this.$nextTick(() => this.position());
                    return;
                }
            }
            this.showTape = false;
        },

        commit() {
            const input = this.$refs.input;
            if (this.isCalc(input.value)) {
                const r = this.evaluate(input.value);
                if (r) {
                    input.value = (r.totalCents / 100).toFixed(2);
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
            this.showTape = false;
        },

        onEnter(event) {
            if (this.isCalc(event.target.value)) {
                event.preventDefault();
                this.commit();
            }
        },
    }));
});

/**
 * Tracks the last text field the user was editing anywhere in the app, so the
 * sidebar calculator can "place" its result back into that field. We capture on
 * focusin (ignoring the calculator's own controls and the search box) because
 * opening the calculator moves focus to the modal, losing document.activeElement.
 */
let lastEditableField = null;

document.addEventListener('focusin', (event) => {
    const el = event.target;
    if (!el || typeof el.closest !== 'function') {
        return;
    }

    // Don't track focus inside the calculator or global-search modals.
    if (el.closest('[data-test="calculator-body"], [data-test="global-search-input"]')) {
        return;
    }

    const tag = el.tagName;
    const editable =
        tag === 'TEXTAREA' ||
        (tag === 'INPUT' && /^(text|number|search|tel|url|email|)$/i.test(el.getAttribute('type') || ''));

    if (editable && !el.readOnly && !el.disabled) {
        lastEditableField = el;
    }
});

document.addEventListener('alpine:init', () => {
    /**
     * Standalone sidebar calculator with a printing "tape", offered in two
     * interaction models (chosen per user in Settings → Appearance):
     *
     *  - "standard": a normal calculator. Enter a number, pick an operator
     *    (+ − × ÷), enter the next number, press = for the result. Every entry
     *    and result prints to the tape.
     *  - "adding_machine": an accountant's 10-key. + and − immediately commit
     *    the typed number to a running grand total (printing a "value +" /
     *    "value −" row); × and ÷ run a sub-calculation resolved by =; the Total
     *    key prints and clears the grand total.
     *
     * Results are rounded to 2 decimals (an accounting default) to avoid float
     * drift, mirroring the cents philosophy of App\Support\Money.
     */
    window.Alpine.data('tapeCalculator', (opts = {}) => ({
        mode: opts.mode === 'adding_machine' ? 'adding_machine' : 'standard',

        display: '0',
        tape: [],

        // Number currently being typed, and whether the next digit starts fresh.
        entry: '',
        freshEntry: true,

        // Standard-mode accumulators.
        accumulator: null,
        pendingOp: null,

        // Adding-machine accumulators.
        total: 0,
        mulPending: null,
        mulLeft: 0,

        // Whether a field is available to receive a placed result (reactive
        // mirror of lastEditableField, refreshed on every focus change).
        hasTarget: false,

        init() {
            this.refreshTarget = () => {
                this.hasTarget = this.canPlace();
            };
            document.addEventListener('focusin', this.refreshTarget);
            this.refreshTarget();
        },

        // --- formatting helpers -------------------------------------------------

        round2(value) {
            return Math.round((value + Number.EPSILON) * 100) / 100;
        },

        fmt(value) {
            if (!Number.isFinite(value)) {
                return 'Error';
            }

            return value.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        entryValue() {
            const num = parseFloat(this.entry);

            return Number.isNaN(num) ? 0 : num;
        },

        apply(a, op, b) {
            switch (op) {
                case '+':
                    return a + b;
                case '−':
                    return a - b;
                case '×':
                    return a * b;
                case '÷':
                    return b === 0 ? Infinity : a / b;
                default:
                    return b;
            }
        },

        pushTape(sign, value, isTotal = false) {
            this.tape.push({ sign, value, total: isTotal });
            this.$nextTick(() => {
                const el = this.$refs.tape;
                if (el) {
                    el.scrollTop = el.scrollHeight;
                }
            });
        },

        // --- entry keys ---------------------------------------------------------

        digit(d) {
            if (this.freshEntry) {
                this.entry = d;
                this.freshEntry = false;
            } else if (this.entry === '0') {
                this.entry = d;
            } else {
                this.entry += d;
            }

            this.refreshDisplay();
        },

        dot() {
            if (this.freshEntry) {
                this.entry = '0.';
                this.freshEntry = false;
            } else if (!this.entry.includes('.')) {
                this.entry = (this.entry === '' ? '0' : this.entry) + '.';
            }

            this.refreshDisplay();
        },

        backspace() {
            if (this.freshEntry || this.entry === '') {
                return;
            }

            this.entry = this.entry.slice(0, -1);
            this.refreshDisplay();
        },

        clear() {
            this.display = '0';
            this.tape = [];
            this.entry = '';
            this.freshEntry = true;
            this.accumulator = null;
            this.pendingOp = null;
            this.total = 0;
            this.mulPending = null;
            this.mulLeft = 0;
        },

        refreshDisplay() {
            this.display = this.entry === '' ? '0' : this.entry;
        },

        // --- operators / equals dispatch ---------------------------------------

        op(symbol) {
            if (this.mode === 'adding_machine') {
                if (symbol === '+' || symbol === '−') {
                    this.amAdd(symbol);
                } else {
                    this.amMul(symbol);
                }

                return;
            }

            this.stdOp(symbol);
        },

        equals() {
            if (this.mode === 'adding_machine') {
                this.amEquals();

                return;
            }

            this.stdEquals();
        },

        // --- standard mode ------------------------------------------------------

        stdCommit() {
            const current = this.entryValue();

            if (this.accumulator === null) {
                this.accumulator = current;
                this.pushTape('', this.fmt(current));
            } else {
                this.accumulator = this.round2(this.apply(this.accumulator, this.pendingOp, current));
                this.pushTape(this.pendingOp, this.fmt(current));
            }

            this.display = this.fmt(this.accumulator);
        },

        stdOp(symbol) {
            if (!this.freshEntry) {
                this.stdCommit();
            }

            this.pendingOp = symbol;
            this.freshEntry = true;
        },

        stdEquals() {
            if (this.accumulator === null && this.freshEntry) {
                return;
            }

            if (!this.freshEntry) {
                this.stdCommit();
            }

            this.pushTape('=', this.fmt(this.accumulator), true);
            this.display = this.fmt(this.accumulator);

            // Finalize: the next number starts a fresh calculation.
            this.accumulator = null;
            this.pendingOp = null;
            this.entry = '';
            this.freshEntry = true;
        },

        // --- adding-machine mode -----------------------------------------------

        /** Resolve any pending × / ÷ into a single value, else the typed entry. */
        resolveMul() {
            if (this.mulPending !== null) {
                const value = this.round2(this.apply(this.mulLeft, this.mulPending, this.entryValue()));
                this.mulPending = null;

                return value;
            }

            return this.entryValue();
        },

        amAdd(sign) {
            const value = this.resolveMul();

            if (sign === '+') {
                this.total = this.round2(this.total + value);
            } else {
                this.total = this.round2(this.total - value);
            }

            this.pushTape(sign, this.fmt(value));
            this.display = this.fmt(this.total);
            this.entry = '';
            this.freshEntry = true;
        },

        amMul(symbol) {
            this.mulLeft = this.mulPending !== null
                ? this.round2(this.apply(this.mulLeft, this.mulPending, this.entryValue()))
                : this.entryValue();
            this.mulPending = symbol;
            this.freshEntry = true;
            this.display = this.fmt(this.mulLeft);
        },

        amEquals() {
            // A pending × / ÷ resolves to a value ready to be + or − into the
            // total; otherwise = behaves as the grand-total key.
            if (this.mulPending !== null) {
                const value = this.resolveMul();
                this.entry = String(value);
                this.freshEntry = false;
                this.display = this.fmt(value);

                return;
            }

            this.total = this.round2(this.total);
            this.pushTape('✱', this.fmt(this.total), true);
            this.display = this.fmt(this.total);
            this.total = 0;
            this.entry = '';
            this.freshEntry = true;
        },

        /** Adding-machine Total key — always prints and clears the grand total. */
        total_() {
            if (this.mulPending !== null) {
                const value = this.resolveMul();
                this.entry = String(value);
                this.freshEntry = false;
                this.display = this.fmt(value);

                return;
            }

            this.pushTape('✱', this.fmt(this.round2(this.total)), true);
            this.display = this.fmt(this.round2(this.total));
            this.total = 0;
            this.entry = '';
            this.freshEntry = true;
        },

        // --- copy / paste / keyboard -------------------------------------------

        plain() {
            return this.display.replace(/,/g, '');
        },

        copy() {
            const text = this.plain();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            }
        },

        /** Whether a field is available to receive the result. */
        canPlace() {
            return !!(lastEditableField && lastEditableField.isConnected);
        },

        /**
         * Insert the current display value into the last field the user was
         * editing, firing input/change so Livewire & Alpine bindings update,
         * then close the modal and return focus to that field.
         */
        place() {
            const field = lastEditableField;
            if (!field || !field.isConnected) {
                return;
            }

            const proto = field.tagName === 'TEXTAREA'
                ? window.HTMLTextAreaElement.prototype
                : window.HTMLInputElement.prototype;
            const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
            setter.call(field, this.plain());

            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));

            if (this.$flux && this.$flux.modal) {
                this.$flux.modal('calculator').close();
            }

            this.$nextTick(() => field.focus());
        },

        onPaste(event) {
            const clip = event.clipboardData || window.clipboardData;
            if (!clip) {
                return;
            }

            const num = parseFloat(clip.getData('text').replace(/[^0-9.\-]/g, ''));
            if (!Number.isNaN(num)) {
                this.entry = String(num);
                this.freshEntry = false;
                this.refreshDisplay();
            }

            event.preventDefault();
        },

        onKey(event) {
            const key = event.key;

            // Cmd/Ctrl shortcuts: ⌘C / Ctrl+C copies the result. Every other
            // combo (paste, select-all, devtools…) passes through untouched.
            if (event.metaKey || event.ctrlKey) {
                if (key === 'c' || key === 'C') {
                    this.copy();
                    event.preventDefault();
                } else if (key === 'Enter' || key === '=') {
                    this.place();
                    event.preventDefault();
                }

                return;
            }

            if (key >= '0' && key <= '9') {
                this.digit(key);
            } else if (key === '.') {
                this.dot();
            } else if (key === '+') {
                this.op('+');
            } else if (key === '-') {
                this.op('−');
            } else if (key === '*') {
                this.op('×');
            } else if (key === '/') {
                this.op('÷');
            } else if (key === 'Enter' || key === '=') {
                this.equals();
            } else if (key === 'Backspace') {
                this.backspace();
            } else if (key === 'Escape') {
                return; // let Flux close the modal
            } else if (key === 'c' || key === 'C') {
                this.clear();
            } else {
                return;
            }

            event.preventDefault();
        },
    }));
});
