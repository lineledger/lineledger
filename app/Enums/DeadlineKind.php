<?php

namespace App\Enums;

/**
 * What has to happen by the due date: a return filed, money paid, both, or
 * some other task.
 */
enum DeadlineKind: string
{
    case File = 'file';
    case Pay = 'pay';
    case FileAndPay = 'file_and_pay';
    case Task = 'task';

    public function label(): string
    {
        return match ($this) {
            self::File => __('File a return'),
            self::Pay => __('Make a payment'),
            self::FileAndPay => __('File and pay'),
            self::Task => __('Other task'),
        };
    }

    /**
     * Whether an amount is normally recorded when this is done.
     */
    public function involvesPayment(): bool
    {
        return $this === self::Pay || $this === self::FileAndPay;
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
