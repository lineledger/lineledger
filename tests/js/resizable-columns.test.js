import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    DEFAULT_MAX_WIDTH,
    DEFAULT_MIN_WIDTH,
    FLEX_MIN_WIDTH,
    clampWidth,
    fitColumns,
    isColumnName,
    minTableWidth,
    parseWidths,
    readWidths,
    resizedWidth,
    sanitizeWidths,
    serializeWidths,
    storageKeyFor,
    withWidth,
    writeWidths,
} from '../../resources/js/resizable-columns.js';

/** A Storage-shaped Map. */
const memoryStorage = (initial = {}) => {
    const data = new Map(Object.entries(initial));

    return {
        data,
        getItem: (k) => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => data.set(k, String(v)),
        removeItem: (k) => data.delete(k),
    };
};

/** Storage that throws on every access (Safari private mode, blocked site data). */
const throwingStorage = {
    getItem() {
        throw new Error('SecurityError');
    },
    setItem() {
        throw new Error('QuotaExceededError');
    },
    removeItem() {
        throw new Error('SecurityError');
    },
};

test('each table gets its own namespaced storage key', () => {
    assert.equal(storageKeyFor('cheque-lines'), 'll:col-widths:cheque-lines');
    assert.equal(storageKeyFor('bill-lines'), 'll:col-widths:bill-lines');
});

test('column names are data-col slugs, never prototype keys', () => {
    assert.ok(isColumnName('account'));
    assert.ok(isColumnName('unit-price'));
    assert.ok(isColumnName('service_date'));
    assert.ok(!isColumnName(''));
    assert.ok(!isColumnName('has space'));
    assert.ok(!isColumnName('__proto__'));
    assert.ok(!isColumnName('constructor'));
    assert.ok(!isColumnName(42));
    assert.ok(!isColumnName('x'.repeat(65)));
});

test('a width is held between the column minimum and maximum, in whole px', () => {
    assert.equal(clampWidth(200, 64, 800), 200);
    assert.equal(clampWidth(10, 64, 800), 64);
    assert.equal(clampWidth(5000, 64, 800), 800);
    assert.equal(clampWidth(150.6, 64, 800), 151);
    assert.equal(clampWidth('220px', 64, 800), 220);
});

test('a nonsense width falls back to the minimum', () => {
    assert.equal(clampWidth(NaN, 88), 88);
    assert.equal(clampWidth(undefined, 88), 88);
    assert.equal(clampWidth('wide', 88), 88);
    assert.equal(clampWidth(Infinity, 88), 88);
});

test('broken bounds fall back to the defaults', () => {
    assert.equal(clampWidth(10, -5), DEFAULT_MIN_WIDTH);
    assert.equal(clampWidth(99999, 64, NaN), DEFAULT_MAX_WIDTH);
    // a maximum below the minimum is ignored in favour of the default maximum
    assert.equal(clampWidth(500, 300, 100), 500);
    assert.equal(clampWidth(5000, 300, 100), DEFAULT_MAX_WIDTH);
});

test('a right-edge handle grows the column as the pointer moves right', () => {
    assert.equal(resizedWidth(288, 120, 'end'), 408);
    assert.equal(resizedWidth(288, -100, 'end'), 188);
    assert.equal(resizedWidth(288, 50), 338);
});

test('a left-edge handle grows the column as the pointer moves left', () => {
    assert.equal(resizedWidth(112, -40, 'start'), 152);
    assert.equal(resizedWidth(112, 20, 'start'), 92);
});

test('a drag never takes a column past its bounds', () => {
    assert.equal(resizedWidth(128, 500, 'start', 96), 96);
    assert.equal(resizedWidth(288, -1000, 'end', 120), 120);
    assert.equal(resizedWidth(288, 5000, 'end', 120, 900), 900);
    assert.equal(resizedWidth(288, NaN, 'end'), 288);
});

test('stored widths are parsed back into whole px per column', () => {
    assert.deepEqual(parseWidths('{"account":408,"amount":151.6}'), { account: 408, amount: 152 });
});

test('unreadable or malformed payloads read as no saved widths', () => {
    assert.deepEqual(parseWidths(''), {});
    assert.deepEqual(parseWidths(null), {});
    assert.deepEqual(parseWidths('{not json'), {});
    assert.deepEqual(parseWidths('[1,2,3]'), {});
    assert.deepEqual(parseWidths('null'), {});
    assert.deepEqual(parseWidths('"account"'), {});
});

test('bad entries are dropped and good ones kept', () => {
    const parsed = parseWidths(JSON.stringify({
        account: 300,
        amount: '120',
        tax: -5,
        total: 0,
        class: null,
        'bad name': 100,
        location: 140,
    }));

    assert.deepEqual(parsed, { account: 300, location: 140 });
});

test('a __proto__ key in storage cannot pollute anything', () => {
    const parsed = parseWidths('{"__proto__":{"polluted":1},"account":250}');

    assert.deepEqual(parsed, { account: 250 });
    assert.equal(Object.getPrototypeOf(parsed), Object.prototype);
    assert.equal({}.polluted, undefined);
});

test('serialize writes only clean entries and round-trips', () => {
    const raw = serializeWidths({ account: 408.4, junk: 'x', amount: 96 });

    assert.equal(raw, '{"account":408,"amount":96}');
    assert.deepEqual(parseWidths(raw), { account: 408, amount: 96 });
    assert.equal(serializeWidths(null), '{}');
});

test('withWidth sets or resets one column without touching the input', () => {
    const saved = { account: 300 };

    assert.deepEqual(withWidth(saved, 'amount', 120), { account: 300, amount: 120 });
    assert.deepEqual(withWidth(saved, 'account', 350), { account: 350 });
    assert.deepEqual(withWidth(saved, 'account', null), {});
    assert.deepEqual(withWidth(saved, '__proto__', 10), { account: 300 });
    assert.deepEqual(saved, { account: 300 });
});

const chequeColumns = [
    { name: 'account', base: 288, min: 120 },
    { name: 'amount', base: 112, min: 88 },
    { name: 'tax', base: 128, min: 128 },
    { name: 'total', base: 112, min: 88 },
];

test('with room to spare every column gets its default', () => {
    assert.deepEqual(fitColumns({}, chequeColumns, 1000), { account: 288, amount: 112, tax: 128, total: 112 });
    assert.deepEqual(fitColumns({}, chequeColumns, Infinity), { account: 288, amount: 112, tax: 128, total: 112 });
});

test('a saved width is honoured exactly, the rest keep their defaults', () => {
    assert.deepEqual(
        fitColumns({ account: 408 }, chequeColumns, 1000),
        { account: 408, amount: 112, tax: 128, total: 112 },
    );
});

test('a saved width is clamped to the column bounds', () => {
    assert.equal(fitColumns({ account: 20 }, chequeColumns, 1000).account, 120);
    assert.equal(fitColumns({ account: 5000 }, [{ name: 'account', base: 288, min: 120, max: 900 }], 1000).account, 900);
});

test('without enough room the defaults shrink in proportion', () => {
    const px = fitColumns({}, [
        { name: 'item', base: 200, min: 50 },
        { name: 'account', base: 200, min: 50 },
    ], 300);

    assert.deepEqual(px, { item: 150, account: 150 });
});

test('a shrinking column stops at its minimum and the others share the rest', () => {
    const px = fitColumns({}, chequeColumns, 520);

    // tax can't shrink (min = default 128); amount and total stop at 88.
    assert.equal(px.tax, 128);
    assert.equal(px.amount, 88);
    assert.equal(px.total, 88);
    assert.equal(px.account, 520 - 128 - 88 - 88);
});

test('a pinned column takes its room first; the unpinned ones absorb the squeeze', () => {
    const px = fitColumns({ account: 400 }, chequeColumns, 750);

    assert.equal(px.account, 400);
    assert.equal(px.tax, 128);
    assert.ok(px.amount >= 88 && px.amount < 112);
    assert.ok(px.total >= 88 && px.total < 112);
    assert.ok(px.amount + px.tax + px.total <= 350);
});

test('when even the minimums do not fit, every column sits at its minimum', () => {
    assert.deepEqual(fitColumns({}, chequeColumns, 100), { account: 120, amount: 88, tax: 128, total: 88 });
    assert.deepEqual(fitColumns({}, chequeColumns, -50), { account: 120, amount: 88, tax: 128, total: 88 });
});

test('the fitted widths never add up to more than the room (unless at minimums)', () => {
    for (const available of [520, 555, 600, 640, 777]) {
        const px = fitColumns({}, chequeColumns, available);
        const sum = Object.values(px).reduce((a, b) => a + b, 0);
        assert.ok(sum <= available, `${sum} > ${available}`);
    }
});

test('saved widths for columns not on the table right now are ignored', () => {
    const px = fitColumns({ item: 300, account: 250 }, chequeColumns, 1000);

    assert.equal(px.item, undefined);
    assert.equal(px.account, 250);
});

test('columns without a usable default get their minimum; bad names and duplicates are skipped', () => {
    const px = fitColumns({}, [
        { name: 'qty', min: 56 },
        { name: 'qty', base: 300 },
        { name: 'bad name', base: 100 },
        { name: 'amount', base: 0, min: 88 },
    ], 1000);

    assert.deepEqual(px, { qty: 56, amount: 88 });
});

test('the table minimum is every fixed column plus room for the flexible one', () => {
    assert.equal(minTableWidth([288, 112, 128, 112, 60]), 700 + FLEX_MIN_WIDTH);
    assert.equal(minTableWidth([288, 112.4], 96), 497);
    assert.equal(minTableWidth([NaN, -10, 100], 0), 100);
    assert.equal(minTableWidth([], 96), 96);
});

test('widths read back from storage', () => {
    const storage = memoryStorage({ 'll:col-widths:cheque-lines': '{"account":408}' });

    assert.deepEqual(readWidths(storage, 'cheque-lines'), { account: 408 });
    assert.deepEqual(readWidths(storage, 'bill-lines'), {});
});

test('widths are written under the table key, and an empty set clears it', () => {
    const storage = memoryStorage();

    assert.equal(writeWidths(storage, 'cheque-lines', { account: 408, bogus: 'x' }), true);
    assert.equal(storage.getItem('ll:col-widths:cheque-lines'), '{"account":408}');

    assert.equal(writeWidths(storage, 'cheque-lines', {}), true);
    assert.equal(storage.getItem('ll:col-widths:cheque-lines'), null);
});

test('a throwing or missing storage never breaks the page', () => {
    assert.deepEqual(readWidths(throwingStorage, 'cheque-lines'), {});
    assert.equal(writeWidths(throwingStorage, 'cheque-lines', { account: 300 }), false);
    assert.equal(writeWidths(throwingStorage, 'cheque-lines', {}), false);

    assert.deepEqual(readWidths(null, 'cheque-lines'), {});
    assert.deepEqual(readWidths(undefined, 'cheque-lines'), {});
    assert.equal(writeWidths(null, 'cheque-lines', { account: 300 }), false);
});
