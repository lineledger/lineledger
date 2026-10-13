import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    accountLabel,
    highlightSegments,
    normalizeSearch,
    queryTokens,
    rankAccounts,
    readAccountOptions,
} from '../../resources/js/account-combo.js';

// A slice of a chart, in the chart's own order (by code), as the server sends it.
const chart = [
    { id: 1, code: '1010', name: 'Petty Cash' },
    { id: 2, code: '1020', name: 'BMO - Operating Chequing' },
    { id: 3, code: '1030', name: 'BMO - USD' },
    { id: 4, code: '1240', name: 'Prepaid Expenses' },
    { id: 5, code: '2400', name: 'Client Disbursements' },
    { id: 6, code: '2410', name: 'GST/HST Payable' },
    { id: 7, code: '24000', name: 'Loan from Shareholder' },
    { id: 8, code: '5400', name: 'Office Supplies' },
    { id: 9, code: '5410', name: 'Rent Expense' },
    { id: 10, code: '1200', name: 'Current Assets' },
    { id: 11, code: '', name: 'Uncoded Clearing' },
    { id: 12, code: '6100', name: 'Dépenses de bureau' },
];

const codes = (query) => rankAccounts(chart, query).map((o) => o.code || o.name);

// --- labels ----------------------------------------------------------------------

test('label is "code — name", or just the name when there is no code', () => {
    assert.equal(accountLabel({ code: '2400', name: 'Client Disbursements' }), '2400 — Client Disbursements');
    assert.equal(accountLabel({ code: '', name: 'Uncoded Clearing' }), 'Uncoded Clearing');
    assert.equal(accountLabel({ code: null, name: 'Uncoded Clearing' }), 'Uncoded Clearing');
    assert.equal(accountLabel(null), '');
});

// --- normalisation ------------------------------------------------------------------

test('normalisation lower-cases and collapses punctuation, dashes and separators', () => {
    assert.equal(normalizeSearch('1030 — BMO - USD'), '1030 bmo usd');
    assert.equal(normalizeSearch('GST/HST Payable'), 'gst hst payable');
    assert.equal(normalizeSearch('  Accounts   Payable  '), 'accounts payable');
    assert.equal(normalizeSearch('Dépenses'), 'depenses');
    assert.deepEqual(queryTokens('BMO-USD'), ['bmo', 'usd']);
    assert.deepEqual(queryTokens(' — '), []);
});

// --- empty query ----------------------------------------------------------------------

test('an empty or blank query returns every account in chart order', () => {
    assert.deepEqual(rankAccounts(chart, ''), chart);
    assert.deepEqual(rankAccounts(chart, '   '), chart);
    assert.deepEqual(rankAccounts(chart, null), chart);
    assert.notEqual(rankAccounts(chart, ''), chart, 'returns a copy, not the payload itself');
});

// --- GL number ------------------------------------------------------------------------

test('a numeric query ranks code-prefix matches first', () => {
    // 2400, 2410, 24000 start with "24"; 1240 only contains it.
    assert.deepEqual(codes('24'), ['2400', '2410', '24000', '1240']);
});

test('an exact code outranks longer codes that share its prefix', () => {
    const ranked = rankAccounts(chart, '2400');
    assert.equal(ranked[0].code, '2400');
    assert.deepEqual(ranked.map((o) => o.code), ['2400', '24000']);
});

test('an exact code wins even when the chart is not sorted by code', () => {
    const shuffled = [chart[6], chart[4]]; // 24000 before 2400
    assert.equal(rankAccounts(shuffled, '2400')[0].code, '2400');
});

test('a code plus a name word narrows to that account', () => {
    assert.deepEqual(codes('5400 office'), ['5400']);
    assert.deepEqual(codes('10 bmo'), ['1020', '1030']);
});

// --- name -------------------------------------------------------------------------------

test('a name query finds the account in the same box', () => {
    assert.deepEqual(codes('client'), ['2400']);
    assert.deepEqual(codes('disb'), ['2400']);
});

test('a word-prefix match ranks above a mid-word match', () => {
    // "Rent Expense" starts a word with "rent"; "Current Assets" only contains it.
    assert.deepEqual(codes('rent'), ['5410', '1200']);
});

test('multi-word queries match every word, in any order', () => {
    assert.deepEqual(codes('bmo usd'), ['1030']);
    assert.deepEqual(codes('usd bmo'), ['1030']);
    assert.deepEqual(codes('chequing op'), ['1020']);
    assert.deepEqual(codes('bmo nothing'), []);
});

test('matching is case-insensitive and ignores punctuation, dashes and accents', () => {
    assert.deepEqual(codes('CLIENT'), ['2400']);
    assert.deepEqual(codes('Bmo-Usd'), ['1030']);
    assert.deepEqual(codes('bmo — usd'), ['1030']);
    assert.deepEqual(codes('gst hst'), ['2410']);
    assert.deepEqual(codes('gst/hst'), ['2410']);
    assert.deepEqual(codes('depenses'), ['6100']);
    assert.deepEqual(codes('DÉPENSES'), ['6100']);
});

test('an account without a code is still found by name', () => {
    assert.deepEqual(codes('clearing'), ['Uncoded Clearing']);
});

test('ranking tolerates junk in the payload', () => {
    assert.deepEqual(rankAccounts(null, 'x'), []);
    assert.deepEqual(rankAccounts([null, 7, { id: 1, code: '1000', name: 'Cash' }], 'cash').map((o) => o.id), [1]);
});

// --- highlighting -------------------------------------------------------------------------

test('highlighting marks each query word, case- and accent-insensitively', () => {
    assert.deepEqual(highlightSegments('BMO - Operating Chequing', 'chequing op'), [
        { text: 'BMO - ', match: false },
        { text: 'Op', match: true },
        { text: 'erating ', match: false },
        { text: 'Chequing', match: true },
    ]);
    assert.deepEqual(highlightSegments('Dépenses de bureau', 'depenses'), [
        { text: 'Dépenses', match: true },
        { text: ' de bureau', match: false },
    ]);
    assert.deepEqual(highlightSegments('2400', '24'), [
        { text: '24', match: true },
        { text: '00', match: false },
    ]);
});

test('highlighting marks one hit per word, preferring the start of a word', () => {
    assert.deepEqual(highlightSegments('1010', '1'), [
        { text: '1', match: true },
        { text: '010', match: false },
    ]);
    // "rent" starts a word in "Rent" but sits mid-word in "Current".
    assert.deepEqual(highlightSegments('Current Rent', 'rent'), [
        { text: 'Current ', match: false },
        { text: 'Rent', match: true },
    ]);
    // With no word-start hit, the first mid-word hit is marked.
    assert.deepEqual(highlightSegments('Current Assets', 'rent'), [
        { text: 'Cur', match: false },
        { text: 'rent', match: true },
        { text: ' Assets', match: false },
    ]);
});

test('highlighting with no query returns the text as one plain run', () => {
    assert.deepEqual(highlightSegments('Petty Cash', ''), [{ text: 'Petty Cash', match: false }]);
    assert.deepEqual(highlightSegments('', 'x'), [{ text: '', match: false }]);
});

// --- options payload ------------------------------------------------------------------------

test('the options payload is parsed once and re-read when its text changes', () => {
    const el = { textContent: JSON.stringify([{ id: 1, code: '1010', name: 'Petty Cash' }]) };

    const first = readAccountOptions(el);
    assert.equal(first.options.length, 1);
    assert.equal(first.byId.get('1').name, 'Petty Cash');
    assert.equal(readAccountOptions(el), first, 'unchanged text reuses the parsed payload');

    el.textContent = JSON.stringify([
        { id: 1, code: '1010', name: 'Petty Cash' },
        { id: 2, code: '1020', name: 'Chequing' },
    ]);
    const second = readAccountOptions(el);
    assert.notEqual(second, first);
    assert.equal(second.byId.get('2').code, '1020');
});

test('a missing or malformed payload reads as an empty list', () => {
    assert.deepEqual(readAccountOptions(null).options, []);
    assert.deepEqual(readAccountOptions({ textContent: '{nope' }).options, []);
    assert.deepEqual(readAccountOptions({ textContent: '{"id":1}' }).options, []);
});
