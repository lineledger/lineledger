<?php

namespace App\Services\Tax;

/**
 * The nine boxes of a UK VAT return, as HMRC's form (and its Making Tax Digital
 * API) lays them out. All values are integer pence.
 *
 *   Box 1  VAT due on sales and other outputs
 *   Box 2  VAT due on acquisitions of goods from the EU (Northern Ireland only)
 *   Box 3  Total VAT due (Box 1 + Box 2)
 *   Box 4  VAT reclaimed on purchases and other inputs
 *   Box 5  Net VAT to pay to HMRC or reclaim (Box 3 − Box 4)
 *   Box 6  Total value of sales and all other outputs, excluding VAT
 *   Box 7  Total value of purchases and all other inputs, excluding VAT
 *   Box 8  Total value of goods supplied to the EU, excluding VAT (NI only)
 *   Box 9  Total value of goods acquired from the EU, excluding VAT (NI only)
 *
 * Boxes 1–5 carry pence. Boxes 6–9 are whole pounds — HMRC drops the pence
 * rather than rounding — so they are always a multiple of 100 here.
 *
 * Box 5 is kept signed (positive: pay HMRC; negative: HMRC repays you); the
 * form itself shows its absolute value, which {@see box5Absolute()} gives.
 */
final readonly class VatReturnBoxes
{
    public function __construct(
        public int $box1,
        public int $box2,
        public int $box3,
        public int $box4,
        public int $box5,
        public int $box6,
        public int $box7,
        public int $box8,
        public int $box9,
    ) {}

    /**
     * Assemble the boxes from the VAT figures and the net (ex-VAT) values.
     * Boxes 3 and 5 are always derived, so they can never disagree with the
     * boxes they total.
     */
    public static function make(
        int $vatOnSales,
        int $vatReclaimed,
        int $netSales,
        int $netPurchases,
        int $vatOnEuAcquisitions = 0,
        int $euSupplies = 0,
        int $euAcquisitions = 0,
    ): self {
        $box3 = $vatOnSales + $vatOnEuAcquisitions;

        return new self(
            box1: $vatOnSales,
            box2: $vatOnEuAcquisitions,
            box3: $box3,
            box4: $vatReclaimed,
            box5: $box3 - $vatReclaimed,
            box6: self::wholePounds($netSales),
            box7: self::wholePounds($netPurchases),
            box8: self::wholePounds($euSupplies),
            box9: self::wholePounds($euAcquisitions),
        );
    }

    /**
     * Drop the pence (towards zero), as HMRC asks for Boxes 6–9.
     */
    public static function wholePounds(int $pence): int
    {
        return intdiv($pence, 100) * 100;
    }

    public function isRepayment(): bool
    {
        return $this->box5 < 0;
    }

    public function box5Absolute(): int
    {
        return abs($this->box5);
    }

    /**
     * @return array<int, array{box: int, label: string, cents: int, whole_pounds: bool}>
     */
    public function rows(): array
    {
        return [
            ['box' => 1, 'label' => __('VAT due on sales and other outputs'), 'cents' => $this->box1, 'whole_pounds' => false],
            ['box' => 2, 'label' => __('VAT due on acquisitions of goods from the EU (Northern Ireland)'), 'cents' => $this->box2, 'whole_pounds' => false],
            ['box' => 3, 'label' => __('Total VAT due (Box 1 + Box 2)'), 'cents' => $this->box3, 'whole_pounds' => false],
            ['box' => 4, 'label' => __('VAT reclaimed on purchases and other inputs'), 'cents' => $this->box4, 'whole_pounds' => false],
            ['box' => 5, 'label' => $this->isRepayment() ? __('Net VAT to be reclaimed from HMRC (Box 4 − Box 3)') : __('Net VAT to pay to HMRC (Box 3 − Box 4)'), 'cents' => $this->box5Absolute(), 'whole_pounds' => false],
            ['box' => 6, 'label' => __('Total value of sales and all other outputs, excluding VAT'), 'cents' => $this->box6, 'whole_pounds' => true],
            ['box' => 7, 'label' => __('Total value of purchases and all other inputs, excluding VAT'), 'cents' => $this->box7, 'whole_pounds' => true],
            ['box' => 8, 'label' => __('Total value of goods supplied to the EU, excluding VAT (Northern Ireland)'), 'cents' => $this->box8, 'whole_pounds' => true],
            ['box' => 9, 'label' => __('Total value of goods acquired from the EU, excluding VAT (Northern Ireland)'), 'cents' => $this->box9, 'whole_pounds' => true],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'box1' => $this->box1,
            'box2' => $this->box2,
            'box3' => $this->box3,
            'box4' => $this->box4,
            'box5' => $this->box5,
            'box6' => $this->box6,
            'box7' => $this->box7,
            'box8' => $this->box8,
            'box9' => $this->box9,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $box = fn (int $n): int => (int) ($data['box'.$n] ?? 0);

        return new self($box(1), $box(2), $box(3), $box(4), $box(5), $box(6), $box(7), $box(8), $box(9));
    }
}
