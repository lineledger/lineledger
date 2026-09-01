import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    evaluateAmountExpression,
    formatAmount,
    isAmountExpression,
    roundHalfUp,
} from '../../resources/js/amount-expression.js';

const plain = (input, decimals = 2) => evaluateAmountExpression(input, decimals)?.plain;
const ops = (input) => evaluateAmountExpression(input).steps.map((s) => s.op);
const values = (input) => evaluateAmountExpression(input).steps.map((s) => s.value);

// --- precedence & associativity ------------------------------------------------

for (const [input, expected] of [
    ['2+3*4', '14.00'],
    ['2*3+4', '10.00'],
    ['10-2-3', '5.00'],
    ['8/2/2', '2.00'],
    ['10/4', '2.50'],
    ['100*1.13', '113.00'],
]) {
    test(`precedence: ${input} = ${expected}`, () => {
        assert.equal(plain(input), expected);
    });
}

// --- float safety --------------------------------------------------------------

for (const [input, expected] of [
    ['0.1+0.2', '0.30'],
    ['10/3*3', '10.00'],
    ['1050+52.50', '1102.50'],
    ['+1102.50-25', '1077.50'],
]) {
    test(`float safety: ${input} = ${expected}`, () => {
        assert.equal(plain(input), expected);
    });
}

test('display groups thousands', () => {
    assert.equal(evaluateAmountExpression('1050+52.50').display, '1,102.50');
    assert.equal(evaluateAmountExpression('1,000*2').display, '2,000.00');
});

// --- unary signs ---------------------------------------------------------------

test('unary: 2*-3', () => {
    const r = evaluateAmountExpression('2*-3');
    assert.equal(r.plain, '-6.00');
    assert.deepEqual(r.steps, [
        { op: '', value: '2.00' },
        { op: '×', value: '-3.00' },
    ]);
});

test('unary: -25*2', () => {
    const r = evaluateAmountExpression('-25*2');
    assert.equal(r.plain, '-50.00');
    assert.deepEqual(r.steps, [
        { op: '−', value: '25.00' },
        { op: '×', value: '2.00' },
    ]);
});

test('unary: 5*+3 and +5', () => {
    assert.equal(plain('5*+3'), '15.00');
    assert.equal(plain('+5'), '5.00');
});

// --- isAmountExpression --------------------------------------------------------

for (const input of ['-25', '12.50', '1,000', '.5', '', 'abc', '*5']) {
    test(`not an expression: ${JSON.stringify(input)}`, () => {
        assert.equal(isAmountExpression(input), false);
    });
}

for (const input of ['+5', '100*', '3x4', '5-2', '-25*2', '1 000 * 2']) {
    test(`is an expression: ${JSON.stringify(input)}`, () => {
        assert.equal(isAmountExpression(input), true);
    });
}

test('isAmountExpression tolerates null/undefined', () => {
    assert.equal(isAmountExpression(null), false);
    assert.equal(isAmountExpression(undefined), false);
});

// --- dangling operator while typing -------------------------------------------

for (const [input, expected] of [
    ['100*', '100.00'],
    ['100*-', '100.00'],
    ['100*.', '100.00'],
    ['100*2.', '200.00'],
    ['5-', '5.00'],
]) {
    test(`dangling: ${JSON.stringify(input)} = ${expected}`, () => {
        assert.equal(plain(input), expected);
    });
}

// --- aliases & separators ------------------------------------------------------

for (const [input, expected] of [
    ['3x4', '12.00'],
    ['3X4', '12.00'],
    ['3×4', '12.00'],
    ['12÷4', '3.00'],
    ['5−2', '3.00'],
    ['1,000*2', '2000.00'],
    ['1 000 * 2', '2000.00'],
]) {
    test(`alias: ${input} = ${expected}`, () => {
        assert.equal(plain(input), expected);
    });
}

// --- errors --------------------------------------------------------------------

for (const input of ['10/0', '0/0']) {
    test(`division by zero: ${input}`, () => {
        const r = evaluateAmountExpression(input);
        assert.equal(r.ok, false);
        assert.equal(r.plain, null);
        assert.equal(r.display, 'Error');
        assert.equal(r.steps.length, 2);
    });
}

for (const input of ['12+abc', '1.2.3', '5(3)', '+', '.', '']) {
    test(`unparseable returns null: ${JSON.stringify(input)}`, () => {
        assert.equal(evaluateAmountExpression(input), null);
    });
}

// --- rounding ------------------------------------------------------------------

for (const [input, expected] of [
    ['1.005*1', '1.01'],
    ['2.675*1', '2.68'],
    ['-1.005*1', '-1.01'],
    ['7/3', '2.33'],
    ['2/3', '0.67'],
    ['1.0625*2', '2.13'],
    ['0.004*-1', '0.00'],
]) {
    test(`rounding: ${input} = ${expected}`, () => {
        assert.equal(plain(input), expected);
    });
}

test('roundHalfUp', () => {
    assert.equal(roundHalfUp(2.5, 0), 3);
    assert.equal(roundHalfUp(-2.5, 0), -3);
    assert.equal(roundHalfUp(2.5), 2.5);
    assert.equal(roundHalfUp(1.005, 2), 1.01);
    assert.equal(roundHalfUp(1.23456, 4), 1.2346);
    assert.equal(roundHalfUp(Infinity), Infinity);
    assert.ok(Object.is(roundHalfUp(-0.001, 2), 0));
});

test('formatAmount', () => {
    assert.equal(formatAmount(3), '3.00');
    assert.equal(formatAmount(1.0625), '1.0625');
    assert.equal(formatAmount(1234.5), '1,234.50');
    assert.equal(formatAmount(Infinity), 'Error');
    assert.equal(formatAmount(-0), '0.00');
});

// --- decimals option -----------------------------------------------------------

test('decimals = 4', () => {
    assert.equal(plain('10/3', 4), '3.3333');
    assert.equal(plain('1.0625*2', 4), '2.1250');
    assert.deepEqual(
        evaluateAmountExpression('3', 4).steps,
        [{ op: '', value: '3.0000' }],
    );
});

// --- tape shape ----------------------------------------------------------------

test('operand rows keep typed precision without padding past 2dp', () => {
    assert.deepEqual(values('100*1.13'), ['100.00', '1.13']);
});

test('operator glyphs per row', () => {
    assert.deepEqual(ops('2+3*4'), ['', '+', '×']);
    assert.deepEqual(ops('10-2/5'), ['', '−', '÷']);
});
