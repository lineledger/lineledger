/**
 * Pure expression evaluator behind the in-cell amount calculator
 * (<x-amount-input> → Alpine `amountCalculator` in app.js). No DOM, no Alpine,
 * so it is unit-tested with Node's built-in runner: `npm test`.
 *
 * Grammar — standard precedence, left-associative, no parentheses (the tape is
 * a flat list of operands and cannot show grouping):
 *
 *   expr   := term (('+'|'-') term)*
 *   term   := factor (('*'|'/') factor)*
 *   factor := ('+'|'-')* number
 *
 * Accepted aliases: `x`/`X`/`×` for multiply, `÷` for divide, `−` (U+2212, the
 * glyph our own tapes print) for minus. Commas and whitespace are ignored.
 *
 * Evaluation runs in doubles and is rounded ONCE at the end — half away from
 * zero — to `decimals` places, so the committed value is the plain literal
 * App\Support\Money::tryFromString expects. Intermediates are never rounded
 * (10/3*3 must be 10.00, not 9.99). Division by zero yields a non-finite
 * total: the result is flagged `ok: false` and the caller must not commit it.
 */

const OP_GLYPH = { '+': '+', '-': '−', '*': '×', '/': '÷' };

/** Trailing operator (optionally followed by a lone dot) while the user is still typing. */
const DANGLING = /[+\-*\/]+\.?$/;

/** One token per match: a number or a single operator. Sticky + linear, no backtracking. */
const TOKEN = /\d+\.?\d*|\.\d+|[+\-*\/]/y;

const SYNTAX = Symbol('amount-expression:syntax');

function normalize(text) {
    return (text ?? '')
        .toString()
        .replace(/[\s,]/g, '')
        .replace(/[×xX]/g, '*')
        .replace(/÷/g, '/')
        .replace(/−/g, '-');
}

/**
 * Whether the text should be treated as an expression (tape + commit) rather
 * than a plain amount. A leading "+" is an expression by convention
 * ("+1102.50-25"); otherwise an operator must follow a digit or dot, so a lone
 * "-25" stays a negative number and "12.50" stays a number.
 */
export function isAmountExpression(text) {
    const s = normalize(text);

    if (s === '') {
        return false;
    }

    if (s[0] === '+') {
        return true;
    }

    return /[\d.][+\-*\/]/.test(s);
}

/**
 * Round half away from zero to `decimals` places. Math.round alone rounds
 * halves toward +∞ (Math.round(-2.5) === -2), so we round |value| and restore
 * the sign. The (1 + EPSILON) factor nudges a scaled value that sits one ulp
 * under an exact tie (1.005 * 100 === 100.49999999999999) back over it.
 */
export function roundHalfUp(value, decimals = 2) {
    if (!Number.isFinite(value)) {
        return value;
    }

    const factor = 10 ** decimals;
    const sign = value < 0 ? -1 : 1;
    const rounded = (sign * Math.round(Math.abs(value) * factor * (1 + Number.EPSILON))) / factor;

    return rounded === 0 ? 0 : rounded; // collapse -0
}

/** Money-style display: at least `decimals` places, at most 4, thousands grouped. */
export function formatAmount(value, decimals = 2) {
    if (!Number.isFinite(value)) {
        return 'Error';
    }

    return (value === 0 ? 0 : value).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: Math.max(decimals, 4),
    });
}

function tokenize(s) {
    const out = [];
    TOKEN.lastIndex = 0;

    while (TOKEN.lastIndex < s.length) {
        const match = TOKEN.exec(s);
        if (!match) {
            return null;
        }
        out.push(match[0]);
    }

    return out;
}

function parse(tokens, decimals) {
    let pos = 0;
    const steps = [];

    const peek = () => tokens[pos];
    const isNumber = (token) => token !== undefined && /^[\d.]/.test(token);

    // A single operand with any unary signs folded in. `binaryOp` is the
    // operator that introduced it (null for the first operand) and drives the
    // tape row: the first row shows "−" only for a negative value, later rows
    // show the binary operator glyph and the signed operand.
    const factor = (binaryOp) => {
        let sign = 1;
        while (peek() === '+' || peek() === '-') {
            if (tokens[pos] === '-') {
                sign = -sign;
            }
            pos++;
        }

        const token = peek();
        if (!isNumber(token)) {
            throw SYNTAX;
        }
        pos++;

        const value = sign * Number(token);

        if (binaryOp === null) {
            steps.push({ op: value < 0 ? '−' : '', value: formatAmount(Math.abs(value), decimals) });
        } else {
            steps.push({ op: OP_GLYPH[binaryOp], value: formatAmount(value, decimals) });
        }

        return value;
    };

    const term = (binaryOp) => {
        let value = factor(binaryOp);
        while (peek() === '*' || peek() === '/') {
            const op = tokens[pos++];
            const rhs = factor(op);
            value = op === '*' ? value * rhs : value / rhs;
        }

        return value;
    };

    const expr = () => {
        let value = term(null);
        while (peek() === '+' || peek() === '-') {
            const op = tokens[pos++];
            const rhs = term(op);
            value = op === '+' ? value + rhs : value - rhs;
        }

        return value;
    };

    const total = expr();
    if (pos !== tokens.length) {
        throw SYNTAX;
    }

    return { total, steps };
}

/**
 * Evaluate an amount expression.
 *
 * Returns null when the text is empty or not a valid expression (no tape, and
 * the caller leaves the input untouched). Otherwise:
 *   ok      — total is finite (false for ÷0); never commit when false
 *   total   — rounded number
 *   plain   — `total.toFixed(decimals)`, the string to hand to wire:model (null when !ok)
 *   display — formatted total for the tape's result row ('Error' when !ok)
 *   steps   — [{ op, value }] tape rows, always populated
 */
export function evaluateAmountExpression(text, decimals = 2) {
    const s = normalize(text).replace(DANGLING, '');
    if (s === '') {
        return null;
    }

    const tokens = tokenize(s);
    if (!tokens) {
        return null;
    }

    let parsed;
    try {
        parsed = parse(tokens, decimals);
    } catch (error) {
        if (error === SYNTAX) {
            return null;
        }
        throw error;
    }

    const total = roundHalfUp(parsed.total, decimals);
    const ok = Number.isFinite(total);

    return {
        ok,
        total,
        plain: ok ? total.toFixed(decimals) : null,
        display: ok ? formatAmount(total, decimals) : 'Error',
        steps: parsed.steps,
    };
}
