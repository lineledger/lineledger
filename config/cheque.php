<?php

/*
 * Layout knobs for the voucher cheque PDF. All coordinates are points
 * (1 pt = 1/72"), top-left origin (the renderer flips for TCPDF).
 *
 * The renderer draws the full cheque (data + static labels) — print onto
 * blank paper or use it as-is for archive copies. For pre-printed Intuit
 * stock, set `draw_static_labels` => false so only data lands in the holes.
 */

return [
    /* Global drift, applied to every field. Tune per printer/tray. */
    'offset_x' => 0.0,
    'offset_y' => 0.0,

    /*
     * Draw the static labels (DATE, MEMO, PAY TO THE ORDER OF, etc.) and
     * decorations (signature line, dollar box). Set to false when printing
     * onto pre-printed Intuit/QuickBooks stock that already has them.
     */
    'draw_static_labels' => true,

    /* Date comb digit pitch. */
    'date_digit_pitch' => 14.0,

    /* All amount columns are right-aligned to this x. */
    'amount_right_edge' => 568.0,

    /* Voucher 2 = voucher 1 + this offset. */
    'voucher_band_pitch' => 252.0,

    /* Star-fill width for the amount-in-words line ("****One Hundred..."). */
    'amount_words_pad_width' => 60,

    /* Detail rows on the vouchers step down by this much per row. */
    'voucher_line_height' => 14.0,

    /* Soft cap so detail rows don't overrun the summary band. */
    'voucher_max_lines' => 10,

    'fonts' => [
        'family' => 'helvetica',
        'size_body' => 10,
        'size_date_comb' => 12,
        'size_label' => 7,        // DATE, MEMO, PAY TO THE ORDER OF, etc.
        'size_subscript' => 7,    // M M D D Y Y Y Y under the date digits
    ],

    /* Vertical distance from the date digits down to the M D Y subscript row. */
    'date_subscript_drop' => 18.0,

    /* Horizontal distance from the first date digit back to the "DATE" label. */
    'date_label_offset' => -36.0,

    /*
     * Field coordinates. Each entry is [x, top]. The renderer applies
     * offset_x / offset_y globally.
     */
    'fields' => [
        // CHEQUE BAND ----------------------------------------------------
        'cheque_date_first_digit' => [430.0, 74.4],
        'cheque_amount_words' => [72.0, 109.4],
        'cheque_amount_numeric' => [495.0, 109.4],
        'cheque_payee' => [72.0, 151.4],
        'cheque_memo_label' => [40.0, 202.4],
        'cheque_memo' => [72.0, 202.4],

        // VOUCHER 1 (voucher 2 = same x, top + voucher_band_pitch) -------
        'voucher_payee' => [60.0, 275.4],
        'voucher_date' => [432.0, 275.4],
        'voucher_detail_first_row' => [44.0, 293.4],
        'voucher_detail_desc_x' => 234.0,
        'voucher_summary_account' => [36.0, 485.4],
        'voucher_summary_desc' => [144.0, 485.4],
        // Amount columns (right-aligned) all use `amount_right_edge` above.
    ],
];
